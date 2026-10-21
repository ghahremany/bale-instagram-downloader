<?php
declare(strict_types=1);

namespace App;

final class RateLimiter
{
    /**
     * Register a hit for $bucket and tell whether the caller is still allowed.
     * The first hit counts immediately (fixed window).
     */
    public static function hit(string $bucket, int $max, int $windowSeconds): bool
    {
        $bucket = substr($bucket, 0, 120);
        $now = time();
        try {
            Db::run('DELETE FROM rate_limits WHERE expires_at < ?', [$now]);
            Db::run(
                'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$bucket, $now + $windowSeconds]
            );
            $hits = (int) Db::value('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
            return $hits <= $max;
        } catch (\Throwable $e) {
            Log::warn('Rate limiter unavailable: ' . $e->getMessage());
            return true; // fail-open: a DB hiccup must not lock the bot for everyone
        }
    }

    public static function clear(string $bucket): void
    {
        try {
            Db::run('DELETE FROM rate_limits WHERE bucket = ?', [substr($bucket, 0, 120)]);
        } catch (\Throwable) {
        }
    }

    public static function count(string $bucket): int
    {
        try {
            return (int) Db::value('SELECT hits FROM rate_limits WHERE bucket = ? AND expires_at >= ?', [substr($bucket, 0, 120), time()]);
        } catch (\Throwable) {
            return 0;
        }
    }
}
