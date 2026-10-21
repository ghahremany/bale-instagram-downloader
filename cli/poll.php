<?php
/**
 * Long-polling worker for Bale.
 *
 *   php cli/poll.php
 *
 * Systemd example:
 *   [Service]
 *   ExecStart=/usr/bin/php /var/www/bale-ig/cli/poll.php
 *   Restart=always
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Bale;
use App\Bot;
use App\Db;
use App\Installer;
use App\Log;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
if (!Installer::isInstalled()) {
    fwrite(STDERR, "نصب نشده است.\n");
    exit(1);
}

$bot = Bale::fromSettings();
if (!$bot->isConfigured()) {
    fwrite(STDERR, "توکن ربات تنظیم نشده است.\n");
    exit(1);
}

Db::run('INSERT IGNORE INTO bot_state (id, last_update_id, updated_at) VALUES (1, 0, ?)', [now_utc()]);

Log::info('Polling worker started', ['pid' => getmypid()]);
echo "🤖 polling started\n";

$router = new Bot($bot);
$offset = 0;

while (true) {
    try {
        $stateRow = Db::first('SELECT last_update_id FROM bot_state WHERE id = 1');
        $stored = (int) ($stateRow['last_update_id'] ?? 0);
        if ($offset <= $stored) {
            $offset = $stored + 1;
        }
        $response = $bot->getUpdates($offset, 25, 50);

        if (($response['ok'] ?? false) !== true) {
            Log::warn('getUpdates returned an error', ['description' => $response['description'] ?? '']);
            sleep(3);
            continue;
        }
        foreach ((array) ($response['result'] ?? []) as $update) {
            if (!is_array($update)) {
                continue;
            }
            $router->handle($update);
            $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
        }
    } catch (\Throwable $e) {
        Log::error('Polling error: ' . $e->getMessage());
        echo '❌ ' . $e->getMessage() . "\n";
        sleep(5);
    }
}
