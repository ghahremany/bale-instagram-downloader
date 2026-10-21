<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private const IDLE_TIMEOUT    = 7200;      // 2 hours of inactivity
    private const ABSOLUTE_LIMIT  = 43200;     // 12 hours max session
    private const MAX_FAILED      = 5;
    private const LOCK_MINUTES    = 15;

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return null; // anonymous: keep any pending 2FA state intact
        }
        if (!self::sessionFresh()) {
            self::logout();
            return null;
        }
        $row = Db::first('SELECT * FROM admins WHERE id = ? AND is_active = 1', [$id]);
        if ($row === null) {
            self::logout();
            return null;
        }
        return self::$user = $row;
    }

    public static function id(): ?int
    {
        return self::user() === null ? null : (int) self::$user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null && empty($_SESSION['2fa_pending']);
    }

    public static function isOwner(): bool
    {
        return (self::user()['role'] ?? '') === 'owner';
    }

    private static function sessionFresh(): bool
    {
        if ((string) ($_SESSION['_epoch'] ?? '') !== Settings::get('session_epoch', '1')) {
            return false;
        }
        $now = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        $started = (int) ($_SESSION['_started_at'] ?? $now);
        if ($now - $last > self::IDLE_TIMEOUT || $now - $started > self::ABSOLUTE_LIMIT) {
            return false;
        }
        $_SESSION['_last_activity'] = $now;
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['2fa_pending'], $_SESSION['otp'], $_SESSION['_last_activity'], $_SESSION['_started_at']);
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::$user = null;
    }

    /** @return array{status:string, message:string, admin?:array<string,mixed>} */
    public static function attempt(string $username, string $password): array
    {
        $ip = client_ip();
        $username = strtolower(trim($username));

        if ($username === '' || $password === '') {
            return ['status' => 'error', 'message' => 'نام کاربری و رمز عبور را وارد کنید.'];
        }
        if (IpGuard::blocked($ip, 60)) {
            return ['status' => 'error', 'message' => 'تلاش‌های ناموفق زیاد از این IP. یک ساعت دیگر امتحان کنید.'];
        }
        if (!RateLimiter::hit('login:' . $ip, 12, 900) || !RateLimiter::hit('login:u:' . sha1($username), 10, 900)) {
            self::recordAttempt($username, $ip, false);
            return ['status' => 'error', 'message' => 'تعداد تلاش‌ها بیش از حد مجاز است. کمی بعد دوباره امتحان کنید.'];
        }

        $admin = Db::first('SELECT * FROM admins WHERE username = ?', [$username]);
        if ($admin === null || empty($admin['password_hash'])) {
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            self::recordAttempt($username, $ip, false);
            return ['status' => 'error', 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }
        if (!(int) $admin['is_active']) {
            self::recordAttempt($username, $ip, false);
            return ['status' => 'error', 'message' => 'این حساب غیرفعال است.'];
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until'] . ' UTC') > time()) {
            return ['status' => 'error', 'message' => 'حساب موقتاً قفل شده است. چند دقیقه بعد دوباره تلاش کنید.'];
        }
        if (!password_verify($password, (string) $admin['password_hash'])) {
            $failed = (int) $admin['failed_attempts'] + 1;
            $lock = $failed >= self::MAX_FAILED ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            Db::update('admins', [
                'failed_attempts' => $failed,
                'locked_until'    => $lock,
                'updated_at'      => now_utc(),
            ], 'id = ?', [(int) $admin['id']]);
            self::recordAttempt($username, $ip, false);
            Audit::log('login_failed', 'wrong password', (int) $admin['id']);
            return ['status' => 'error', 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        /* Password OK. */
        self::recordAttempt($username, $ip, true);
        Db::update('admins', [
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => now_utc(),
            'last_login_ip'   => $ip,
            'updated_at'      => now_utc(),
        ], 'id = ?', [(int) $admin['id']]);

        if (password_needs_rehash((string) $admin['password_hash'], self::hashAlgo())) {
            Db::update('admins', ['password_hash' => self::hashPassword($password)], 'id = ?', [(int) $admin['id']]);
        }

        if ((int) $admin['must_change_password'] === 1) {
            $_SESSION['2fa_pending'] = ['admin_id' => (int) $admin['id'], 'purpose' => 'first_login', 'at' => time()];
            $_SESSION['_started_at'] = time();
            $_SESSION['_last_activity'] = time();
            return ['status' => 'first_login', 'message' => 'برای اولین ورود، کد فعال‌سازی نصب را وارد کنید.', 'admin' => $admin];
        }

        $needOtp = Settings::bool('require_2fa', true);
        if ($needOtp) {
            $_SESSION['2fa_pending'] = ['admin_id' => (int) $admin['id'], 'purpose' => 'login', 'at' => time()];
            $_SESSION['_started_at'] = time();
            $_SESSION['_last_activity'] = time();
            $info = Otp::issueForLogin((int) $admin['id']);
            $_SESSION['otp_delivery'] = $info;
            return ['status' => 'otp', 'message' => 'کد یکبار مصرف ارسال شد.', 'admin' => $admin, 'delivery' => $info];
        }

        self::completeLogin((int) $admin['id']);
        return ['status' => 'ok', 'message' => 'خوش آمدید.', 'admin' => $admin];
    }

    public static function completeLogin(int $adminId): void
    {
        unset($_SESSION['2fa_pending'], $_SESSION['otp']);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['_epoch'] = Settings::get('session_epoch', '1');
        $_SESSION['_started_at'] = time();
        $_SESSION['_last_activity'] = time();
        self::$user = null;
        Audit::log('login_success', 'panel login completed', $adminId);
    }

    public static function pendingTwoFactor(): ?array
    {
        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        if (time() - (int) ($pending['at'] ?? 0) > 900) {
            unset($_SESSION['2fa_pending'], $_SESSION['otp']);
            return null;
        }
        return $pending;
    }

    public static function cancelPending(): void
    {
        unset($_SESSION['2fa_pending'], $_SESSION['otp']);
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        try {
            Db::insert('login_attempts', [
                'username'   => mb_substr($username, 0, 64),
                'ip'         => $ip,
                'success'    => $success ? 1 : 0,
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable) {
        }
    }

    /* ---------------------------------------------------------- password mgmt */

    public static function validatePassword(string $password): ?string
    {
        $min = 10;
        if (mb_strlen($password) < $min) {
            return 'رمز عبور باید حداقل ' . $min . ' کاراکتر باشد.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'رمز عبور باید شامل حروف و عدد باشد.';
        }
        $weak = ['password', '12345678', '123456789', 'qwerty', 'admin', 'bale1234', 'instagram'];
        $lower = mb_strtolower($password);
        foreach ($weak as $needle) {
            if (str_contains($lower, $needle)) {
                return 'رمز عبور انتخابی بسیار ساده است. رمز پیچیده‌تری انتخاب کنید.';
            }
        }
        return null;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::hashAlgo());
    }

    private static function hashAlgo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function changePassword(int $adminId, string $newPassword): void
    {
        Db::update('admins', [
            'password_hash'        => self::hashPassword($newPassword),
            'must_change_password' => 0,
            'updated_at'           => now_utc(),
        ], 'id = ?', [$adminId]);
        Audit::log('password_changed', 'admin changed own password', $adminId);
    }

    public static function verifyPassword(int $adminId, string $password): bool
    {
        $hash = Db::value('SELECT password_hash FROM admins WHERE id = ?', [$adminId]);
        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }
}
