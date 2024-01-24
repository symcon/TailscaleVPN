<?php

declare(strict_types=1);
class TailscaleVPN extends IPSModule
{
    static private $version = "1.56.1";
    static private $filename = "tailscale_%s_arm64.tgz";
    static private $url = "https://pkgs.tailscale.com/stable/%s";
    static private $targetProduction = "/mnt/data/";
    static private $targetDevelopment = __DIR__ . "/../";
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString("AuthKey", "");
        $this->RegisterPropertyString("AdvertiseRoutes", "[]");

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

        $hasAuthKey = $this->ReadPropertyString("AuthKey") !== "";
        $this->MaintainVariable('State', $this->Translate('VPN'), VARIABLETYPE_BOOLEAN, "Switch", 0, $hasAuthKey);
        $this->MaintainAction('State', $hasAuthKey);

        $this->RegisterVariableString('Status', 'Status', '', 1);

        $this->UpdateStatus();
    }

    public function RequestAction($Ident, $Value) {
        switch($Ident) {
            case 'State':
                if ($Value) {
                    if (!$this->isServiceRunning()) {
                        $this->StartService();
                    }
                    $this->StartTunnel();
                }
                else {
                    $this->StopTunnel();
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

    public function Download()
    {
        $filename = sprintf(self::$filename, self::$version);
        $download = sprintf(self::$url, $filename);
        $target = $this->getTarget();


        $this->UpdateFormField("DownloadButton", "visible", false);
        $this->UpdateFormField("DownloadIndicator", "visible", true);
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
        $this->UpdateFormField("DownloadIndicator", "visible", false);

        // Update Form after Download
        $this->ReloadForm();
    }

    public function StartService()
    {
        if (!file_exists('/mnt/data/tailscale-state/')) {
            mkdir('/mnt/data/tailscale-state/');
        }

        exec('TS_DEBUG_FIREWALL_MODE="nftables"' . ' ' . $this->getTarget() . "tailscaled --statedir=/mnt/data/tailscale-state/ > /var/log/symcon/tailscale.log 2> /var/log/symcon/tailscale.log &");

        // Give it some time to connect
        IPS_Sleep(2500);

        //Reload Form
        $this->ReloadForm();
    }

    public function StartTunnel()
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
        }

        exec($this->getTarget() . "tailscale up --auth-key=" . $this->ReadPropertyString("AuthKey") . $hostname . $advertiseRoutes);

        // Give it some time to connect
        IPS_Sleep(2500);

        //Reload Form
        $this->ReloadForm();

        //Update Status
        $this->UpdateStatus();
    }

    public function UpdateStatus()
    {
        if ($this->ReadPropertyString("AuthKey") === "") {
            $this->SetValue('Status', $this->Translate('Missing AuthKey!'));
        }
        else {
            exec($this->getTarget() . "tailscale status 2>&1", $status, $exitCode);
            if ($exitCode == 0) {
                $this->SetValue('Status', $this->Translate('Connected!'));
            } else {
                $this->SetValue('Status', implode(PHP_EOL, $status));
            }
        }
    }

    public function StopTunnel()
    {
        exec($this->getTarget() . "tailscale down");

        // Give it some time to connect
        IPS_Sleep(2500);

        //Reload Form
        $this->ReloadForm();

        //Update Status
        $this->UpdateStatus();
    }

    private function isServiceRunning() {
        return shell_exec("pidof tailscaled");
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