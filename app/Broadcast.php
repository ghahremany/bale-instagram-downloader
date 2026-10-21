<?php
declare(strict_types=1);

namespace App;

/**
 * Broadcast queue. Everything is persisted in MySQL so a crash or a restart
 * never loses a running campaign and the admin panel can show real progress.
 */
final class Broadcast
{
    /**
     * @param array{body?:string,media_type?:string,media_file_id?:string,button_text?:string,button_url?:string} $payload
     */
    public static function create(array $payload, ?int $adminId = null): int
    {
        $targets = Users::allPlatformIds();
        $id = Db::insert('broadcasts', [
            'admin_id'      => $adminId,
            'body'          => isset($payload['body']) ? mb_substr((string) $payload['body'], 0, 4096) : null,
            'media_type'    => $payload['media_type'] ?? null,
            'media_file_id' => isset($payload['media_file_id']) ? mb_substr((string) $payload['media_file_id'], 0, 255) : null,
            'button_text'   => isset($payload['button_text']) && $payload['button_text'] !== '' ? mb_substr((string) $payload['button_text'], 0, 64) : null,
            'button_url'    => isset($payload['button_url']) && $payload['button_url'] !== '' ? mb_substr((string) $payload['button_url'], 0, 500) : null,
            'status'        => 'queued',
            'total'         => count($targets),
            'created_at'    => now_utc(),
        ]);

        foreach (array_chunk($targets, 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $uid) {
                $values[] = '(?, ?, ?)';
                array_push($params, $id, $uid, 'pending');
            }
            Db::run(
                'INSERT INTO broadcast_targets (broadcast_id, platform_user_id, status) VALUES ' . implode(',', $values),
                $params
            );
        }

        Audit::log('broadcast_created', "broadcast #{$id} for " . count($targets) . ' users');
        return $id;
    }

    public static function find(int $id): ?array
    {
        return Db::first('SELECT * FROM broadcasts WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Db::all("SELECT * FROM broadcasts ORDER BY id DESC LIMIT {$limit}");
    }

    public static function cancel(int $id): void
    {
        Db::run("UPDATE broadcasts SET status = 'canceled', finished_at = ? WHERE id = ? AND status IN ('queued','running')", [now_utc(), $id]);
        Audit::log('broadcast_canceled', 'broadcast #' . $id);
    }

    /**
     * Send the next batch of pending targets. Called from cron.
     * @return array{sent:int,failed:int,done:bool,job?:int}
     */
    public static function run(int $batch = 25): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'done' => false];
        $job = Db::first("SELECT * FROM broadcasts WHERE status IN ('running','queued') ORDER BY id ASC LIMIT 1");
        if ($job === null) {
            return $result;
        }
        $jobId = (int) $job['id'];
        $result['job'] = $jobId;
        if ($job['status'] === 'queued') {
            Db::update('broadcasts', ['status' => 'running', 'started_at' => now_utc()], 'id = ?', [$jobId]);
        }

        $bot = Bale::fromSettings();
        if (!$bot->isConfigured()) {
            Db::update('broadcasts', ['status' => 'failed', 'finished_at' => now_utc()], 'id = ?', [$jobId]);
            return $result;
        }

        $markup = null;
        if (!empty($job['button_text']) && !empty($job['button_url'])) {
            $markup = ['inline_keyboard' => [[['text' => (string) $job['button_text'], 'url' => (string) $job['button_url']]]]];
        }

        $targets = Db::all(
            "SELECT id, platform_user_id FROM broadcast_targets
             WHERE broadcast_id = ? AND status = 'pending' ORDER BY id ASC LIMIT " . max(1, min(200, $batch)),
            [$jobId]
        );
        if ($targets === []) {
            Db::update('broadcasts', ['status' => 'done', 'finished_at' => now_utc()], 'id = ?', [$jobId]);
            $result['done'] = true;
            return $result;
        }

        foreach ($targets as $target) {
            $chatId = (int) $target['platform_user_id'];
            $ok = true;
            $error = null;
            try {
                self::deliver($bot, $chatId, $job, $markup);
            } catch (\Throwable $e) {
                $ok = false;
                $error = mb_substr($e->getMessage(), 0, 290);
            }
            Db::update('broadcast_targets', [
                'status'        => $ok ? 'sent' : 'failed',
                'error_message' => $error,
                'sent_at'       => now_utc(),
            ], 'id = ?', [(int) $target['id']]);
            $ok ? $result['sent']++ : $result['failed']++;
            usleep(60000); // ~16 msg/s ceiling, gentle on the API
        }

        Db::run(
            'UPDATE broadcasts SET sent = sent + ?, failed = failed + ? WHERE id = ?',
            [$result['sent'], $result['failed'], $jobId]
        );

        $pending = (int) (Db::value("SELECT COUNT(*) FROM broadcast_targets WHERE broadcast_id = ? AND status = 'pending'", [$jobId]) ?? 0);
        if ($pending === 0) {
            Db::update('broadcasts', ['status' => 'done', 'finished_at' => now_utc()], 'id = ?', [$jobId]);
            $result['done'] = true;
            Audit::log('broadcast_finished', "broadcast #{$jobId}");
        }
        return $result;
    }

    private static function deliver(Bale $bot, int $chatId, array $job, ?array $markup): void
    {
        $body = (string) ($job['body'] ?? '');
        $mediaType = (string) ($job['media_type'] ?? '');
        $fileId = (string) ($job['media_file_id'] ?? '');
        $extra = ['reply_markup' => $markup];

        if ($fileId !== '' && in_array($mediaType, ['photo', 'video', 'audio', 'document'], true)) {
            match ($mediaType) {
                'photo'    => $bot->sendPhoto($chatId, $fileId, $body !== '' ? $body : null, $extra),
                'video'    => $bot->sendVideo($chatId, $fileId, $body !== '' ? $body : null, $extra),
                'audio'    => $bot->sendAudio($chatId, $fileId, $body !== '' ? $body : null, $extra),
                default    => $bot->sendDocument($chatId, $fileId, $body !== '' ? $body : null, $extra),
            };
            return;
        }
        if ($body === '') {
            throw new \RuntimeException('empty broadcast body');
        }
        $bot->sendMessage($chatId, $body, $markup);
    }
}
