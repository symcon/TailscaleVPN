<?php

declare(strict_types=1);
class TailscaleVPN extends IPSModule
{
    static private $version = "1.58.2";
    static private $filename = "tailscale_%s_arm64.tgz";
    static private $url = "https://pkgs.tailscale.com/stable/%s";
    static private $targetProduction = "/mnt/data/";
    static private $targetDevelopment = __DIR__ . "/../";
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString("AdvertiseRoutes", "[]");

        $this->RegisterVariableBoolean('State', $this->Translate('VPN'), "Switch", 0);
        $this->RegisterAction('State');

        $this->RegisterVariableString('Status', 'Status', '', 1);

        $this->RegisterTimer('Update', 10 * 1000, 'TSVPN_UpdateStatus($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->UpdateStatus();
    }

    public function RequestAction($Ident, $Value) {
        switch($Ident) {
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
                    $this->StartTunnel();
                    $this->ReloadForm();
                }
                else {
                    $this->StopTunnel();
                    $this->ReloadForm();
                }
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    private function getTarget() {
        if(strstr("SymBox", IPS_GetKernelPlatform()) !== false) {
            return self::$targetProduction;
        }
        else {
            return self::$targetDevelopment;
        }
    }

    public function UIDownload()
    {
        $this->UpdateFormField("DownloadButton", "visible", false);
        $this->UpdateFormField("DownloadIndicator", "visible", true);

        // Stop Tunnel
        $tunnelRunning = $this->isTunnelRunning();
        if ($tunnelRunning) {
            $this->UpdateFormField("DownloadIndicator", "caption", "Stopping Tunnel...");
            $this->StopTunnel();
        }

        // Stop Service
        $serviceRunning = $this->isServiceRunning();
        if ($serviceRunning) {
            $this->UpdateFormField("DownloadIndicator", "caption", "Stopping Service...");
            $this->StopService();
        }

        $filename = sprintf(self::$filename, self::$version);
        $download = sprintf(self::$url, $filename);
        $target = $this->getTarget();

        $this->UpdateFormField("DownloadIndicator", "caption", "Downloading...");
        file_put_contents($target . $filename, fopen($download, 'r'));

        $this->UpdateFormField("DownloadIndicator", "caption", "Extracting...");
        ini_set('memory_limit', '128M');
        $phar = new PharData($target . $filename);
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            if (in_array($file->getFileName(), ['tailscale', 'tailscaled'])) {
                $contents = file_get_contents($file->getPathName());
                file_put_contents($target . $file->getFileName(), $contents);
                chmod($target . $file->getFileName(), 0777);
            }
        }
        $this->UpdateFormField("DownloadIndicator", "caption", "Cleanup...");
        unlink($target . $filename);

        if ($serviceRunning) {
            $this->UpdateFormField("DownloadIndicator", "caption", "Starting Service...");
            $this->StartService();
        }

        if ($tunnelRunning) {
            $this->UpdateFormField("DownloadIndicator", "caption", "Starting Tunnel...");
            $this->StartTunnel();
        }

        $this->UpdateFormField("DownloadIndicator", "visible", false);

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

    private function StartService()
    {
        if (!file_exists('/mnt/data/tailscale-state/')) {
            mkdir('/mnt/data/tailscale-state/');
        }

        exec('TS_DEBUG_FIREWALL_MODE="nftables"' . ' ' . $this->getTarget() . "tailscaled --statedir=/mnt/data/tailscale-state/ > /var/log/symcon/tailscale.log 2> /var/log/symcon/tailscale.log &");

        // Give it some time to connect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    private function StartTunnel($authKey = "")
    {
        $hostname = "";

        // Determine a nicer Hostname
        $address = IPS_GetLicensee();
        $start = strpos($address, "+");
        if($start !== false)
        {
            $end = strpos($address, "@");
            $oem = substr($address, $start+1, $end - $start - 1);
            $hostname = " " . "--hostname " . "symbox-" . $oem;
        }

        // Check if we want to advertise routes
        $advertiseRoutes = "";
        $routes = [];

        $ar = json_decode($this->ReadPropertyString("AdvertiseRoutes"), true);
        foreach ($ar as $r) {
            $routes[] = $r['Route'];
        }

        if (count($routes) > 0) {
            $advertiseRoutes = " " . "--advertise-routes=" . implode(",", $routes);

            // Enable forwarding inside kernel only if required
            exec("echo 'net.ipv4.ip_forward = 1' > /etc/sysctl.conf");
            exec("echo 'net.ipv6.conf.all.forwarding = 1' >> /etc/sysctl.conf");
            exec("sysctl -p");
        }

        if ($authKey) {
            $authKey = " " . "--auth-key=" . $authKey;
        }

        exec($this->getTarget() . "tailscale up" . $authKey . $hostname . $advertiseRoutes);

        // Give it some time to connect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    public function UpdateStatus()
    {
        if (!$this->isServiceInstalled()) {
            $this->SetValue('Status', $this->Translate('Not installed!'));
            $this->SetValue('State', false);
        }
        else if ($this->isTunnelRunning()) {
            $this->SetValue('Status', $this->Translate('Connected!'));
            $this->SetValue('State', true);
        } else {
            $this->SetValue('Status', implode(PHP_EOL, $this->getTunnelStatus()));
            $this->SetValue('State', false);
        }
    }

    private function StopTunnel()
    {
        exec($this->getTarget() . "tailscale down");

        // Give it some time to disconnect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    private function StopService()
    {
        exec("kill $(pidof tailscaled)");

        // Give it some time to disconnect
        IPS_Sleep(2500);

        //Update Status
        $this->UpdateStatus();
    }

    private function isServiceInstalled() {
        return file_exists($this->getTarget() . "tailscale");
    }

    private function isServiceRunning() {
        return shell_exec("pidof tailscaled");
    }

    private function isTunnelRunning() {
        exec($this->getTarget() . "tailscale status 2>&1", $status, $exitCode);
        return $exitCode == 0;
    }

    private function getTunnelStatus() {
        exec($this->getTarget() . "tailscale status 2>&1", $status, $exitCode);
        return $status;
    }

    public function GetConfigurationForm() {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $version = false;
        $status = false;
        $serviceRunning = $this->isServiceRunning();
        $tunnelRunning = false;

        if (file_exists($this->getTarget() . "tailscale")) {
            $result = shell_exec($this->getTarget() . "tailscale --version");
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
            exec($this->getTarget() . "tailscale status 2>&1", $status, $exitCode);
            $tunnelRunning = $exitCode == 0;
            $status = implode(PHP_EOL, $status);
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

            $form['actions'][3]['caption'] = $this->Translate('Service') . ': ' . ($serviceRunning ? $this->Translate("Running!") : $this->Translate("Stopped!"));
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
            else if (!$tunnelRunning) {
                $form['actions'][6]['visible'] = true;
            }
        }

        return json_encode($form);
    }
}