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

    public function Download()
    {
        $filename = sprintf(self::$filename, self::$version);
        $download = sprintf(self::$url, $filename);

        if(strstr("SymBox", IPS_GetKernelPlatform()) !== false) {
            $target = self::$targetProduction;
        }
        else {
            $target = self::$targetDevelopment;
        }

        $this->UpdateFormField("DownloadButton", "visible", false);
        $this->UpdateFormField("DownloadIndicator", "visible", true);
        $this->UpdateFormField("DownloadIndicator", "caption", "Downloading...");
        file_put_contents($target . $filename, fopen($download, 'r'));

        $this->UpdateFormField("DownloadIndicator", "caption", "Extracting...");
        ini_set('memory_limit', '128M');
        $phar = new PharData($target . $filename);
        $phar->extractTo($target, ["tailscale", "tailscaled"], true);

        $this->UpdateFormField("DownloadIndicator", "caption", "Cleanup...");
        unlink($target . $filename);
        $this->UpdateFormField("DownloadIndicator", "visible", false);
    }
}