<?php
declare(strict_types=1);

namespace App;


/**
 * Instagram URL validation + provider client (fast-creat compatible).
 *
 * The provider is never trusted blindly: only https URLs are accepted for
 * media, and the downloader additionally refuses private/reserved hosts.
 */
final class Instagram
{
    private const ALLOWED_HOSTS = [
        'instagram.com',
        'www.instagram.com',
        'm.instagram.com',
        'instagr.am',
        'www.instagr.am',
        'ddinstagram.com',
        'www.ddinstagram.com',
    ];

    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 2048) {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        return $url;
    }

    public static function shortcode(string $url): ?string
    {
        if (preg_match('~instagram\.com/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,})~i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function isPostUrl(string $url): bool
    {
        return self::normalizeUrl($url) !== null && self::shortcode($url) !== null;
    }

    /**
     * Fetch post metadata from the provider.
     *
     * @return array{ok:bool,error?:string,title?:string,author?:string,cover?:string,media_type?:string,files?:list<array<string,mixed>>,raw?:array<mixed>}
     */
    public static function fetch(string $url): array
    {
        $apiKey = trim(Settings::get('ig_api_key'));
        if ($apiKey === '') {
            $apiKey = trim((string) Config::get('IG_API_KEY', ''));
        }
        $apiUrl = trim(Settings::get('ig_api_url', (string) Config::get('IG_API_URL', 'https://api.fast-creat.ir/instagram')));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'کلید API اینستاگرام در پنل تنظیم نشده است.'];
        }
        $insecureAllowed = Config::bool('ALLOW_INSECURE_ENDPOINTS', false);
        if (!preg_match('~^https://~i', $apiUrl) && !($insecureAllowed && preg_match('~^http://(127\.0\.0\.1|localhost)~i', $apiUrl))) {
            return ['ok' => false, 'error' => 'آدرس API نامعتبر است (فقط https).'];
        }

        $mode = Settings::get('ig_provider_mode', 'post2');
        $timeout = max(5, min(60, Settings::int('ig_request_timeout', 30)));

        $ch = curl_init($apiUrl . '?' . http_build_query([
            'apikey' => $apiKey,
            'type'   => $mode,
            'url'    => $url,
        ]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'BaleInstagramDownloader/' . APP_VERSION,
        ] + (Config::get('CURL_CA_BUNDLE') ? [CURLOPT_CAINFO => (string) Config::get('CURL_CA_BUNDLE')] : []));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Log::warn('Instagram provider unreachable', ['error' => $err]);
            return ['ok' => false, 'error' => 'سرویس دریافت اطلاعات در دسترس نیست. کمی بعد تلاش کنید.'];
        }
        if ($status !== 200) {
            Log::warn('Instagram provider HTTP error', ['status' => $status]);
            return ['ok' => false, 'error' => "سرویس اطلاعات پاسخ {$status} داد."];
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'پاسخ سرویس اطلاعات قابل خواندن نبود.'];
        }
        $result = $json['result'] ?? null;
        if (!is_array($result) || ($json['ok'] ?? false) !== true || ($result['ok'] ?? false) !== true) {
            $providerError = (string) ($json['message'] ?? $json['error'] ?? '');
            Log::warn('Instagram provider reported failure', ['message' => mb_substr($providerError, 0, 200)]);
            return ['ok' => false, 'error' => 'این لینک قابل پردازش نیست. پست خصوصی یا حذف‌شده است.'];
        }

        $normalized = self::normalizeResult($result, $url);
        if ($normalized['files'] === []) {
            return ['ok' => false, 'error' => 'فایلی برای این پست پیدا نشد.'];
        }
        return $normalized;
    }

    /**
     * @param array<mixed> $result
     * @return array{ok:bool,title:string,author:string,cover:string,media_type:string,files:list<array<string,mixed>>,raw:array<mixed>}
     */
    private static function normalizeResult(array $result, string $url): array
    {
        $title = trim((string) ($result['title'] ?? $result['caption'] ?? ''));
        $title = mb_substr(preg_replace('/\s+/u', ' ', $title) ?? '', 0, 300);
        $author = trim((string) ($result['author'] ?? $result['username'] ?? $result['owner'] ?? ''));
        $author = ltrim(mb_substr($author, 0, 80), '@');
        $cover = (string) ($result['cover'] ?? $result['thumbnail'] ?? '');
        if ($cover !== '' && !self::acceptableMediaUrl($cover)) {
            $cover = '';
        }

        $rawFiles = $result['files'] ?? $result['medias'] ?? $result['items'] ?? [];
        $files = [];
        if (is_array($rawFiles)) {
            foreach ($rawFiles as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $fileUrl = (string) ($item['url'] ?? $item['link'] ?? $item['src'] ?? '');
                if (!self::acceptableMediaUrl($fileUrl)) {
                    continue;
                }
                $type = strtolower((string) ($item['type'] ?? $item['media_type'] ?? ''));
                $ext = strtolower((string) ($item['ext'] ?? $item['extension'] ?? ''));
                if ($type === '') {
                    $type = self::guessType($ext, $fileUrl);
                }
                $type = match (true) {
                    str_contains($type, 'audio'), str_contains($type, 'music') => 'audio',
                    str_contains($type, 'video'), str_contains($type, 'reel')  => 'video',
                    str_contains($type, 'image'), str_contains($type, 'photo') => 'photo',
                    default => 'document',
                };
                $files[] = [
                    'type'    => $type,
                    'url'     => $fileUrl,
                    'quality' => mb_substr((string) ($item['quality'] ?? $item['label'] ?? ''), 0, 40),
                    'ext'     => $ext !== '' ? preg_replace('/[^a-z0-9]/', '', $ext) : self::guessExt($type),
                    'size'    => (int) ($item['size'] ?? 0),
                ];
            }
        }

        // Keep a deterministic order: video → audio → photo → other
        $order = ['video' => 0, 'audio' => 1, 'photo' => 2, 'document' => 3];
        usort($files, static fn($a, $b) => ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9));

        $mediaType = 'photo';
        foreach ($files as $f) {
            if ($f['type'] === 'video') {
                $mediaType = count($files) > 1 ? 'carousel' : 'video';
                break;
            }
        }

        return [
            'ok'         => true,
            'title'      => $title !== '' ? $title : 'پست اینستاگرام',
            'author'     => $author !== '' ? $author : 'ناشناس',
            'cover'      => $cover,
            'media_type' => $mediaType,
            'files'      => array_values($files),
            'raw'        => $result,
        ];
    }

    /** Media URLs must be https; plain http only for loopback while testing. */
    public static function acceptableMediaUrl(string $url): bool
    {
        if (preg_match('~^https://~i', $url)) {
            return true;
        }
        $insecureAllowed = Config::bool('ALLOW_INSECURE_ENDPOINTS', false);
        return $insecureAllowed && preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?/~i', $url) === 1;
    }

    private static function guessType(string $ext, string $url): string
    {
        return match ($ext) {
            'mp3', 'm4a', 'aac', 'ogg', 'wav' => 'audio',
            'mp4', 'mkv', 'mov', 'webm'        => 'video',
            'jpg', 'jpeg', 'png', 'webp'       => 'photo',
            default => str_contains($url, '.mp4') ? 'video' : 'document',
        };
    }

    private static function guessExt(string $type): string
    {
        return match ($type) {
            'video' => 'mp4',
            'audio' => 'mp3',
            'photo' => 'jpg',
            default => 'bin',
        };
    }
}
