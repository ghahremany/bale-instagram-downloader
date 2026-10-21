<?php
declare(strict_types=1);

namespace App;

final class Users
{
    /** @param array<string,mixed> $from Bale "from" object */
    public static function touch(array $from, int|string|null $chatId = null): ?array
    {
        $platformId = (int) ($from['id'] ?? 0);
        if ($platformId === 0) {
            return null;
        }
        $username = isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : null;
        $firstName = isset($from['first_name']) ? mb_substr((string) $from['first_name'], 0, 128) : null;
        $lastName = isset($from['last_name']) ? mb_substr((string) $from['last_name'], 0, 128) : null;
        $chat = $chatId !== null ? (int) $chatId : null;

        Db::run(
            'INSERT INTO users (platform, platform_user_id, chat_id, username, first_name, last_name, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name),
                last_name = VALUES(last_name), chat_id = VALUES(chat_id), last_seen_at = VALUES(last_seen_at)',
            ['bale', $platformId, $chat, $username, $firstName, $lastName, now_utc(), now_utc()]
        );

        return Db::first('SELECT * FROM users WHERE platform = ? AND platform_user_id = ?', ['bale', $platformId]);
    }

    public static function find(int|string $platformId): ?array
    {
        return Db::first('SELECT * FROM users WHERE platform = ? AND platform_user_id = ?', ['bale', (int) $platformId]);
    }

    public static function isBlocked(int|string $platformId): bool
    {
        $row = Db::first('SELECT is_blocked FROM users WHERE platform_user_id = ?', [(int) $platformId]);
        return $row !== null && (int) $row['is_blocked'] === 1;
    }

    public static function setBlocked(int $id, bool $blocked): void
    {
        Db::update('users', ['is_blocked' => $blocked ? 1 : 0], 'id = ?', [$id]);
        Audit::log($blocked ? 'user_blocked' : 'user_unblocked', 'user #' . $id);
    }

    public static function setJoined(int|string $platformId, bool $joined): void
    {
        Db::run('UPDATE users SET is_joined = ? WHERE platform_user_id = ?', [$joined ? 1 : 0, (int) $platformId]);
    }

    public static function incrementDownloads(int|string $platformId): void
    {
        Db::run('UPDATE users SET downloads_count = downloads_count + 1 WHERE platform_user_id = ?', [(int) $platformId]);
    }

    public static function count(): int
    {
        return (int) (Db::value('SELECT COUNT(*) FROM users') ?? 0);
    }

    public static function countSince(string $sinceUtc): int
    {
        return (int) (Db::value('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$sinceUtc]) ?? 0);
    }

    public static function activeSince(string $sinceUtc): int
    {
        return (int) (Db::value('SELECT COUNT(*) FROM users WHERE last_seen_at >= ?', [$sinceUtc]) ?? 0);
    }

    public static function blockedCount(): int
    {
        return (int) (Db::value('SELECT COUNT(*) FROM users WHERE is_blocked = 1') ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public static function paginate(int $page, int $perPage, string $search = ''): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $perPage = max(1, min(200, $perPage));
        if ($search !== '') {
            $like = '%' . $search . '%';
            return Db::all(
                "SELECT * FROM users WHERE username LIKE ? OR first_name LIKE ? OR CAST(platform_user_id AS CHAR) LIKE ?
                 ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
                [$like, $like, $like]
            );
        }
        return Db::all("SELECT * FROM users ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
    }

    public static function countSearch(string $search = ''): int
    {
        if ($search === '') {
            return self::count();
        }
        $like = '%' . $search . '%';
        return (int) (Db::value(
            'SELECT COUNT(*) FROM users WHERE username LIKE ? OR first_name LIKE ? OR CAST(platform_user_id AS CHAR) LIKE ?',
            [$like, $like, $like]
        ) ?? 0);
    }

    /** @return list<int> */
    public static function allPlatformIds(): array
    {
        $rows = Db::all('SELECT platform_user_id FROM users WHERE is_blocked = 0');
        return array_map(static fn($r) => (int) $r['platform_user_id'], $rows);
    }
}
