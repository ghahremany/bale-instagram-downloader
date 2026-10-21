<?php
/**
 * Integration tests — run against the mock Bale + provider servers:
 *
 *   python3 tests/mock_servers.py --log /tmp/mock.jsonl &
 *   php tests/integration.php /tmp/mock.jsonl
 *
 * Uses a dedicated test database (TEST_DB_NAME, default bale_test) and does not
 * touch anything in production storage except the temp folder.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Bale;
use App\Bot;
use App\Broadcast;
use App\Channels;
use App\Config;
use App\Db;
use App\Downloader;
use App\Downloads;
use App\Instagram;
use App\Settings;
use App\Users;

$logPath = $argv[1] ?? '/tmp/mock.jsonl';
$failures = 0;
$checks = 0;

function check(string $label, bool $condition, string $extra = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  ✓ {$label}\n";
        return;
    }
    $failures++;
    echo "  ✗ {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
}

function mock_calls(string $logPath, ?string $method = null): array
{
    $rows = [];
    foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        if ($method === null || ($row['method'] ?? '') === $method) {
            $rows[] = $row;
        }
    }
    return $rows;
}

echo "== environment ==\n";
/* A fresh slate so repeated runs behave identically. */
Db::run('DELETE FROM channels');
Db::run('DELETE FROM processed_updates');
Db::run('DELETE FROM rate_limits');
Db::run('DELETE FROM downloads');
Db::run('DELETE FROM users');
$runBase = (int) (microtime(true) * 1000); // fake update ids unique per run
$uid = static fn(int $n): int => $runBase + $n;
Config::set('ALLOW_INSECURE_ENDPOINTS', 'true');
Settings::set('ig_api_url', 'http://127.0.0.1:9098/instagram');
Settings::set('ig_api_key', 'test-key-123456');
Settings::set('ig_provider_mode', 'post2');
Settings::set('membership_gate_enabled', '0');
Settings::set('download_timeout', '10');
Settings::set('max_file_mb', '5');
Settings::set('rate_limit_per_hour', '100');
Settings::set('send_cover', '1');
Settings::set('send_audio', '1');
Settings::set('fallback_to_links', '1');
Settings::flush();

Config::set('BALE_API_BASE', 'http://127.0.0.1:9099');
Settings::set('bot_token', '123456:TESTTOKEN');
Settings::flush();
$bot = new Bale('123456:TESTTOKEN', 'http://127.0.0.1:9099', 10);
$router = new Bot($bot);

/* ---------------------------------------------------------------- URL rules */
echo "\n== URL validation ==\n";
check('reel URL accepted', Instagram::isPostUrl('https://www.instagram.com/reel/AbC123xyz/'));
check('post URL accepted', Instagram::isPostUrl('https://instagram.com/p/AbC123xyz'));
check('evil host rejected', !Instagram::isPostUrl('https://instagram.com.evil.tld/p/AbC123xyz'));
check('javascript scheme rejected', Instagram::normalizeUrl('javascript:alert(1)') === null);
check('profile URL rejected (not a post)', !Instagram::isPostUrl('https://www.instagram.com/someone/'));
check('shortcode parsed', Instagram::shortcode('https://www.instagram.com/reel/AbC123xyz/') === 'AbC123xyz');

/* ------------------------------------------------------------- SSRF defence */
echo "\n== downloader safety ==\n";
Config::set('ALLOW_INSECURE_ENDPOINTS', 'false');
check('private IP blocked', Downloader::unsafeReason('https://127.0.0.1/file.mp4') !== null);
check('localhost blocked', Downloader::unsafeReason('https://localhost/file.mp4') !== null);
check('http blocked', Downloader::unsafeReason('http://example.com/file.mp4') !== null);
check('public https allowed', Downloader::unsafeReason('https://example.com/file.mp4') === null);
Config::set('ALLOW_INSECURE_ENDPOINTS', 'true');

/* -------------------------------------------------------- provider metadata */
echo "\n== provider ==\n";
$post = Instagram::fetch('https://www.instagram.com/reel/AbC123xyz/');
check('provider ok', ($post['ok'] ?? false) === true, (string) ($post['error'] ?? ''));
check('two files parsed', count($post['files'] ?? []) === 2);
check('video first', ($post['files'][0]['type'] ?? '') === 'video');
check('author parsed', ($post['author'] ?? '') === 'test_account');

/* ------------------------------------------------------------------- happy path */
echo "\n== full download flow ==\n";
$userId = 555000111;
$update = [
    'update_id' => $uid(1),
    'message' => [
        'message_id' => 1,
        'from' => ['id' => $userId, 'first_name' => 'کاربر', 'username' => 'tester'],
        'chat' => ['id' => $userId, 'type' => 'private'],
        'text' => 'https://www.instagram.com/reel/AbC123xyz/',
    ],
];
file_put_contents($logPath, '');
$router->handle($update);

$calls = mock_calls($logPath);
$methods = array_column($calls, 'method');
check('processing notice sent', in_array('sendMessage', $methods, true));
check('video delivered', in_array('sendVideo', $methods, true));
check('audio delivered', in_array('sendAudio', $methods, true));
check('cover delivered', in_array('sendPhoto', $methods, true));
check('processing notice cleaned up', in_array('deleteMessage', $methods, true) || in_array('editMessageText', $methods, true));

$user = Users::find($userId);
check('user stored', $user !== null);
$last = Db::first('SELECT * FROM downloads ORDER BY id DESC LIMIT 1');
check('download recorded as success', ($last['status'] ?? '') === 'success', (string) ($last['status'] ?? ''));
check('bytes counted', (int) ($last['bytes'] ?? 0) > 0);

echo "\n== duplicate update is ignored ==\n";
file_put_contents($logPath, '');
$router->handle($update);
check('no API call on duplicate update_id', mock_calls($logPath) === []);

/* --------------------------------------------------------------- error paths */
echo "\n== provider failure ==\n";
file_put_contents($logPath, '');
$router->handle([
    'update_id' => $uid(2),
    'message' => [
        'message_id' => 2,
        'from' => ['id' => $userId, 'first_name' => 'کاربر'],
        'chat' => ['id' => $userId],
        'text' => 'https://www.instagram.com/p/failpost1/',
    ],
]);
$texts = array_map(static fn($c) => (string) ($c['params']['text'] ?? ''), mock_calls($logPath, 'sendMessage'));
$edited = array_map(static fn($c) => (string) ($c['params']['text'] ?? ''), mock_calls($logPath, 'editMessageText'));
check('user told about failure', str_contains(implode(' ', array_merge($texts, $edited)), 'خطا'));

echo "\n== invalid link ==\n";
file_put_contents($logPath, '');
$router->handle([
    'update_id' => $uid(3),
    'message' => [
        'message_id' => 3,
        'from' => ['id' => $userId],
        'chat' => ['id' => $userId],
        'text' => 'https://example.com/not-instagram',
    ],
]);
$texts = array_map(static fn($c) => (string) ($c['params']['text'] ?? ''), mock_calls($logPath, 'sendMessage'));
check('invalid link rejected', str_contains(implode(' ', $texts), 'اینستاگرام'));

/* ------------------------------------------------------------ membership gate */
echo "\n== membership gate ==\n";
Settings::set('membership_gate_enabled', '1');
Settings::flush();
Channels::create('کانال تست', '-1001234567890', 'https://t.me/test_channel');
file_put_contents($logPath, '');
$router->handle([
    'update_id' => $uid(4),
    'message' => [
        'message_id' => 4,
        'from' => ['id' => $userId],
        'chat' => ['id' => $userId],
        'text' => 'https://www.instagram.com/reel/AbC123xyz/',
    ],
]);
$memberCalls = mock_calls($logPath, 'getChatMember');
check('membership checked', $memberCalls !== []);
Settings::set('membership_gate_enabled', '0');
Settings::flush();

echo "\n== callback: check_membership ==\n";
file_put_contents($logPath, '');
$router->handle([
    'update_id' => $uid(5),
    'callback_query' => [
        'id' => 'cb-1',
        'from' => ['id' => $userId],
        'message' => ['message_id' => 77, 'chat' => ['id' => $userId]],
        'data' => 'check_membership',
    ],
]);
check('callback answered', mock_calls($logPath, 'answerCallbackQuery') !== []);

/* ---------------------------------------------------------------- broadcast */
echo "\n== broadcast queue ==\n";
$jobId = Broadcast::create(['body' => 'سلام از تست همگانی'], null);
$result = Broadcast::run(50);
check('queue processed', ($result['sent'] ?? 0) >= 1, 'sent=' . ($result['sent'] ?? 0));
$job = Broadcast::find($jobId);
check('job finished', ($job['status'] ?? '') === 'done');

/* -------------------------------------------------------------- rate limiting */
echo "\n== rate limit ==\n";
Settings::set('rate_limit_per_hour', '2');
Settings::flush();
\App\RateLimiter::clear('dl:u:' . $userId);
file_put_contents($logPath, '');
for ($i = 0; $i < 3; $i++) {
    $router->handle([
        'update_id' => $uid(100 + $i),
        'message' => [
            'message_id' => 10 + $i,
            'from' => ['id' => $userId],
            'chat' => ['id' => $userId],
            'text' => 'https://www.instagram.com/reel/AbC123xyz/',
        ],
    ]);
}
$texts = array_map(static fn($c) => (string) ($c['params']['text'] ?? ''), mock_calls($logPath, 'sendMessage'));
$edited = array_map(static fn($c) => (string) ($c['params']['text'] ?? ''), mock_calls($logPath, 'editMessageText'));
check('third request hitting the limit', str_contains(implode(' ', array_merge($texts, $edited)), 'سقف'));

echo "\n----------------------------------------\n";
echo $failures === 0
    ? "ALL {$checks} CHECKS PASSED ✅\n"
    : "{$failures} of {$checks} checks FAILED ❌\n";
exit($failures === 0 ? 0 : 1);
