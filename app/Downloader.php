<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Safe HTTPS downloader with SSRF protection, hard byte cap and timeout
 * that also covers slow bodies (the original Python version could hang).
 */
final class Downloader
{
    /**
     * @return array{ok:bool,path?:string,bytes?:int,error?:string}
     */
    public static function download(string $url, string $destPath, int $timeout = 60, int $maxBytes = 47185920): array
    {
        $reason = self::unsafeReason($url);
        if ($reason !== null) {
            Log::warn('Blocked unsafe media URL', ['reason' => $reason, 'host' => parse_url($url, PHP_URL_HOST)]);
            return ['ok' => false, 'error' => $reason];
        }
        $insecureAllowed = Config::bool('ALLOW_INSECURE_ENDPOINTS', false);

        $fp = @fopen($destPath, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'error' => 'امکان نوشتن فایل موقت وجود ندارد.'];
        }

        $ch = curl_init($url);
        $deadline = time() + $timeout;
        $written = 0;
        $tooLarge = false;

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => $insecureAllowed ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BaleIGDownloader/1.0)',
            CURLOPT_PROGRESSFUNCTION => static function ($res, $dlTotal, $dlNow) use (&$written, &$tooLarge, $maxBytes, $deadline) {
                $written = (int) $dlNow;
                if ($written > $maxBytes) {
                    $tooLarge = true;
                    return 1; // abort transfer
                }
                if (time() > $deadline) {
                    return 1;
                }
                return 0;
            },
            CURLOPT_NOPROGRESS => false,
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($tooLarge) {
            @unlink($destPath);
            return ['ok' => false, 'error' => 'حجم فایل بیش از حد مجاز است.'];
        }
        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($destPath);
            Log::warn('Media download failed', ['status' => $status, 'error' => $err]);
            return ['ok' => false, 'error' => 'دانلود فایل ناموفق بود.'];
        }
        $size = (int) (@filesize($destPath) ?: 0);
        if ($size === 0) {
            @unlink($destPath);
            return ['ok' => false, 'error' => 'فایل خالی دریافت شد.'];
        }
        return ['ok' => true, 'path' => $destPath, 'bytes' => $size];
    }

    /** @return array{ok:bool,bytes?:int,error?:string} */
    public static function fetchToMemory(string $url, int $timeout = 15, int $maxBytes = 5242880): array
    {
        $reason = self::unsafeReason($url);
        if ($reason !== null) {
            return ['ok' => false, 'error' => $reason];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => Config::bool('ALLOW_INSECURE_ENDPOINTS', false) ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BaleIGDownloader/1.0)',
            CURLOPT_MAXFILESIZE    => $maxBytes,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            return ['ok' => false, 'error' => 'دریافت فایل ناموفق بود.'];
        }
        return ['ok' => true, 'bytes' => strlen((string) $body)];
    }

    /**
     * SSRF guard: https only, no credentials, host must resolve to a public IP.
     */
    public static function unsafeReason(string $url): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'آدرس فایل نامعتبر است.';
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $insecureAllowed = Config::bool('ALLOW_INSECURE_ENDPOINTS', false);
        if ($scheme !== 'https') {
            $loopback = in_array($host, ['127.0.0.1', 'localhost'], true);
            if (!($insecureAllowed && $scheme === 'http' && $loopback)) {
                return 'فقط آدرس‌های HTTPS مجاز هستند.';
            }
        }
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'آدرس فایل نامعتبر است.';
        }
        $allowPrivate = Config::bool('ALLOW_PRIVATE_MEDIA_HOSTS', false) || $insecureAllowed;
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    } elseif (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
            if ($ips === []) {
                $v4 = @gethostbyname($host);
                if ($v4 !== $host) {
                    $ips[] = $v4;
                }
            }
        }
        if ($ips === []) {
            return 'دامنه فایل قابل شناسایی نیست.';
        }
        foreach ($ips as $ip) {
            if (!$allowPrivate && !self::isPublicIp($ip)) {
                return 'آدرس فایل به شبکه داخلی اشاره می‌کند.';
            }
        }
        return null;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Extra explicit ranges sometimes missed by the filter flags.
        $blocked = ['0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', '::1/128', 'fc00::/7', 'fe80::/10'];
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($blocked as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rem = (int) $bits % 8;
            if ($bytes > 0 && strncmp($bin, $netBin, $bytes) !== 0) {
                continue;
            }
            if ($rem === 0) {
                return false;
            }
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);
            if (($bin[$bytes] & $mask) === ($netBin[$bytes] & $mask)) {
                return false;
            }
        }
        return true;
    }

    public static function tmpFile(string $ext): string
    {
        $dir = APP_ROOT . '/storage/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'bin';
        return $dir . '/dl_' . bin2hex(random_bytes(8)) . '.' . substr($ext, 0, 5);
    }

    public static function cleanup(string ...$paths): void
    {
        foreach ($paths as $p) {
            if ($p !== '' && is_file($p)) {
                @unlink($p);
            }
        }
    }

    /** Remove leftovers older than an hour (run from cron). */
    public static function gcTmp(int $olderThanSeconds = 3600): int
    {
        $dir = APP_ROOT . '/storage/tmp';
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - $olderThanSeconds) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }
}
