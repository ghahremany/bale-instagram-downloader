<?php
/**
 * Bale Instagram Downloader — application bootstrap.
 * Every entry point (public, cli, bot) requires this file.
 */
declare(strict_types=1);

define('APP_START', microtime(true));
define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/helpers.php';

App\Config::load(APP_ROOT . '/.env');
App\Config::setDefault('APP_NAME', 'Bale Instagram Downloader');
App\Config::setDefault('APP_ENV', 'production');
App\Config::setDefault('APP_DEBUG', 'false');
App\Config::setDefault('APP_TIMEZONE', 'Asia/Tehran');
App\Config::setDefault('DB_PORT', '3306');
App\Config::setDefault('BALE_API_BASE', 'https://tapi.bale.ai');
App\Config::setDefault('IG_API_URL', 'https://api.fast-creat.ir/instagram');
App\Config::setDefault('IG_PROVIDER_MODE', 'post2');
App\Config::setDefault('BOT_MODE', 'polling');
App\Config::setDefault('APP_URL', '');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

/* PHP error handling: never leak stack traces to the browser in production. */
error_reporting(E_ALL);
$debug = App\Config::bool('APP_DEBUG', false) && App\Config::get('APP_ENV') !== 'production';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

/* Hardened session cookie for the admin panel. */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('bale_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => App\Config::bool('COOKIE_SECURE', true) && security_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

App\Installer::guard();
