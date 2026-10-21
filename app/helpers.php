<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Global helper functions
|--------------------------------------------------------------------------
*/

/** HTML-escape any value for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function security_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    // Only trust proxy headers when the app explicitly runs behind a trusted proxy.
    if (App\Config::bool('TRUST_PROXY', false)) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }
    }
    return false;
}

/** Best-effort client IP (proxy headers only honoured when TRUST_PROXY=true). */
function client_ip(): string
{
    $candidates = ['REMOTE_ADDR'];
    if (App\Config::bool('TRUST_PROXY', false)) {
        array_unshift($candidates, 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP');
    }
    foreach ($candidates as $key) {
        $raw = $_SERVER[$key] ?? '';
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        foreach (explode(',', $raw) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function base_url(): string
{
    $configured = trim((string) App\Config::get('APP_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = security_is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?: 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if (str_ends_with($dir, '/public')) {
        $dir = substr($dir, 0, -7);
    }
    return $scheme . '://' . $host . ($dir === '/' ? '' : $dir);
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path, int $code = 302): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, $code);
    exit;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    $session = $_SESSION['_csrf'] ?? '';
    return is_string($token) && $session !== '' && hash_equals($session, $token);
}

/** @param array<string,mixed> $data */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function take_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function random_string(int $bytes = 32): string
{
    return bin2hex(random_bytes(max(8, $bytes)));
}

function human_bytes(int|float $bytes): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
}

/**
 * Gregorian date -> Jalali (Solar Hijri) date, no intl extension required.
 * @return array{0:int,1:int,2:int}
 */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd;
    for ($i = 0; $i < $gm - 1; $i++) {
        $days += $gDaysInMonth[$i];
    }
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/** Format a UTC datetime in the configured timezone, as a Jalali date. */
function format_dt(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $tz = new DateTimeZone((string) App\Config::get('APP_TIMEZONE', 'Asia/Tehran'));
        $local = $dt->setTimezone($tz);
        if (App\Config::get('DATE_STYLE', 'jalali') === 'gregorian') {
            return $local->format('Y-m-d H:i');
        }
        [$jy, $jm, $jd] = gregorian_to_jalali(
            (int) $local->format('Y'),
            (int) $local->format('n'),
            (int) $local->format('j')
        );
        return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $local->format('H:i'));
    } catch (Throwable) {
        return (string) $utc;
    }
}

/** Persian label for a stored media type. */
function media_label(?string $type): string
{
    return match ((string) $type) {
        'video'    => 'ویدیو',
        'photo'    => 'تصویر',
        'audio'    => 'صدا',
        'carousel' => 'چندتایی',
        'document' => 'فایل',
        'reel'     => 'ریلز',
        ''         => '—',
        default    => (string) $type,
    };
}

/** Mask a secret for display: keeps first/last 2 characters. */
function mask_secret(?string $secret): string
{
    $secret = (string) $secret;
    $len = strlen($secret);
    if ($len === 0) {
        return '—';
    }
    if ($len <= 6) {
        return str_repeat('•', $len);
    }
    return substr($secret, 0, 3) . str_repeat('•', 6) . substr($secret, -3);
}

/** @return array<string,mixed> */
function request_input(): array
{
    $data = $_POST;
    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ctype, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $data;
}

function abort(int $status, string $message = ''): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $title = match ($status) {
        400 => 'درخواست نامعتبر',
        401 => 'نیاز به ورود',
        403 => 'دسترسی ممنوع',
        404 => 'یافت نشد',
        405 => 'روش نامعتبر',
        419 => 'نشست منقضی شده',
        429 => 'درخواست بیش از حد',
        500 => 'خطای سرور',
        default => 'خطا',
    };
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<title>' . e($title) . '</title>'
        . '<body style="font-family:Tahoma,system-ui;background:#0b1020;color:#e8ecff;'
        . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
        . '<div style="text-align:center;max-width:520px;padding:32px">'
        . '<div style="font-size:64px;font-weight:700;color:#6d8cff">' . $status . '</div>'
        . '<h1 style="font-size:20px;margin:8px 0 12px">' . e($title) . '</h1>'
        . '<p style="color:#9aa4c7;line-height:1.9">' . e($message) . '</p>'
        . '<a href="' . e(url('admin/')) . '" style="display:inline-block;margin-top:18px;'
        . 'padding:10px 18px;border-radius:10px;background:#4b6bff;color:#fff;text-decoration:none">بازگشت</a>'
        . '</div></body></html>';
    exit;
}

/** Trim + normalise a numeric id coming from a form. */
function parse_id_list(?string $raw): array
{
    $ids = [];
    foreach (preg_split('/[\s,]+/', (string) $raw) ?: [] as $chunk) {
        $chunk = trim($chunk);
        if ($chunk !== '' && preg_match('/^-?\d+$/', $chunk)) {
            $ids[] = (int) $chunk;
        }
    }
    return array_values(array_unique($ids));
}
