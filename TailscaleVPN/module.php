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
    }

    public function GetConfigurationForm() {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $version = false;
        if (file_exists($this->getTarget() . "tailscale")) {
            $result = shell_exec($this->getTarget() . "tailscale --version");
            if ($result) {
                $lines = explode("\n", $result);
                $version = $lines[0];
            }
            else {
                $version = "Broken";
            }
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

            $serviceRunning = shell_exec("pidof tailscaled");
            $form['actions'][3]['caption'] = $this->Translate('Status') . ': ' . ($serviceRunning ? $this->Translate("Service is running!") : $this->Translate("Service is stopped!"));
            $form['actions'][3]['visible'] = true;
        }

        return json_encode($form);
    }
}