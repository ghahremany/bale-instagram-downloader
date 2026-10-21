<?php
/**
 * Admin panel front controller: /admin/
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

try {
    App\Admin\Router::run();
} catch (Throwable $e) {
    App\Log::error('Panel fatal error: ' . $e->getMessage(), ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
    ob_get_clean();
    abort(500, 'خطای غیرمنتظره‌ای رخ داد. اگر مشکل ادامه داشت، اتصال پایگاه داده و لاگ‌های سرور را بررسی کنید.');
}
