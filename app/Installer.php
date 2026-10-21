<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * First-run installer: requirements, MySQL schema, owner account, .env and the
 * one-time activation code used for the very first panel login.
 *
 * The installer disables itself forever by writing storage/installed.lock.
 */
final class Installer
{
    public const LOCK_FILE = 'installed.lock';

    public static function lockPath(): string
    {
        return APP_ROOT . '/storage/' . self::LOCK_FILE;
    }

    public static function envPath(): string
    {
        return APP_ROOT . '/.env';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockPath()) || (is_file(self::envPath()) && filesize(self::envPath()) > 0);
    }

    /** Sends un-installed traffic to the wizard; protects every other entry point. */
    public static function guard(): void
    {
        if (PHP_SAPI === 'cli' || self::isInstalled()) {
            return;
        }
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === 'install.php') {
            return;
        }
        header('Location: ' . self::installUrl(), true, 302);
        exit;
    }

    public static function installUrl(): string
    {
        $self = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
        $pos = strpos($self, '/public/');
        if ($pos !== false) {
            return substr($self, 0, $pos) . '/public/install.php';
        }
        $dir = rtrim(str_replace('\\', '/', dirname($self)), '/');
        return ($dir === '' ? '' : $dir) . '/install.php';
    }

    /**
     * @return array<string,array{ok:bool,label:string,value:string,hint:string}>
     */
    public static function requirements(): array
    {
        $checks = [];
        $checks['php'] = [
            'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
            'label' => 'نسخه PHP',
            'value' => PHP_VERSION,
            'hint'  => 'حداقل PHP 8.1 لازم است.',
        ];
        foreach (['pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL', 'openssl' => 'OpenSSL', 'mbstring' => 'mbstring', 'json' => 'JSON'] as $ext => $label) {
            $checks['ext_' . $ext] = [
                'ok'    => extension_loaded($ext),
                'label' => 'افزونه ' . $label,
                'value' => extension_loaded($ext) ? 'نصب است' : 'نصب نیست',
                'hint'  => "افزونه PHP «{$ext}» الزامی است.",
            ];
        }
        $checks['openssl_gcm'] = [
            'ok'    => in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
            'label' => 'پشتیبانی AES-256-GCM',
            'value' => in_array('aes-256-gcm', openssl_get_cipher_methods(), true) ? 'فعال' : 'غیرفعال',
            'hint'  => 'برای رمزنگاری توکن و کدها لازم است.',
        ];
        foreach (['storage', 'storage/logs', 'storage/cache', 'storage/tmp'] as $dir) {
            $path = APP_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
            }
            $checks['dir_' . $dir] = [
                'ok'    => is_dir($path) && is_writable($path),
                'label' => 'قابل نوشتن: ' . $dir,
                'value' => is_writable($path) ? 'بله' : 'خیر',
                'hint'  => "دسترسی نوشتن به پوشه {$dir} بدهید (chmod 770).",
            ];
        }
        $rootWritable = is_writable(APP_ROOT);
        $checks['env_writable'] = [
            'ok'    => $rootWritable || (is_file(self::envPath()) && is_writable(self::envPath())),
            'label' => 'ساخت فایل .env',
            'value' => $rootWritable ? 'امکان‌پذیر' : 'غیرقابل نوشتن',
            'hint'  => 'پوشه پروژه باید برای وب‌سرور قابل نوشتن باشد تا .env ساخته شود.',
        ];
        $checks['schema'] = [
            'ok'    => is_file(APP_ROOT . '/database/schema.sql'),
            'label' => 'فایل ساختار پایگاه داده',
            'value' => is_file(APP_ROOT . '/database/schema.sql') ? 'موجود' : 'ناموجود',
            'hint'  => 'فایل database/schema.sql پیدا نشد.',
        ];
        $checks['https'] = [
            'ok'    => security_is_https() || in_array(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), ['localhost', '127.0.0.1'], true) || str_contains((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost'),
            'label' => 'اتصال امن (HTTPS)',
            'value' => security_is_https() ? 'فعال' : 'غیرفعال',
            'hint'  => 'در محیط واقعی حتماً HTTPS فعال کنید (کوکی امن و وب‌هوک).',
        ];
        return $checks;
    }

    public static function allRequirementsPassed(array $checks): bool
    {
        foreach ($checks as $key => $check) {
            if ($key === 'https') {
                continue; // warning only
            }
            if (!$check['ok']) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> field => error */
    public static function validate(array $in): array
    {
        $errors = [];
        $username = strtolower(trim((string) ($in['admin_username'] ?? '')));
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
            $errors['admin_username'] = 'نام کاربری باید ۳ تا ۳۲ کاراکتر و شامل حروف کوچک، عدد، نقطه، خط تیره یا زیرخط باشد.';
        }
        $password = (string) ($in['admin_password'] ?? '');
        if (($msg = Auth::validatePassword($password)) !== null) {
            $errors['admin_password'] = $msg;
        }
        if ($password !== (string) ($in['admin_password_confirm'] ?? '')) {
            $errors['admin_password_confirm'] = 'تکرار رمز عبور مطابقت ندارد.';
        }
        foreach (['db_host', 'db_name', 'db_user'] as $field) {
            if (trim((string) ($in['db_' . substr($field, 3)] ?? '')) === '') {
                $errors[$field] = 'این فیلد الزامی است.';
            }
        }
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', (string) ($in['db_name'] ?? ''))) {
            $errors['db_name'] = 'نام پایگاه داده فقط می‌تواند شامل حروف، عدد، _ و - باشد.';
        }
        $token = trim((string) ($in['bot_token'] ?? ''));
        if ($token !== '' && !preg_match('/^\d{3,}:[A-Za-z0-9_\-]{10,}$/', $token)) {
            $errors['bot_token'] = 'قالب توکن بله صحیح نیست (مثل ۱۲۳۴۵۶:AAab...).';
        }
        if (!in_array((string) ($in['otp_delivery'] ?? 'bale'), ['bale', 'file'], true)) {
            $errors['otp_delivery'] = 'روش ارسال کد نامعتبر است.';
        }
        $chatId = trim((string) ($in['otp_chat_id'] ?? ''));
        if (($in['otp_delivery'] ?? 'bale') === 'bale') {
            if ($token === '') {
                $errors['bot_token'] = 'برای ارسال کد از طریق بله، توکن ربات لازم است.';
            }
            if (!preg_match('/^-?\d{4,20}$/', $chatId)) {
                $errors['otp_chat_id'] = 'شناسه چت عددی بله را وارد کنید (مثلاً 123456789).';
            }
        }
        $igKey = trim((string) ($in['ig_api_key'] ?? ''));
        if ($igKey !== '' && strlen($igKey) < 6) {
            $errors['ig_api_key'] = 'کلید API بسیار کوتاه است.';
        }
        return $errors;
    }

    /**
     * Run the installation. Returns data for the success screen.
     *
     * @return array{activation_code:string, activation_expires:string, admin_username:string, otp_sent:bool}
     */
    public static function install(array $in): array
    {
        if (self::isInstalled()) {
            throw new RuntimeException('نصب قبلاً انجام شده است. برای نصب مجدد، فایل storage/installed.lock و .env را حذف کنید.');
        }
        $errors = self::validate($in);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $dbHost = trim((string) $in['db_host']);
        $dbPort = (int) ($in['db_port'] ?? 3306) ?: 3306;
        $dbName = trim((string) $in['db_name']);
        $dbUser = trim((string) $in['db_user']);
        $dbPass = (string) ($in['db_pass'] ?? '');

        /* 1) connections + schema ------------------------------------------- */
        try {
            $root = Db::connect($dbHost, $dbPort, $dbUser, $dbPass);
        } catch (PDOException $e) {
            throw new RuntimeException('اتصال به MySQL برقرار نشد: ' . $e->getMessage());
        }
        try {
            $root->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = Db::connect($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
            $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
            foreach (self::splitSql($schema) as $statement) {
                $pdo->exec($statement);
            }
        } catch (PDOException $e) {
            throw new RuntimeException('ایجاد جداول پایگاه داده ناموفق بود: ' . $e->getMessage()
                . ' — کاربر دیتابیس باید اجازه CREATE TABLE داشته باشد.');
        }

        /* 2) app key + .env ------------------------------------------------- */
        $appKey = bin2hex(random_bytes(32));
        $appUrl = rtrim(trim((string) ($in['app_url'] ?? '')), '/');
        if ($appUrl === '') {
            $scheme = security_is_https() ? 'https' : 'http';
            $appUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/public/install.php'))), '/');
            if ($dir !== '' && $dir !== '/public') {
                $appUrl .= $dir;
            }
        }
        $webhookSecret = bin2hex(random_bytes(24));

        $env = [
            'APP_NAME'           => 'Bale Instagram Downloader',
            'APP_ENV'            => 'production',
            'APP_DEBUG'          => 'false',
            'APP_URL'            => $appUrl,
            'APP_TIMEZONE'       => 'Asia/Tehran',
            'APP_KEY'            => $appKey,
            'DB_HOST'            => $dbHost,
            'DB_PORT'            => (string) $dbPort,
            'DB_NAME'            => $dbName,
            'DB_USER'            => $dbUser,
            'DB_PASS'            => $dbPass,
            'BALE_BOT_TOKEN'     => trim((string) ($in['bot_token'] ?? '')),
            'BALE_API_BASE'      => 'https://tapi.bale.ai',
            'BALE_WEBHOOK_SECRET'=> $webhookSecret,
            'BALE_ADMIN_IDS'     => trim((string) ($in['otp_chat_id'] ?? '')),
            'IG_API_KEY'         => trim((string) ($in['ig_api_key'] ?? '')),
            'IG_API_URL'         => 'https://api.fast-creat.ir/instagram',
            'BOT_MODE'           => 'polling',
        ];
        self::writeEnv($env);

        /* 3) settings + owner ----------------------------------------------- */
        Config::load(self::envPath());
        Config::setDefault('APP_KEY', $appKey);
        Settings::flush();

        $rawSettings = [
            'installed_at'     => now_utc(),
            'schema_version'   => '1',
            'panel_title'      => trim((string) ($in['panel_title'] ?? '')) ?: 'دانلودر اینستاگرام بله',
            'bot_token'        => trim((string) ($in['bot_token'] ?? '')),
            'bot_username'     => '',
            'webhook_secret'   => $webhookSecret,
            'ig_api_key'       => trim((string) ($in['ig_api_key'] ?? '')),
            'otp_delivery'     => (string) $in['otp_delivery'],
            'otp_chat_id'      => trim((string) ($in['otp_chat_id'] ?? '')),
            'require_2fa'      => '1',
            'bot_admin_ids'    => trim((string) ($in['otp_chat_id'] ?? '')),
        ];
        foreach ($rawSettings as $key => $value) {
            $stored = Settings::isSecret($key) && $value !== '' ? Settings::encrypt($value) : $value;
            Settings::insertRaw($pdo, $key, $stored);
        }

        $username = strtolower(trim((string) $in['admin_username']));
        $stmt = $pdo->prepare(
            'INSERT INTO admins (username, password_hash, display_name, role, is_active, must_change_password, created_at, updated_at)
             VALUES (?, ?, ?, "owner", 1, 1, ?, ?)'
        );
        $stmt->execute([
            $username,
            Auth::hashPassword((string) $in['admin_password']),
            trim((string) ($in['admin_name'] ?? '')) ?: $username,
            now_utc(),
            now_utc(),
        ]);

        /* 4) activation code for the first login ---------------------------- */
        $code = Otp::generateCode();
        $expires = gmdate('Y-m-d H:i:s', time() + 86400);
        $adminId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO setup_tokens (admin_id, token_hash, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminId ?: null, hash_hmac('sha256', $code, $appKey), 'first_login', $expires, now_utc()]);

        $otpSent = false;
        if (($in['otp_delivery'] ?? 'bale') === 'bale') {
            $otpSent = self::sendActivationCode($env, $code);
        }

        /* 5) lock ------------------------------------------------------------ */
        @file_put_contents(self::lockPath(), json_encode([
            'installed_at' => now_utc(),
            'version'      => APP_VERSION,
            'admin'        => $username,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod(self::lockPath(), 0640);

        Audit::log('installed', 'installation completed', null);

        return [
            'activation_code'    => $code,
            'activation_expires' => $expires,
            'admin_username'     => $username,
            'otp_sent'           => $otpSent,
        ];
    }

    /** @param array<string,string> $values */
    public static function writeEnv(array $values): void
    {
        $lines = [
            '# Generated by the installer on ' . gmdate('c'),
            '# Keep this file out of version control and chmod it to 0600.',
            '',
        ];
        foreach ($values as $key => $value) {
            $needsQuotes = $value === '' || preg_match('/[\s#"\'$]/', $value);
            $rendered = $needsQuotes
                ? '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"'
                : $value;
            $lines[] = $key . '=' . $rendered;
        }
        $path = self::envPath();
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
            throw new RuntimeException('نوشتن فایل .env ناموفق بود. دسترسی نوشتن پوشه پروژه را بررسی کنید.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('جابه‌جایی فایل .env ناموفق بود.');
        }
        @chmod($path, 0600);
    }

    private static function sendActivationCode(array $env, string $code): bool
    {
        try {
            $bot = new Bale($env['BALE_BOT_TOKEN'], $env['BALE_API_BASE'], 20);
            if (!$bot->isConfigured()) {
                return false;
            }
            $text = "🔐 <b>کد فعال‌سازی پنل مدیریت</b>\n\n"
                . "کد یکبار مصرف: <code>{$code}</code>\n"
                . "اعتبار: ۲۴ ساعت\n\n"
                . 'این کد فقط برای اولین ورود استفاده می‌شود و پس از مصرف باطل می‌گردد.';
            $res = $bot->sendMessage((string) $env['BALE_ADMIN_IDS'], $text);
            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warn('Activation code delivery failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @return list<string> */
    public static function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        $current = '';
        $inString = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            if ($inString !== null) {
                $current .= $char;
                if ($char === '\\') {
                    if ($i + 1 < $len) {
                        $current .= $sql[++$i];
                    }
                    continue;
                }
                if ($char === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $inString = $char;
                $current .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
