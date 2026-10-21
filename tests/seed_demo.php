<?php
/**
 * Seeds demo data so screenshots / staging environments look realistic.
 *   php tests/seed_demo.php
 * Safe to run on a demo database only.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Channels;
use App\Db;
use App\Downloads;
use App\Settings;
use App\Users;

Settings::set('bot_token', '123456:TESTTOKEN');
Settings::set('bot_username', 'TestDownloaderBot');
Settings::set('ig_api_key', 'demo-api-key-123456');
Settings::set('otp_delivery', 'file');
Settings::set('membership_gate_enabled', '1');
Settings::set('support_text', 'اگر لینکی مشکل داشت، پشتیبانی کنارته 💙');
Settings::flush();

Db::run('DELETE FROM downloads');
Db::run('DELETE FROM users');
Db::run('DELETE FROM channels');
Db::run('DELETE FROM broadcasts');
Db::run('DELETE FROM broadcast_targets');

Channels::create('Flormod', '-1002281216925', 'https://t.me/flormod_channel');
Channels::create('اخبار ربات', '-1001987654321', 'https://t.me/bale_ig_news');

$names = [
    [880011, 'sara_r', 'سارا', 'رضایی'],
    [880012, 'mmd_77', 'محمد', 'احمدی'],
    [880013, 'niloofar', 'نیلوفر', 'کریمی'],
    [880014, 'ali_dev', 'علی', 'محمدی'],
    [880015, 'zahra_art', 'زهرا', 'حسینی'],
    [880016, 'reza_ux', 'رضا', 'نوری'],
];

foreach ($names as $i => [$id, $username, $first, $last]) {
    Users::touch(['id' => $id, 'username' => $username, 'first_name' => $first, 'last_name' => $last], $id);
    Db::run(
        'UPDATE users SET created_at = ?, last_seen_at = ?, downloads_count = ?, is_joined = ? WHERE platform_user_id = ?',
        [
            gmdate('Y-m-d H:i:s', time() - (6 - $i) * 86400 - 3600),
            gmdate('Y-m-d H:i:s', time() - $i * 5400),
            random_int(3, 40),
            $i % 4 === 0 ? 0 : 1,
            $id,
        ]
    );
}

$statuses = ['success', 'success', 'success', 'partial', 'fallback', 'failed'];
for ($i = 0; $i < 90; $i++) {
    $dayOffset = (int) floor($i / 7);
    $user = $names[$i % count($names)];
    $status = $statuses[$i % count($statuses)];
    Downloads::record([
        'platform_user_id' => $user[0],
        'user_id'          => (int) (Db::value('SELECT id FROM users WHERE platform_user_id = ?', [$user[0]]) ?? 1),
        'url'              => 'https://www.instagram.com/reel/DEMO' . (1000 + $i) . '/',
        'shortcode'        => 'DEMO' . (1000 + $i),
        'media_type'       => $i % 3 === 0 ? 'carousel' : 'video',
        'status'           => $status,
        'files_total'      => random_int(1, 4),
        'files_sent'       => $status === 'failed' ? 0 : random_int(1, 3),
        'bytes'            => random_int(2, 42) * 1024 * 1024,
        'duration_ms'      => random_int(1800, 14000),
        'error_message'    => $status === 'failed' ? 'دانلود فایل ناموفق بود.' : null,
    ]);
    Db::run('UPDATE downloads SET created_at = ? WHERE id = LAST_INSERT_ID()', [
        gmdate('Y-m-d H:i:s', time() - $dayOffset * 86400 - random_int(600, 40000)),
    ]);
}

echo "demo data seeded: " . Users::count() . " users, " . Downloads::total() . " downloads\n";
