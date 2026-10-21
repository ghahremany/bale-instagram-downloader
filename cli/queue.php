<?php
/**
 * Cron worker: sends the next batch of a running broadcast and cleans temp files.
 *
 *   * * * * * /usr/bin/php /path/to/cli/queue.php 40 >> /path/to/storage/logs/cron.log 2>&1
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Broadcast;
use App\Downloader;
use App\Installer;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
if (!Installer::isInstalled()) {
    fwrite(STDERR, "نصب نشده است.\n");
    exit(1);
}

$batch = max(1, min(200, (int) ($argv[1] ?? 30)));
$result = Broadcast::run($batch);
Downloader::gcTmp(3600);

if (!isset($result['job'])) {
    exit(0);
}
printf(
    "[%s] job=%d sent=%d failed=%d done=%s\n",
    gmdate('c'),
    $result['job'],
    $result['sent'],
    $result['failed'],
    $result['done'] ? 'yes' : 'no'
);
