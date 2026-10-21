<?php
declare(strict_types=1);

namespace App;

final class AppName
{
    public static function title(): string
    {
        $t = trim(Settings::get('panel_title'));
        return $t !== '' ? $t : (string) Config::get('APP_NAME', 'Bale Instagram Downloader');
    }

    public static function version(): string
    {
        return APP_VERSION;
    }
}
