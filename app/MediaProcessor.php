<?php
declare(strict_types=1);

namespace App;

/**
 * Turns provider metadata into actual Bale messages, with graceful fallbacks:
 *   file too big / download failed / send failed  →  direct links instead.
 */
final class MediaProcessor
{
    /**
     * @param array<string,mixed> $post normalised result from Instagram::fetch()
     * @return array{status:string,sent:int,fallback:int,bytes:int,files_total:int,error?:string}
     */
    public static function deliver(Bale $bot, int|string $chatId, array $post, array $user = []): array
    {
        $started = microtime(true);
        $maxFiles = max(1, min(35, Settings::int('max_files_per_post', 10)));
        $maxBytes = max(1, Settings::int('max_file_mb', 45)) * 1024 * 1024;
        $timeout = max(5, min(120, Settings::int('download_timeout', 60)));
        $allowAudio = Settings::bool('send_audio', true);
        $allowCover = Settings::bool('send_cover', true);
        $allowLinks = Settings::bool('fallback_to_links', true);

        $files = $post['files'] ?? [];
        $author = (string) ($post['author'] ?? 'ناشناس');
        $title = (string) ($post['title'] ?? '');
        $sent = 0;
        $fallback = 0;
        $bytes = 0;
        $errors = [];
        $links = [];
        $channelMarkup = Channels::inlineMarkup(false);

        if ($allowCover && !empty($post['cover']) && Downloader::unsafeReason((string) $post['cover']) === null) {
            try {
                $bot->sendPhoto($chatId, (string) $post['cover'], Messages::captionCover($title, $author), [
                    'reply_markup' => $channelMarkup,
                ]);
            } catch (\Throwable $e) {
                Log::info('Cover not sent: ' . $e->getMessage());
            }
        }

        foreach ($files as $file) {
            if ($sent + $fallback >= $maxFiles) {
                break;
            }
            $type = (string) ($file['type'] ?? 'document');
            if ($type === 'audio' && !$allowAudio) {
                continue;
            }
            $url = (string) ($file['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $quality = (string) ($file['quality'] ?? '');
            $ext = (string) ($file['ext'] ?? 'mp4');
            if (!empty($file['size']) && (int) $file['size'] > $maxBytes) {
                $links[] = $url;
                $fallback++;
                continue;
            }

            $path = Downloader::tmpFile($ext);
            try {
                $dl = Downloader::download($url, $path, $timeout, $maxBytes);
                if (!$dl['ok']) {
                    $errors[] = (string) ($dl['error'] ?? 'download failed');
                    $links[] = $url;
                    $fallback++;
                    continue;
                }
                $bytes += (int) ($dl['bytes'] ?? 0);

                $caption = match ($type) {
                    'video' => Messages::captionVideo($author, $quality),
                    'audio' => Messages::captionAudio($author, $quality),
                    'photo' => Messages::captionPhoto($author),
                    default => Messages::captionDocument($author, $ext),
                };
                if (!Settings::bool('send_caption', true)) {
                    $caption = null;
                }
                $extra = ['reply_markup' => $channelMarkup];

                try {
                    if ($type === 'video') {
                        $bot->sendVideo($chatId, $path, $caption, $extra + ['supports_streaming' => true]);
                    } elseif ($type === 'audio') {
                        $bot->sendAudio($chatId, $path, $caption, $extra + [
                            'title'     => mb_substr($title, 0, 60),
                            'performer' => mb_substr($author, 0, 60),
                        ]);
                    } elseif ($type === 'photo') {
                        $bot->sendPhoto($chatId, $path, $caption, $extra);
                    } else {
                        $bot->sendDocument($chatId, $path, $caption, $extra);
                    }
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warn('Sending media failed, falling back to link', ['error' => $e->getMessage()]);
                    $errors[] = 'send failed';
                    $links[] = $url;
                    $fallback++;
                }
            } finally {
                Downloader::cleanup($path);
            }
        }

        if ($fallback > 0 && $allowLinks && $links !== []) {
            try {
                $bot->sendMessage($chatId, Messages::fallbackLinks($links), Channels::inlineMarkup(false));
            } catch (\Throwable $e) {
                Log::warn('Fallback links not delivered: ' . $e->getMessage());
            }
        }

        $status = match (true) {
            $sent > 0 && $fallback === 0 => 'success',
            $sent > 0                     => 'partial',
            $fallback > 0                 => 'fallback',
            default                       => 'failed',
        };

        $summary = [
            'status'      => $status,
            'sent'        => $sent,
            'fallback'    => $fallback,
            'bytes'       => $bytes,
            'files_total' => count($files),
        ];
        if ($errors !== []) {
            $summary['error'] = implode(' | ', array_slice($errors, 0, 3));
        }

        self::track($post, $user, $summary, (int) round((microtime(true) - $started) * 1000));
        return $summary;
    }

    private static function track(array $post, array $user, array $summary, int $durationMs): void
    {
        try {
            $url = (string) ($post['source_url'] ?? '');
            Downloads::record([
                'user_id'          => $user['id'] ?? null,
                'platform_user_id' => $user['platform_user_id'] ?? null,
                'url'              => $url,
                'shortcode'        => $url !== '' ? Instagram::shortcode($url) : null,
                'media_type'       => $post['media_type'] ?? null,
                'status'           => $summary['status'],
                'files_total'      => $summary['files_total'],
                'files_sent'       => $summary['sent'] + $summary['fallback'],
                'bytes'            => $summary['bytes'],
                'duration_ms'      => $durationMs,
                'error_message'    => $summary['error'] ?? null,
            ]);
            if (!empty($user['platform_user_id'])) {
                Users::incrementDownloads((int) $user['platform_user_id']);
            }
        } catch (\Throwable $e) {
            Log::warn('Download tracking failed: ' . $e->getMessage());
        }
    }
}
