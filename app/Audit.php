<?php
declare(strict_types=1);

namespace App;

final class Audit
{
    public static function log(string $action, string $details = '', ?int $adminId = null): void
    {
        try {
            Db::insert('audit_log', [
                'admin_id'   => $adminId ?? Auth::id(),
                'action'     => substr($action, 0, 64),
                'details'    => mb_substr($details, 0, 500),
                'ip'         => client_ip(),
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Log::warn('Audit log failed: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return Db::all("SELECT a.*, u.username FROM audit_log a
                        LEFT JOIN admins u ON u.id = a.admin_id
                        ORDER BY a.id DESC LIMIT {$limit}");
    }
}
