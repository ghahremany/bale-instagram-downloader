<?php
/**
 * Webhook helper.
 *
 *   php cli/webhook.php set [https://your-domain/bot.php]
 *   php cli/webhook.php delete
 *   php cli/webhook.php info
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Bale;
use App\Config;
use App\Installer;
use App\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
if (!Installer::isInstalled()) {
    fwrite(STDERR, "نصب نشده است.\n");
    exit(1);
}

$command = $argv[1] ?? 'info';
$bot = Bale::fromSettings();
if (!$bot->isConfigured()) {
    fwrite(STDERR, "توکن ربات تنظیم نشده است.\n");
    exit(1);
}

switch ($command) {
    case 'set':
        $secret = Settings::get('webhook_secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            Settings::set('webhook_secret', $secret);
        }
        $base = rtrim((string) ($argv[2] ?? Config::get('APP_URL', '')), '/');
        if ($base === '' || !preg_match('~^https://~i', $base)) {
            fwrite(STDERR, "APP_URL باید با https باشد (یا آدرس را به‌صورت آرگومان بدهید).\n");
            exit(1);
        }
        $url = str_ends_with($base, '/bot.php') ? $base : $base . '/bot.php';
        $res = $bot->setWebhook($url . '?secret=' . urlencode($secret), $secret);
        echo (($res['ok'] ?? false) ? "✅ webhook set: {$url}\n" : '❌ ' . ($res['description'] ?? 'failed') . "\n");
        break;

    case 'delete':
        $res = $bot->deleteWebhook();
        echo (($res['ok'] ?? false) ? "✅ webhook deleted\n" : '❌ failed' . "\n");
        break;

    default:
        $info = $bot->call('getWebhookInfo');
        echo json_encode($info['result'] ?? $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
