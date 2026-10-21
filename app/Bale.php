<?php
declare(strict_types=1);

namespace App;

use CURLFile;
use RuntimeException;

/**
 * Minimal, dependency-free client for the Bale Bot API.
 * Bale mirrors the Telegram Bot API on https://tapi.bale.ai/bot<TOKEN>/<METHOD>.
 */
final class Bale
{
    private string $token;
    private string $base;
    private int $timeout;

    public function __construct(string $token, string $base = 'https://tapi.bale.ai', int $timeout = 30)
    {
        $this->token = trim($token);
        $this->base = rtrim($base, '/');
        $this->timeout = max(5, $timeout);
    }

    public static function fromSettings(): self
    {
        return new self(
            Settings::get('bot_token'),
            (string) Config::get('BALE_API_BASE', 'https://tapi.bale.ai'),
            Settings::int('bale_timeout', 30)
        );
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && preg_match('/^\d+:[A-Za-z0-9_\-]+$/', $this->token) === 1;
    }

    public function token(): string
    {
        return $this->token;
    }

    private function endpoint(string $method): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Bale bot token is not configured.');
        }
        return $this->base . '/bot' . $this->token . '/' . $method;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function call(string $method, array $params = [], int $timeout = 0): array
    {
        $url = $this->endpoint($method);
        $payload = $this->normalize($params);
        $hasFile = false;
        foreach ($payload as $value) {
            if ($value instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }

        $ch = curl_init($hasFile ? $url : $url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout > 0 ? $timeout : $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'BaleInstagramDownloader/' . APP_VERSION,
        ] + (Config::get('CURL_CA_BUNDLE') ? [CURLOPT_CAINFO => (string) Config::get('CURL_CA_BUNDLE')] : []);
        if ($hasFile) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new RuntimeException("Bale request failed ({$method}): {$error}");
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new RuntimeException("Bale returned a non-JSON response ({$method}, HTTP {$status})");
        }
        if (($json['ok'] ?? false) !== true) {
            $description = (string) ($json['description'] ?? 'unknown error');
            Log::warn('Bale API error', ['method' => $method, 'status' => $status, 'description' => $description]);
        }
        return $json;
    }

    /** @param array<string,mixed> $params */
    private function normalize(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /* --------------------------------------------------------------- methods */

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function getUpdates(int $offset = 0, int $timeout = 20, int $limit = 50): array
    {
        return $this->call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'limit'           => $limit,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + 15);
    }

    public function setWebhook(string $url, ?string $secret = null): array
    {
        return $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = 'HTML'): array
    {
        return $this->call('sendMessage', [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'reply_markup'             => $replyMarkup,
            'disable_web_page_preview' => true,
        ]);
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null, ?string $parseMode = 'HTML'): array
    {
        return $this->call('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => $replyMarkup,
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    /** @param array<string,mixed> $extra */
    public function sendPhoto(int|string $chatId, string $photo, ?string $caption = null, array $extra = []): array
    {
        return $this->sendMedia('sendPhoto', 'photo', $chatId, $photo, $caption, $extra);
    }

    public function sendVideo(int|string $chatId, string $video, ?string $caption = null, array $extra = []): array
    {
        return $this->sendMedia('sendVideo', 'video', $chatId, $video, $caption, $extra);
    }

    public function sendAudio(int|string $chatId, string $audio, ?string $caption = null, array $extra = []): array
    {
        return $this->sendMedia('sendAudio', 'audio', $chatId, $audio, $caption, $extra);
    }

    public function sendDocument(int|string $chatId, string $document, ?string $caption = null, array $extra = []): array
    {
        return $this->sendMedia('sendDocument', 'document', $chatId, $document, $caption, $extra);
    }

    /** @param array<string,mixed> $extra */
    private function sendMedia(string $method, string $field, int|string $chatId, string $source, ?string $caption, array $extra): array
    {
        $params = array_merge($extra, [
            'chat_id'    => $chatId,
            'caption'    => $caption,
            'parse_mode' => $caption !== null ? 'HTML' : null,
        ]);

        if (is_file($source) && is_readable($source)) {
            $mime = mime_content_type($source) ?: 'application/octet-stream';
            $params[$field] = new CURLFile($source, $mime, basename($source));
        } else {
            $params[$field] = $source; // URL or file_id
        }
        return $this->call($method, $params, 180);
    }
}
