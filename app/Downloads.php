<?php
declare(strict_types=1);

namespace App;

final class Downloads
{
    public static function record(array $data): int
    {
        return Db::insert('downloads', [
            'user_id'          => $data['user_id'] ?? null,
            'platform_user_id' => $data['platform_user_id'] ?? null,
            'url'              => (string) ($data['url'] ?? ''),
            'shortcode'        => $data['shortcode'] ?? null,
            'media_type'       => $data['media_type'] ?? null,
            'status'           => (string) ($data['status'] ?? 'failed'),
            'files_total'      => (int) ($data['files_total'] ?? 0),
            'files_sent'       => (int) ($data['files_sent'] ?? 0),
            'bytes'            => (int) ($data['bytes'] ?? 0),
            'duration_ms'      => (int) ($data['duration_ms'] ?? 0),
            'error_message'    => isset($data['error_message']) ? mb_substr((string) $data['error_message'], 0, 500) : null,
            'created_at'       => now_utc(),
        ]);
    }

    public static function total(): int
    {
        return (int) (Db::value('SELECT COUNT(*) FROM downloads') ?? 0);
    }

    public static function countSince(string $sinceUtc): int
    {
        return (int) (Db::value('SELECT COUNT(*) FROM downloads WHERE created_at >= ?', [$sinceUtc]) ?? 0);
    }

    /** @return array<string,int> */
    public static function statusBreakdown(string $sinceUtc): array
    {
        $out = ['success' => 0, 'partial' => 0, 'fallback' => 0, 'failed' => 0];
        foreach (Db::all('SELECT status, COUNT(*) c FROM downloads WHERE created_at >= ? GROUP BY status', [$sinceUtc]) as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    public static function successRate(string $sinceUtc): float
    {
        $total = self::countSince($sinceUtc);
        if ($total === 0) {
            return 0.0;
        }
        $ok = (int) (Db::value(
            "SELECT COUNT(*) FROM downloads WHERE created_at >= ? AND status IN ('success','partial')",
            [$sinceUtc]
        ) ?? 0);
        return round($ok * 100 / $total, 1);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return Db::all("SELECT d.*, u.username FROM downloads d
                        LEFT JOIN users u ON u.id = d.user_id
                        ORDER BY d.id DESC LIMIT {$limit}");
    }

    /** Daily counts for the last N days: [['day'=>'2026-10-01','total'=>..], …] */
    public static function daily(int $days = 14): array
    {
        $days = max(2, min(60, $days));
        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $rows = Db::all(
            'SELECT DATE(created_at) d, COUNT(*) c, SUM(status IN ("success","partial")) ok
             FROM downloads WHERE created_at >= ? GROUP BY DATE(created_at)',
            [$from . ' 00:00:00']
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['d']] = ['total' => (int) $r['c'], 'ok' => (int) $r['ok']];
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = gmdate('Y-m-d', time() - ($days - 1 - $i) * 86400);
            $out[] = ['day' => $day, 'total' => $map[$day]['total'] ?? 0, 'ok' => $map[$day]['ok'] ?? 0];
        }
        return $out;
    }

    public static function purgeOlderThan(int $days): int
    {
        $stmt = Db::run('DELETE FROM downloads WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        return $stmt->rowCount();
    }
}
