<?php

declare(strict_types=1);
class TailscaleVPN extends IPSModule
{
    private static $version = '1.84.0';
    private static $filename = 'tailscale_%s_arm64.tgz';
    private static $filehash = '41516fbdd12ae6cbd7f41c875812ca683c0ead54bef3393e5886c232c954996c';
    private static $url = 'https://pkgs.tailscale.com/stable/%s';
    private static $targetProduction = '/mnt/data/';
    private static $targetDevelopment = __DIR__ . '/../';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean('AutoStartVPN', false);

        $this->RegisterPropertyString('AdvertiseRoutes', '[]');

        $this->RegisterVariableBoolean('State', $this->Translate('VPN'), 'Switch', 0);
        $this->EnableAction('State');

        $this->RegisterVariableString('Status', 'Status', '', 1);

        $this->RegisterTimer('Update', 10 * 1000, 'TSVPN_UpdateStatus($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->HandleAutostart();
        }
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->UpdateStatus();
    }

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
                    $this->StartTunnel();
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

    public function UIDownload()
    {
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

        if (hash('sha256', file_get_contents($target . $filename)) != self::$filehash) {
            $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Hash validation failed!'));
            $this->UpdateFormField('DownloadButton', 'visible', true);
            return;
        }

        $this->UpdateFormField('DownloadIndicator', 'caption', $this->Translate('Extracting...'));
        ini_set('memory_limit', '128M');
        $phar = new PharData($target . $filename);
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            if (in_array($file->getFileName(), ['tailscale', 'tailscaled'])) {
                // Delete file first. Even if it is used, we want to work around the "Text file busy" problem
                if (file_exists($target . $file->getPathName())) {
                    unlink($target . $file->getPathName());
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

    public function UIStartService()
    {
        $this->StartService();
        $this->ReloadForm();
    }

    public function UIStartTunnel(string $authKey)
    {
        $this->StartTunnel($authKey);
        $this->ReloadForm();
    }

    public function UpdateStatus()
    {
        if (!$this->isServiceInstalled()) {
            $this->SetValue('Status', $this->Translate('Not installed!'));
            $this->SetValue('State', false);
        } elseif ($this->isTunnelRunning()) {
            $this->SetValue('Status', $this->Translate('Connected!'));
            $this->SetValue('State', true);
        } else {
            $this->SetValue('Status', $this->getTunnelStatus());
            $this->SetValue('State', false);
        }
    }

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
                $form['actions'][5]['caption'] = $this->Translate('Tunnel') . ': ' . $status;
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
        }

        return json_encode($form);
    }

    public function UIUninstall()
    {
        exec($this->getTarget() . 'tailscale down');
        exec('kill $(pidof tailscaled)');
        exec('rm -R /mnt/data/tailscale-state');
        exec('rm /mnt/data/tailscale');
        exec('rm /mnt/data/tailscaled');
        $this->ReloadForm();
    }

    private function getTarget()
    {
        if (strstr('SymBox', IPS_GetKernelPlatform()) !== false) {
            return self::$targetProduction;
        } else {
            return self::$targetDevelopment;
        }
    }

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

    private function StartTunnel($authKey = '')
    {
        $hostname = '';

        // Determine a nicer Hostname
        $address = IPS_GetLicensee();
        $start = strpos($address, '+');
        if ($start !== false) {
            $end = strpos($address, '@');
            $oem = substr($address, $start + 1, $end - $start - 1);
            $oem = $this->sanitizeDnsName($oem);
            $hostname = ' ' . '--hostname ' . 'symbox-' . $oem;
        }

        // Check if we want to advertise routes
        $advertiseRoutes = '';
        $routes = [];

        $ar = json_decode($this->ReadPropertyString('AdvertiseRoutes'), true);
        foreach ($ar as $r) {
            $routes[] = $r['Route'];
        }

        if (count($routes) > 0) {
            $advertiseRoutes = ' ' . '--advertise-routes=' . implode(',', $routes);

            // Enable forwarding inside kernel only if required
            exec("echo 'net.ipv4.ip_forward = 1' > /etc/sysctl.conf");
            exec("echo 'net.ipv6.conf.all.forwarding = 1' >> /etc/sysctl.conf");
            exec('sysctl -p');
        }

        if ($authKey) {
            $authKey = ' ' . '--force-reauth --auth-key=' . $authKey;
        }

        exec($this->getTarget() . 'tailscale up' . $authKey . $hostname . $advertiseRoutes);

        // Give it some time to connect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    private function StopTunnel()
    {
        exec($this->getTarget() . 'tailscale down');

        // Give it some time to disconnect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    private function StopService()
    {
        exec('kill $(pidof tailscaled)');

        // Give it some time to disconnect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    private function isServiceInstalled()
    {
        return file_exists($this->getTarget() . 'tailscale');
    }

    private function isServiceRunning()
    {
        return shell_exec('pidof tailscaled');
    }

    private function isTunnelRunning()
    {
        exec($this->getTarget() . 'tailscale status 2>&1', $status, $exitCode);
        return ($exitCode == 0) && !str_contains(implode(PHP_EOL, $status), 'not logged in');
    }

    private function getTunnelStatus()
    {
        exec($this->getTarget() . 'tailscale status 2>&1', $status);
        $lines = '';
        foreach ($status as $line) {
            if (!str_starts_with($line, 'Log in at:')) {
                $lines .= $line . PHP_EOL;
            }
        }
        return $lines;
    }

    private function isTunnelAuthenticated()
    {
        $status = $this->getTunnelStatus();
        return !(str_contains($status, 'Logged out.') || str_contains($status, 'not logged in'));
    }

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