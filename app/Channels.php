<?php
declare(strict_types=1);

namespace App;

final class Channels
{
    /** @return list<array<string,mixed>> */
    public static function active(): array
    {
        try {
            return Db::all('SELECT * FROM channels WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Db::all('SELECT * FROM channels ORDER BY sort_order ASC, id ASC');
    }

    public static function create(string $title, string $chatId, string $link): int
    {
        return Db::insert('channels', [
            'title'      => mb_substr($title, 0, 120),
            'chat_id'    => mb_substr($chatId, 0, 64),
            'link'       => mb_substr($link, 0, 255),
            'is_active'  => 1,
            'sort_order' => (int) (Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM channels') ?? 1),
            'created_at' => now_utc(),
        ]);
    }

    public static function toggle(int $id): void
    {
        Db::run('UPDATE channels SET is_active = 1 - is_active WHERE id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::run('DELETE FROM channels WHERE id = ?', [$id]);
    }

    /**
     * Build the inline keyboard used by the join gate and by download captions.
     * @return array<string,mixed>|null
     */
    public static function inlineMarkup(bool $withCheckButton): ?array
    {
        $rows = [];
        foreach (self::active() as $ch) {
            $rows[] = [['text' => '📢 ' . (string) $ch['title'], 'url' => (string) $ch['link']]];
        }
        if ($withCheckButton) {
            $rows[] = [['text' => '✅ عضو شدم', 'callback_data' => 'check_membership']];
        }
        if ($rows === []) {
            return null;
        }
        return ['inline_keyboard' => $rows];
    }
}
