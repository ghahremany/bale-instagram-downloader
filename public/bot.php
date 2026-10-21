<?php
/**
 * Bale webhook endpoint.
 *
 * Configure it from the panel (Tools → set webhook) or with:
 *   php cli/webhook.php set
 *
 * Security: HTTPS only, secret token comparison, timestamp-free replay is
 * impossible because every update_id is stored and deduplicated.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Bot;
use App\Log;
use App\RateLimiter;
use App\Settings;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$expected = Settings::get('webhook_secret') ?: (string) App\Config::get('BALE_WEBHOOK_SECRET', '');
if ($expected === '') {
    http_response_code(503);
    exit('webhook secret not configured');
}

$provided = (string) ($_SERVER['HTTP_X_BALE_API_SECRET_TOKEN'] ?? ($_GET['secret'] ?? ''));
if (!hash_equals($expected, $provided)) {
    http_response_code(401);
    Log::warn('Rejected webhook call with bad secret', ['ip' => client_ip()]);
    exit;
}

if (!RateLimiter::hit('webhook:' . client_ip(), 600, 60)) {
    http_response_code(429);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

/* Acknowledge immediately: Bale retries slow webhooks. */
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

try {
    (new Bot())->handle($update);
} catch (\Throwable $e) {
    Log::error('Webhook processing error: ' . $e->getMessage());
}
