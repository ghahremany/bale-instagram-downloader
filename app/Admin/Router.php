<?php
declare(strict_types=1);

namespace App\Admin;

use App\Audit;
use App\Auth;
use App\Csrf;
use App\IpGuard;
use App\Settings;

final class Router
{
    private const PUBLIC_ROUTES = ['login', 'first-login', 'otp'];

    private const ROUTES = [
        'dashboard', 'users', 'downloads', 'broadcast', 'channels',
        'settings', 'security', 'admins', 'tools', 'audit',
        'login', 'first-login', 'otp', 'logout',
    ];

    public static function run(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!IpGuard::allowed(client_ip())) {
            Audit::log('ip_blocked', 'panel access from disallowed IP ' . client_ip(), null);
            abort(403, 'دسترسی پنل از این IP مجاز نیست.');
        }

        $route = strtolower((string) ($_GET['r'] ?? 'dashboard'));
        $route = preg_replace('/[^a-z\-]/', '', $route) ?: 'dashboard';
        if (!in_array($route, self::ROUTES, true)) {
            abort(404, 'صفحه مورد نظر پیدا نشد.');
        }

        if ($route === 'logout') {
            Csrf::assertPost();
            Audit::log('logout', 'admin signed out');
            Auth::logout();
            flash('ok', 'با موفقیت خارج شدید.');
            redirect('admin/?r=login');
        }

        $isPublic = in_array($route, self::PUBLIC_ROUTES, true);

        if (!$isPublic) {
            if (!Auth::check()) {
                if (Auth::pendingTwoFactor() !== null) {
                    redirect('admin/?r=otp');
                }
                redirect('admin/?r=login');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                Csrf::assertValid();
            }
        } else {
            /* Visiting an auth page while already signed in? */
            if ($route === 'login' && Auth::check()) {
                redirect('admin/');
            }
            if ($route !== 'login' && Auth::check()) {
                redirect('admin/');
            }
        }

        $file = APP_ROOT . '/app/Admin/pages/' . $route . '.php';
        if (!is_file($file)) {
            abort(404);
        }

        $pageTitle = '';
        $authPage = false;
        $subtitle = '';
        $wide = false;
        $active = $route;

        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        if (ob_get_level() > 0) {
            // never leak stray output above the layout
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        echo $authPage
            ? View::authLayout($pageTitle, $content, $subtitle, $wide)
            : View::layout($pageTitle, $active, $content);
    }

    public static function requireOwner(): void
    {
        if (!Auth::isOwner()) {
            abort(403, 'این بخش فقط برای مالک سیستم قابل دسترسی است.');
        }
    }

    public static function setting(string $key, string $default = ''): string
    {
        return Settings::get($key, $default);
    }
}
