<?php
/**
 * CLI helper.
 *
 *   php cli/console.php <command> [args]
 *
 * Commands:
 *   otp:last                 Show the latest locally-delivered panel OTP
 *   otp:new-activation       Create a new first-login activation code
 *   ip:allow [ip]            Show/append the panel IP allow-list
 *   admin:list               List admin accounts
 *   admin:unlock <username>  Remove lockout + failed attempts
 *   schema:apply             Create any missing tables (safe to re-run)
 *   stats                    Quick counters
 *   test:bot                 Call getMe with the stored bot token
 *   test:instagram <url>     Fetch a post through the provider
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Bale;
use App\Db;
use App\Downloads;
use App\Instagram;
use App\Installer;
use App\Otp;
use App\Settings;
use App\Users;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$command = $argv[1] ?? 'help';
$arg1 = $argv[2] ?? '';

$line = static fn(string $text = '') => fwrite(STDOUT, $text . PHP_EOL);
$fail = static function (string $text): never {
    fwrite(STDERR, "✖ {$text}" . PHP_EOL);
    exit(1);
};

if (!Installer::isInstalled()) {
    $fail('برنامه نصب نشده است. ابتدا /install.php را در مرورگر باز کنید.');
}

try {
    match ($command) {
        'otp:last' => (function () use ($line, $fail) {
            $file = APP_ROOT . '/storage/otp/otp.log';
            if (!is_file($file)) {
                $fail('فایل storage/otp/otp.log وجود ندارد (حالت ارسال بله فعال است یا هنوز کدی ساخته نشده).');
            }
            $rows = array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], -5);
            foreach ($rows as $row) {
                if (preg_match('/code=(\d+)/', $row, $m)) {
                    $line($row);
                }
            }
            $line();
            $line('کدها فقط یک‌بار قابل استفاده‌اند و پس از انقضا بی‌اعتبار می‌شوند.');
        })(),

        'otp:new-activation' => (function () use ($line) {
            $admins = Db::all('SELECT id, username FROM admins ORDER BY id ASC');
            if ($admins === []) {
                throw new RuntimeException('هیچ حساب مدیری وجود ندارد.');
            }
            $admin = $admins[0];
            $code = Otp::createActivationCode((int) $admin['id']);
            $line('کد فعال‌سازی برای کاربر «' . $admin['username'] . '»:');
            $line('  ' . $code['code']);
            $line('اعتبار تا: ' . $code['expires_at'] . ' (UTC)');
        })(),

        'ip:allow' => (function () use ($line, $arg1) {
            if ($arg1 === '') {
                $line('لیست فعلی: ' . (trim(Settings::get('ip_allowlist')) === '' ? '(خالی = همه IPها)' : Settings::get('ip_allowlist')));
                $line('برای افزودن: php cli/console.php ip:allow 203.0.113.10');
                return;
            }
            $current = trim(Settings::get('ip_allowlist'));
            $entries = $current === '' ? [] : preg_split('/\s+/', $current);
            if (!in_array($arg1, $entries, true)) {
                $entries[] = $arg1;
            }
            Settings::set('ip_allowlist', implode("\n", array_filter($entries)));
            $line('✅ افزوده شد. برای غیرفعال کردن محدودیت: php cli/console.php ip:allow --clear');
        })(),

        'admin:list' => (function () use ($line) {
            foreach (Db::all('SELECT id, username, role, is_active, last_login_at FROM admins ORDER BY id') as $row) {
                $line(sprintf(
                    '#%d  %-20s role=%-6s active=%d  last_login=%s',
                    $row['id'],
                    $row['username'],
                    $row['role'],
                    $row['is_active'],
                    $row['last_login_at'] ?? '—'
                ));
            }
        })(),

        'admin:unlock' => (function () use ($line, $arg1, $fail) {
            if ($arg1 === '') {
                $fail('نام کاربری را وارد کنید: php cli/console.php admin:unlock admin');
            }
            $affected = Db::run(
                'UPDATE admins SET failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE username = ?',
                [now_utc(), $arg1]
            )->rowCount();
            $line($affected > 0 ? '✅ قفل حساب برداشته شد.' : 'حسابی با این نام پیدا نشد.');
        })(),

        'schema:apply' => (function () use ($line) {
            $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
            foreach (Installer::splitSql($schema) as $statement) {
                Db::pdo()->exec($statement);
            }
            $line('✅ ساختار پایگاه داده به‌روزرسانی شد.');
        })(),

        'stats' => (function () use ($line) {
            $line('کاربران: ' . Users::count());
            $line('دانلودها: ' . Downloads::total());
            $line('امروز: ' . Downloads::countSince(gmdate('Y-m-d 00:00:00')));
            $line('نرخ موفقیت ۷ روز: ' . Downloads::successRate(gmdate('Y-m-d 00:00:00', time() - 7 * 86400)) . '%');
        })(),

        'test:bot' => (function () use ($line) {
            $bot = Bale::fromSettings();
            if (!$bot->isConfigured()) {
                throw new RuntimeException('توکن ربات تنظیم نشده است.');
            }
            $me = $bot->getMe();
            $line(($me['ok'] ?? false) ? '✅ @' . ($me['result']['username'] ?? '?') : '❌ ' . ($me['description'] ?? 'خطای نامشخص'));
        })(),

        'test:instagram' => (function () use ($line, $arg1, $fail) {
            if ($arg1 === '') {
                $fail('لینک پست را وارد کنید.');
            }
            $url = Instagram::normalizeUrl($arg1);
            if ($url === null || !Instagram::isPostUrl($url)) {
                $fail('لینک اینستاگرام معتبر نیست.');
            }
            $post = Instagram::fetch($url);
            if (!($post['ok'] ?? false)) {
                $fail((string) $post['error']);
            }
            $line('عنوان: ' . $post['title']);
            $line('نویسنده: ' . $post['author']);
            $line('فایل‌ها: ' . count($post['files']));
            foreach ($post['files'] as $i => $file) {
                $line(sprintf('  %d) %s %s %s', $i + 1, $file['type'], $file['quality'], $file['ext']));
            }
        })(),

        default => (function () use ($line) {
            $line('دستورهای موجود:');
            $line('  otp:last | otp:new-activation | ip:allow [ip] | admin:list | admin:unlock <user>');
            $line('  schema:apply | stats | test:bot | test:instagram <url>');
        })(),
    };
} catch (Throwable $e) {
    $fail($e->getMessage());
}
