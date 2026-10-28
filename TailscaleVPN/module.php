<?php

declare(strict_types=1);

/**
 * Tailscale VPN for the SymBox.
 *
 * The module downloads the static Tailscale binaries (tailscale + tailscaled) to /mnt/data, runs tailscaled
 * as a background service and controls the tunnel via the tailscale CLI.
 *
 * Terminology used throughout this module:
 *  - Service: the tailscaled daemon. It has to run before anything else can be done.
 *  - Tunnel:  the actual VPN connection. It can be started/stopped via 'tailscale up' / 'tailscale down'
 *             and is reflected by the 'State' variable.
 *  - Preferences: the tailscale settings (hostname, routes, DNS, ...). They are persisted by tailscaled
 *             in /mnt/data/tailscale-state and survive restarts of the service.
 *
 * How preferences are applied:
 *  Only the preferences listed in getPreferenceFlags() are managed by this module. They are applied via
 *  'tailscale set' and the tunnel is then started with a bare 'tailscale up'. A bare 'tailscale up' does not
 *  validate preferences, so settings that were changed outside of this module (e.g. via the shell) are kept.
 *  Only the one-time authentication uses 'tailscale up' with flags, because the auth key is an 'up' flag.
 *
 * Error handling:
 *  The exit code and output of every tailscale call is written to the debug window. If starting the tunnel
 *  fails, the error is kept in the 'LastError' buffer and shown in the 'Status' variable until the next
 *  successful start or stop.
 */
class TailscaleVPN extends IPSModule
{
    // https://pkgs.tailscale.com/stable/#static
    private static $version = '1.104.4';
    private static $filename = 'tailscale_%s_arm64.tgz';
    private static $filehash = 'a1c249ce6bdebef62e1ae3b997595555bce2ae4709bf95a718e9ca7a129dad7e';
    private static $url = 'https://pkgs.tailscale.com/stable/%s';
    private static $targetProduction = '/mnt/data/';
    private static $targetDevelopment = __DIR__ . '/../';

    /**
     * Registers properties, status variables and the status timer.
     *
     * Properties:
     *  - AutoStartVPN:    Start the tunnel automatically after the kernel has started.
     *  - UseMagicDNS:     Let tailscale take over the DNS configuration (--accept-dns). Off by default,
     *                     because MagicDNS occasionally breaks DNS resolution on the SymBox.
     *  - AdvertiseRoutes: List of subnets (CIDR) behind the SymBox that are offered to the tailnet.
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean('AutoStartVPN', false);
        $this->RegisterPropertyBoolean('UseMagicDNS', false);

        $this->RegisterPropertyString('AdvertiseRoutes', '[]');

        $this->RegisterVariableBoolean('State', $this->Translate('VPN'), 'Switch', 0);
        $this->EnableAction('State');

        $this->RegisterVariableString('Status', 'Status', '', 1);

        $this->RegisterTimer('Update', 10 * 1000, 'TSVPN_UpdateStatus($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    /**
     * Nothing to clean up. The tailscale binaries and state are deliberately kept, see UIUninstall().
     */
    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    /**
     * Starts service and tunnel after the kernel is ready, if 'AutoStartVPN' is enabled.
     * This cannot be done in ApplyChanges(), because IPS_Execute() is not available before IPS_KERNELSTARTED.
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            if ($this->ReadPropertyBoolean('AutoStartVPN')) {
                $this->HandleAutostart();
            }
        }
    }

    /**
     * Applies the configuration. If tailscaled is already running, the module managed preferences
     * (routes, DNS, ...) are pushed via 'tailscale set' immediately, so a change to the configuration
     * takes effect without restarting the tunnel.
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        // When tailscaled is already running update the preferences directly.
        // They are persisted by tailscaled and will also be used for the next 'tailscale up'
        if ($this->isServiceInstalled()) {
            if ($this->isServiceRunning()) {
                $this->ApplyPreferences();
            }
        }

        $this->UpdateStatus();
    }

    /**
     * Brings the VPN up after a reboot: starts tailscaled if required and, if the node is already
     * authenticated, starts the tunnel. Nothing happens if tailscale was never downloaded.
     */
    public function HandleAutostart()
    {
        if ($this->isServiceInstalled()) {
            if (!$this->isServiceRunning()) {
                $this->StartService();
            }
            if ($this->isTunnelAuthenticated()) {
                $this->StartTunnel();
            }
        }
    }

    /**
     * Handles the 'State' switch from the visualization.
     *
     * Switching on starts the service if required and then the tunnel. If the node is not installed or not
     * authenticated yet, or if starting the tunnel fails, an error is reported and the switch stays off.
     * Switching off stops the tunnel but keeps tailscaled running.
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                if ($Value) {
                    if (!$this->isServiceInstalled()) {
                        echo $this->Translate('Tailscale is not yet installed');
                        return;
                    }
                    if (!$this->isServiceRunning()) {
                        $this->StartService();
                        $this->ReloadForm();
                    }
                    if (!$this->isTunnelAuthenticated()) {
                        echo $this->Translate('Tailscale is not yet authenticated');
                        return;
                    }
                    if (!$this->StartTunnel()) {
                        $this->ReloadForm();
                        echo $this->Translate('Starting the tunnel failed') . ': ' . $this->GetBuffer('LastError');
                        return;
                    }
                    $this->ReloadForm();
                } else {
                    $this->StopTunnel();
                    $this->ReloadForm();
                }
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    /**
     * Downloads the tailscale release defined by self::$version, verifies its SHA256 hash and extracts the
     * 'tailscale' and 'tailscaled' binaries to the target directory. A running tunnel/service is stopped before
     * and restarted afterwards, so this can also be used to update an existing installation.
     *
     * The archive is extracted via PharData, which requires about 65 MB of free space in /tmp.
     * Progress is reported via the 'DownloadIndicator' progress bar in the configuration form.
     */
    public function UIDownload()
    {
        // Check if we have enough temporary space
        if (disk_free_space('/tmp') < 65 * 1024 * 1024) {
            echo $this->Translate('Not enough temporary disk space. Please reboot SymBox and try again!');
            return;
        }

        // Download and Extract needs a little bit more of RAM
        ini_set('memory_limit', '128M');

        $this->UpdateFormField('DownloadButton', 'visible', false);
        $this->UpdateFormField('DownloadIndicator', 'visible', true);

        // Stop Tunnel
        $tunnelRunning = $this->isTunnelRunning();
        if ($tunnelRunning) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Stopping Tunnel...'));
            $this->StopTunnel();
        }

        // Stop Service
        $serviceRunning = $this->isServiceRunning();
        if ($serviceRunning) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Stopping Service...'));
            $this->StopService();
        }

        $filename = sprintf(self::$filename, self::$version);
        $download = sprintf(self::$url, $filename);
        $target = $this->getTarget();

        $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Downloading...'));
        file_put_contents($target . $filename, fopen($download, 'r'));

        if (!file_exists($target . $filename)) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Download failed!'));
            $this->UpdateFormField('DownloadButton', 'visible', true);
            return;
        }

        if (hash_file('sha256', $target . $filename) != self::$filehash) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Hash validation failed!'));
            $this->UpdateFormField('DownloadButton', 'visible', true);
            return;
        }

        $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Extracting...'));
        $phar = new PharData($target . $filename);
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            if (in_array($file->getFileName(), ['tailscale', 'tailscaled'])) {
                // Delete file first. Even if it is used, we want to work around the "Text file busy" problem
                if (file_exists($target . $file->getFileName())) {
                    unlink($target . $file->getFileName());
                }
                $contents = file_get_contents($file->getPathName());
                file_put_contents($target . $file->getFileName(), $contents);
                chmod($target . $file->getFileName(), 0777);
            }
        }

        $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Cleanup...'));
        unlink($target . $filename);

        if ($serviceRunning) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Starting Service...'));
            $this->StartService();
        }

        if ($tunnelRunning) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Starting Tunnel...'));
            $this->StartTunnel();
        }

        $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Please wait...'));

        $this->UpdateStatus();

        $this->ReloadForm();
    }

    /**
     * Form action: starts tailscaled.
     */
    public function UIStartService()
    {
        $this->StartService();
        $this->ReloadForm();
    }

    /**
     * Form action: starts the tunnel. With an auth key the node is (re-)authenticated first.
     * A failed start is reported as an error message in the console.
     *
     * @param string $authKey Tailscale auth key (https://login.tailscale.com/admin/settings/keys) or empty
     */
    public function UIStartTunnel(string $authKey)
    {
        $success = $this->StartTunnel($authKey);
        $this->ReloadForm();
        if (!$success) {
            echo $this->Translate('Starting the tunnel failed') . ': ' . $this->GetBuffer('LastError');
        }
    }

    /**
     * Form action: stops the tunnel ('tailscale down'). tailscaled keeps running.
     */
    public function UIStopTunnel()
    {
        $this->StopTunnel();
        $this->ReloadForm();
    }

    /**
     * Form action: prints the raw JSON output of 'tailscale status' for debugging purposes.
     */
    public function UIShowStatus()
    {
        echo $this->getTunnelStatus();
    }

    /**
     * Refreshes the 'State' and 'Status' variables. Called by the 'Update' timer every 10 seconds and
     * after every state change.
     *
     * While the tunnel is down, 'Status' shows the last start error (if any), otherwise the health
     * messages reported by tailscale.
     */
    public function UpdateStatus()
    {
        if (!$this->isServiceInstalled()) {
            $this->SetValue('Status', $this->Translate('Not installed!'));
            $this->SetValue('State', false);
        } elseif ($this->isTunnelRunning()) {
            $this->SetValue('Status', $this->Translate('Connected!'));
            $this->SetValue('State', true);
        } else {
            $lastError = $this->GetBuffer('LastError');
            $status = $this->getTunnelStatus();
            if ($lastError) {
                $this->SetValue('Status', $this->Translate('Starting the tunnel failed') . ': ' . $lastError);
            } elseif ($status) {
                $this->SetValue('Status', implode(PHP_EOL, json_decode($status)->Health ?? []));
            } else {
                $this->SetValue('Status', $this->Translate('Unknown'));
            }
            $this->SetValue('State', false);
        }
    }

    /**
     * Builds the configuration form dynamically depending on the installation state:
     *  - Not installed: only the download button is shown, no configuration is possible.
     *  - Installed: shows version, service and tunnel state and offers the matching action
     *    (start service, authenticate, start tunnel or stop tunnel).
     *  - Installed but version differs from self::$version: the download button becomes an update button.
     *
     * The indices used below refer to the 'actions' array in form.json.
     */
    public function GetConfigurationForm()
    {
        if (IPS_GetKernelPlatform() != 'SymBox' && IPS_GetKernelVersion() != '0.0') {
            return json_encode([
                'actions' => [
                    [
                        'type'    => 'Label',
                        'caption' => $this->Translate('Tailscale VPN is only available for SymBox!'),
                    ]
                ]]);
        }

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $version = false;
        $status = false;
        $serviceInstalled = $this->isServiceInstalled();
        $serviceRunning = $this->isServiceRunning();
        $tunnelRunning = false;
        $tunnelAuthenticated = false;

        if (!$serviceInstalled) {
            // Do not allow any configuration as long as it is not installed
            unset($form['elements']);
        }
        else {
            $result = shell_exec($this->getTarget() . 'tailscale --version');
            if ($result) {
                $lines = explode("\n", $result);
                $version = $lines[0];
            }
            else {
                $form['actions'][0]['caption'] = $this->Translate('Fix');
                $form['actions'][2]['caption'] = $this->Translate('The currently downloaded version seems broken!');
                $form['actions'][2]['visible'] = true;
                return json_encode($form);
            }
            $tunnelRunning = $this->isTunnelRunning();
            $tunnelAuthenticated = $this->isTunnelAuthenticated();
            $status = $this->getTunnelStatus();
        }

        if ($version) {
            if ($version != self::$version) {
                $form['actions'][0]['caption'] = $this->Translate('Update');
            }
            else {
                $form['actions'][0]['visible'] = false;
            }

            $form['actions'][2]['caption'] = $this->Translate('Version') . ': ' . $version;
            $form['actions'][2]['visible'] = true;

            $form['actions'][3]['caption'] = $this->Translate('Service') . ': ' . ($serviceRunning ? $this->Translate('Running!') : $this->Translate('Stopped!'));
            $form['actions'][3]['visible'] = true;
        }

        if ($status) {
            if (!$tunnelRunning) {
                $form['actions'][5]['caption'] = $this->Translate('Tunnel') . ': ' . json_decode($status)->BackendState;
            }
            else {
                $form['actions'][5]['caption'] = $this->Translate('Tunnel') . ': ' . $this->Translate('Connected!');
            }
            $form['actions'][5]['visible'] = true;
        }

        if ($version) {
            if (!$serviceRunning) {
                $form['actions'][4]['visible'] = true;
            }
            elseif (!$tunnelRunning) {
                if ($tunnelAuthenticated) {
                    $form['actions'][7]['visible'] = true;
                }
                else {
                    $form['actions'][6]['visible'] = true;
                }
            }
            else {
                $form['actions'][8]['visible'] = true;
            }
        }

        return json_encode($form);
    }

    /**
     * Form action: stops everything and removes the binaries and the complete tailscale state.
     * Afterwards the node has to be downloaded and authenticated again.
     */
    public function UIUninstall()
    {
        exec($this->getTarget() . 'tailscale down');
        exec('kill $(pidof tailscaled)');
        exec('rm -R /mnt/data/tailscale-state');
        exec('rm /mnt/data/tailscale');
        exec('rm /mnt/data/tailscaled');
        $this->ReloadForm();
    }

    /**
     * Returns the directory that holds the tailscale binaries.
     * On the SymBox this is the persistent /mnt/data partition, during development the repository folder.
     */
    private function getTarget()
    {
        // Substring check, so platform variants like 'SymBox Pro' are also detected
        if (strstr(IPS_GetKernelPlatform(), 'SymBox') !== false) {
            return self::$targetProduction;
        } else {
            return self::$targetDevelopment;
        }
    }

    /**
     * Returns the --advertise-routes flag built from the 'AdvertiseRoutes' property.
     *
     * As a side effect IPv4/IPv6 forwarding is enabled in the kernel if at least one route is configured.
     * accept_ra is set to 2 so that router advertisements (required for Thread/Matter) still work while forwarding is on.
     * An empty route list results in '--advertise-routes=', which clears previously advertised routes.
     */
    private function getAdvertiseRoutes()
    {
        $routes = [];
        $ar = json_decode($this->ReadPropertyString('AdvertiseRoutes'), true);
        foreach ($ar as $r) {
            $routes[] = $r['Route'];
        }

        if (count($routes) > 0) {
            // Enable forwarding inside kernel only if required
            exec("sysctl -w net.ipv4.ip_forward=1");

            $dir = '/proc/sys/net/ipv6/conf/';
            foreach (scandir($dir) as $ifName) {
                if ($ifName === '.' || $ifName === '..' || !is_dir($dir . $ifName)) {
                    continue;
                }
                exec("sysctl -w net.ipv6.conf." . $ifName . ".forwarding=1");

                // Allow all RA announcements for Thread/Matter
                // Set accept_ra = 2 to allow RAs even when forwarding is enabled
                exec("sysctl -w net.ipv6.conf." . $ifName . ".accept_ra=2");
            }
        }

        return '--advertise-routes=' . escapeshellarg(implode(',', $routes));
    }

    /**
     * Derives the tailscale hostname from the licensee address. For OEM licenses of the form
     * 'user+oem@domain' the hostname becomes 'symbox-<oem>'. Returns an empty string otherwise,
     * in which case the hostname is not touched.
     */
    private function getHostname()
    {
        // Determine a nicer Hostname
        $address = IPS_GetLicensee();
        $start = strpos($address, '+');
        if ($start !== false) {
            $end = strpos($address, '@');
            $oem = substr($address, $start + 1, $end - $start - 1);
            $oem = $this->sanitizeDnsName($oem);
            return 'symbox-' . $oem;
        }
        return '';
    }

    /**
     * Returns all preferences that are managed by this module as command line flags.
     * They are used for both 'tailscale set' and 'tailscale up'.
     * Any other preference (e.g. one that was changed manually via 'tailscale set') is left untouched.
     */
    private function getPreferenceFlags()
    {
        $flags = [];

        $hostname = $this->getHostname();
        if ($hostname) {
            $flags[] = '--hostname=' . escapeshellarg($hostname);
        }

        // Do not accept any routes by default
        $flags[] = '--accept-routes=false';

        // Get routes that we want to advertise
        $flags[] = $this->getAdvertiseRoutes();

        // MagicDNS: Only let tailscale take over the DNS configuration if the user explicitly enabled it
        $flags[] = '--accept-dns=' . ($this->ReadPropertyBoolean('UseMagicDNS') ? 'true' : 'false');

        return implode(' ', $flags);
    }

    /**
     * Applies the module managed preferences via 'tailscale set'.
     * This works in any state (running, stopped) as long as tailscaled is running.
     */
    private function ApplyPreferences()
    {
        exec($this->getTarget() . 'tailscale set ' . $this->getPreferenceFlags() . ' 2>&1', $output, $exitCode);
        $output = trim(implode(PHP_EOL, $output));
        $this->SendDebug('ApplyPreferences', 'Exit code: ' . $exitCode . PHP_EOL . $output, 0);
        if ($exitCode != 0) {
            $this->SetBuffer('LastError', $output ?: ('Exit code ' . $exitCode));
            return false;
        }
        return true;
    }

    /**
     * Starts tailscaled in the background with its state in /mnt/data/tailscale-state.
     *
     * See the comments below for the approaches that did not work. IPS_Execute() is used because it closes
     * all file descriptors before exec (IP-Symcon 7.2+), which prevents tailscaled from inheriting sockets.
     */
    private function StartService()
    {
        if (!file_exists('/mnt/data/tailscale-state/')) {
            mkdir('/mnt/data/tailscale-state/');
        }

        //This does not detach from the symcon process and inherits file descriptors
        //exec('TS_DEBUG_FIREWALL_MODE="nftables"' . ' ' . $this->getTarget() . "tailscaled --statedir=/mnt/data/tailscale-state/ > /var/log/symcon/tailscale.log 2> /var/log/symcon/tailscale.log &");

        //This properly detaches from the process but still inherits file descriptors
        //exec('sh -c "TS_DEBUG_FIREWALL_MODE=nftables exec nohup setsid' . ' ' . $this->getTarget() . 'tailscaled --statedir=/mnt/data/tailscale-state/ > /var/log/symcon/tailscale.log 2> /var/log/symcon/tailscale.log &"');

        //We cannot use start-stop-daemon as it is buggy:  http://lists.busybox.net/pipermail/busybox/2019-March/087118.html
        //echo exec('TS_DEBUG_FIREWALL_MODE=nftables start-stop-daemon -S -b -O /var/log/symcon/tailscale.log -x /mnt/data/tailscaled -- --statedir=/mnt/data/tailscale-state/');

        //This does only properly work with IP-Symcon 7.2 which closes all fd's after fork and before exec
        //But we cannot redirect the stdout/stderr with this method and it will be written to the statedir
        putenv('TS_DEBUG_FIREWALL_MODE=nftables');
        IPS_Execute($this->getTarget() . 'tailscaled', '--statedir=/mnt/data/tailscale-state/', false, false);

        // Give it some time to connect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    /**
     * Starts the tunnel.
     *
     * Without auth key: applies the module managed preferences via 'tailscale set' and runs a bare 'tailscale up'.
     * With auth key: runs 'tailscale up --force-reauth --auth-key=... <all module managed flags>'.
     *
     * @param string $authKey optional auth key for (re-)authentication
     * @return bool true if tailscale accepted the command. The error is stored in the 'LastError' buffer otherwise.
     */
    private function StartTunnel($authKey = '')
    {
        if ($authKey) {
            // Authentication requires 'tailscale up' with flags. As soon as any flag is given,
            // tailscale requires all non-default preferences to be mentioned, so pass all module managed ones
            $command = 'tailscale up --force-reauth --auth-key=' . escapeshellarg($authKey) . ' ' . $this->getPreferenceFlags();
        } else {
            // Apply all module managed preferences via 'tailscale set' and bring the tunnel up with a bare 'tailscale up'.
            // A bare 'tailscale up' never complains about unmentioned non-default flags, so preferences that were
            // changed outside of this module (e.g. via 'tailscale set --accept-dns=false') are kept as they are
            if (!$this->ApplyPreferences()) {
                $this->UpdateStatus();
                return false;
            }
            $command = 'tailscale up';
        }

        exec($this->getTarget() . $command . ' 2>&1', $output, $exitCode);
        $output = trim(implode(PHP_EOL, $output));
        $this->SendDebug('StartTunnel', 'Exit code: ' . $exitCode . PHP_EOL . $output, 0);

        if ($exitCode != 0) {
            $this->SetBuffer('LastError', $output ?: ('Exit code ' . $exitCode));
            $this->UpdateStatus();
            return false;
        }
        $this->SetBuffer('LastError', '');

        // Give it some time to connect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
        return true;
    }

    /**
     * Stops the tunnel via 'tailscale down' and clears a pending start error.
     */
    private function StopTunnel()
    {
        exec($this->getTarget() . 'tailscale down 2>&1', $output, $exitCode);
        $this->SendDebug('StopTunnel', 'Exit code: ' . $exitCode . PHP_EOL . implode(PHP_EOL, $output), 0);
        $this->SetBuffer('LastError', '');

        // Give it some time to disconnect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    /**
     * Kills tailscaled. The tunnel state is persisted by tailscaled, so the next StartService() + 'tailscale up'
     * reconnects without re-authentication.
     */
    private function StopService()
    {
        exec('kill $(pidof tailscaled)');

        // Give it some time to disconnect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    /**
     * @return bool true if the tailscale binary exists in the target directory
     */
    private function isServiceInstalled()
    {
        return file_exists($this->getTarget() . 'tailscale');
    }

    /**
     * @return string|null the PID(s) of tailscaled, or null if it is not running
     */
    private function isServiceRunning()
    {
        return shell_exec('pidof tailscaled');
    }

    /**
     * @return bool true if the tunnel is up (BackendState 'Running')
     */
    private function isTunnelRunning()
    {
        $status = $this->getTunnelStatus();
        return $status && (json_decode($status)->BackendState == "Running");
    }

    /**
     * Queries 'tailscale status --json' without peer information.
     *
     * @return string|false the JSON status, or false if tailscaled does not respond (e.g. not running)
     */
    private function getTunnelStatus()
    {
        exec($this->getTarget() . 'tailscale status --json --peers=false --self=false 2>&1', $status, $exitCode);
        if ($exitCode == 0) {
            $status = trim(implode(PHP_EOL, $status));
            $this->SendDebug("TunnelStatus", $status, 0);
            return $status;
        }
        else {
            $this->SendDebug("TunnelStatus", "No response!", 0);
        }
        return false;
    }

    /**
     * @return bool true if the node is logged in, i.e. any BackendState other than 'NeedsLogin'
     */
    private function isTunnelAuthenticated()
    {
        $status = $this->getTunnelStatus();
        return $status && (json_decode($status)->BackendState != 'NeedsLogin');
    }

    /**
     * Converts an arbitrary string into a valid DNS label sequence (lowercase, [a-z0-9.-], max 63 chars per label).
     * Used to build a tailscale hostname from the licensee's OEM name.
     */
    private function sanitizeDnsName(string $input): string
    {
        $input = strtolower($input);

        // Replace any invalid characters with a minus
        $input = preg_replace('/[^a-z0-9\.-]+/', '-', $input);

        // Replace multiple consecutive dots with a single dot.
        $input = preg_replace('/\.+/', '.', $input);

        // Trim any leading or trailing dots and hyphens.
        $input = trim($input, '.-');

        // Process each label (part between dots) to ensure they don't start or end with hyphens.
        $labels = explode('.', $input);
        foreach ($labels as &$label) {
            $label = trim($label, '-');

            // Optionally: truncate labels longer than 63 characters.
            if (strlen($label) > 63) {
                $label = substr($label, 0, 63);
            }
        }
        $input = implode('.', $labels);

        return $input;
    }
}
