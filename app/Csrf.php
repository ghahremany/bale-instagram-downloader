<?php
declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        return csrf_token();
    }

    public static function field(): string
    {
        return csrf_field();
    }

    /** Non-throwing token check for login-style forms. */
    public static function valid(?string $token): bool
    {
        return csrf_verify($token);
    }

    /** Alias kept for readability in page controllers. */
    public static function assertPostToken(?string $token): bool
    {
        return self::valid($token);
    }

    /** Verify for state-changing requests; aborts with 419 when invalid. */
    public static function assertValid(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!csrf_verify(is_string($token) ? $token : null)) {
            Log::warn('CSRF verification failed', [
                'ip'   => client_ip(),
                'uri'  => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            abort(419, 'اعتبار فرم منقضی شده است. صفحه را دوباره بارگذاری کنید.');
        }
    }

    /** Enforce POST + CSRF + same-origin for state changing routes. */
    public static function assertPost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            header('Allow: POST');
            abort(405, 'این مسیر فقط درخواست POST را می‌پذیرد.');
        }
        self::assertValid();
    }
}
