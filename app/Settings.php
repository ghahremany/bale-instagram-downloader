<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Key/value settings stored in MySQL.
 *
 * Secrets (admin panel login OTP destination, API keys, …) are encrypted with
 * AES-256-GCM under a key derived from APP_KEY, so a database dump alone does
 * not expose them. Values added later always fall back to a default.
 */
final class Settings
{
    private const SECRET_KEYS = [
        'otp_chat_id',
        'bot_token',
        'webhook_secret',
        'ig_api_key',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    private const DEFAULTS = [
        // bot
        'bot_token'                 => '',
        'bot_username'              => '',
        'bot_enabled'               => '1',
        'webhook_secret'            => '',
        // instagram provider
        'ig_api_key'                => '',
        'ig_api_url'                => 'https://api.fast-creat.ir/instagram',
        'ig_provider_mode'          => 'post2',
        'ig_request_timeout'        => '30',
        // membership gate
        'membership_gate_enabled'   => '1',
        'membership_gate_fail_open' => '0',
        // behaviour
        'download_timeout'          => '12',
        'max_file_mb'               => '45',
        'max_files_per_post'        => '10',
        'send_cover'                => '1',
        'send_audio'                => '1',
        'send_caption'              => '1',
        'fallback_to_links'         => '1',
        'rate_limit_per_hour'       => '30',
        'welcome_text'              => '',
        'support_text'              => 'اگر لینکی مشکل داشت، پشتیبانی کنارته 💙',
        'support_contact'           => '',
        // admin panel
        'panel_title'               => 'دانلودر اینستاگرام بله',
        'require_2fa'               => '1',
        'bot_admin_ids'             => '',
        'bale_timeout'              => '30',
        'session_epoch'             => '1',
        'otp_ttl_minutes'           => '15',
        'otp_delivery'              => 'bale',   // bale | file
        'otp_chat_id'               => '',
        'otp_last_request_at'       => '',
        'ip_allowlist'              => '',
        // meta
        'installed_at'              => '',
        'schema_version'            => '1',
    ];

    public static function get(string $key, ?string $default = null): string
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return (string) $all[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, (string) $default);
        return $v === '' ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default ? '1' : '0');
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value): void
    {
        $stored = in_array($key, self::SECRET_KEYS, true) && $value !== ''
            ? self::encrypt($value)
            : $value;

        Db::run(
            'INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
            [$key, $stored, now_utc()]
        );
        self::$cache = null;
    }

    /** @param array<string,string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set((string) $k, (string) $v);
        }
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = self::DEFAULTS;
        try {
            foreach (Db::all('SELECT `key`, `value` FROM settings') as $row) {
                $key = (string) $row['key'];
                $value = (string) ($row['value'] ?? '');
                if (in_array($key, self::SECRET_KEYS, true)) {
                    $value = self::decrypt($value);
                }
                $out[$key] = $value;
            }
        } catch (\Throwable $e) {
            Log::warn('Settings unavailable: ' . $e->getMessage());
        }
        return self::$cache = $out;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /* ---------------------------------------------------------------- crypto */

    private static function key(): string
    {
        $appKey = (string) Config::get('APP_KEY', '');
        if (strlen($appKey) < 16) {
            // Fall back to a machine-specific key so the panel still works, but
            // values become unreadable if APP_KEY changes. Installer prevents it.
            $appKey = hash('sha256', APP_ROOT . PHP_BINARY);
        }
        return hash('sha256', 'settings:' . $appKey, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'enc:v1:')) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /** Used by the installer to write a raw value without encryption. */
    public static function insertRaw(PDO $pdo, string $key, string $stored): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)'
        );
        $stmt->execute([$key, $stored, now_utc()]);
    }
}
