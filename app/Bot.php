<?php
declare(strict_types=1);

namespace App;

/**
 * Stateless router: one Bale update in, zero or more API calls out.
 * Works both for long polling (cli/poll.php) and for webhooks (public/bot.php).
 */
final class Bot
{
    private Bale $bot;

    public function __construct(?Bale $bot = null)
    {
        $this->bot = $bot ?? Bale::fromSettings();
    }

    public function handle(array $update): void
    {
        if (!Settings::bool('bot_enabled', true)) {
            return;
        }
        if (!$this->dedupe($update)) {
            return;
        }
        try {
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            } elseif (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled bot error: ' . $e->getMessage(), [
                'update_id' => $update['update_id'] ?? null,
                'trace'     => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
        }
    }

    private function dedupe(array $update): bool
    {
        $id = (int) ($update['update_id'] ?? 0);
        if ($id <= 0) {
            return true;
        }
        try {
            $stmt = Db::run('INSERT IGNORE INTO processed_updates (update_id, created_at) VALUES (?, ?)', [$id, now_utc()]);
            Db::run(
                'INSERT INTO bot_state (id, last_update_id, updated_at) VALUES (1, ?, ?)
                 ON DUPLICATE KEY UPDATE last_update_id = GREATEST(last_update_id, VALUES(last_update_id)), updated_at = VALUES(updated_at)',
                [$id, now_utc()]
            );
            return $stmt->rowCount() === 1;
        } catch (\Throwable $e) {
            Log::warn('Dedupe unavailable: ' . $e->getMessage());
            return true;
        }
    }

    private function handleCallback(array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');
        $from = $callback['from'] ?? [];
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($from['id'] ?? 0);

        Users::touch($from, $chatId);

        if ($data === 'check_membership') {
            if ($this->isMember($userId)) {
                Users::setJoined($userId, true);
                $this->bot->answerCallbackQuery($callbackId, '✅ عضویت تأیید شد.');
                if ($chatId !== 0 && !empty($message['message_id'])) {
                    $this->bot->editMessageText($chatId, (int) $message['message_id'], Messages::joinedSuccess());
                }
            } else {
                $this->bot->answerCallbackQuery($callbackId, Messages::notJoinedAlert(), true);
            }
            return;
        }
        $this->bot->answerCallbackQuery($callbackId, '');
    }

    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? [];
        $chat = $message['chat'] ?? [];
        $chatId = (int) ($chat['id'] ?? 0);
        $userId = (int) ($from['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === 0 || $userId === 0) {
            return;
        }

        $user = Users::touch($from, $chatId);
        if ($user !== null && (int) $user['is_blocked'] === 1) {
            if (RateLimiter::hit('blocked:' . $userId, 1, 300)) {
                $this->safeSend($chatId, Messages::blocked());
            }
            return;
        }

        if ($text === '') {
            if (RateLimiter::hit('nontext:' . $userId, 3, 120)) {
                $this->safeSend($chatId, 'فقط لینک اینستاگرام را بفرستید. برای راهنما /help را بزنید.');
            }
            return;
        }

        if (str_starts_with($text, '/')) {
            $command = strtolower(explode(' ', $text)[0]);
            $this->handleCommand($command, $chatId, $userId, $chat, $from);
            return;
        }

        /* Membership gate */
        if (Settings::bool('membership_gate_enabled', true) && !$this->isMember($userId)) {
            $this->sendJoinGate($chatId);
            return;
        }

        /* Per-user rate limit */
        $limit = max(1, Settings::int('rate_limit_per_hour', 30));
        if (!RateLimiter::hit('dl:u:' . $userId, $limit, 3600)) {
            $this->safeSend($chatId, Messages::rateLimited($limit));
            return;
        }

        $url = Instagram::normalizeUrl($text);
        if ($url === null || !Instagram::isPostUrl($url)) {
            $this->safeSend($chatId, Messages::invalidLink());
            return;
        }

        /* Processing */
        $notice = null;
        try {
            $notice = $this->bot->sendMessage($chatId, Messages::processing());
        } catch (\Throwable $e) {
            Log::warn('Cannot send processing notice: ' . $e->getMessage());
        }
        $noticeId = (int) ($notice['result']['message_id'] ?? 0);

        $post = Instagram::fetch($url);
        if (!($post['ok'] ?? false)) {
            $msg = Messages::fetchFailed((string) ($post['error'] ?? 'خطای نامشخص'));
            if ($noticeId > 0) {
                try {
                    $this->bot->editMessageText($chatId, $noticeId, $msg);
                    return;
                } catch (\Throwable) {
                }
            }
            $this->safeSend($chatId, $msg);
            return;
        }

        $post['source_url'] = $url;
        if ($noticeId > 0) {
            try {
                $this->bot->editMessageText($chatId, $noticeId, '🔥 در حال ارسال فایل‌ها…');
            } catch (\Throwable) {
            }
        }

        $summary = MediaProcessor::deliver($this->bot, $chatId, $post, $user ?? []);
        $support = trim(Settings::get('support_text'));

        if ($noticeId > 0) {
            try {
                if ($summary['status'] === 'success' || $summary['status'] === 'partial') {
                    $this->bot->deleteMessage($chatId, $noticeId);
                } else {
                    $this->bot->editMessageText($chatId, $noticeId, '⚠️ برخی فایل‌ها ارسال نشد؛ لینک مستقیم فرستاده شد.');
                }
            } catch (\Throwable) {
            }
        }

        if ($summary['status'] === 'failed' && $support !== '') {
            $this->safeSend($chatId, e($support));
        }
    }

    private function handleCommand(string $command, int $chatId, int $userId, array $chat, array $from): void
    {
        switch ($command) {
            case '/start':
                $markup = Channels::inlineMarkup(true);
                $isMember = $this->isMember($userId);
                $text = Messages::welcome((string) ($from['first_name'] ?? ''));
                if (!$isMember) {
                    $text .= "\n\n" . Messages::joinRequired();
                }
                $this->safeSend($chatId, $text, $markup);
                return;

            case '/help':
                $this->safeSend($chatId, Messages::help());
                return;

            case '/id':
                $this->safeSend($chatId, "🆔 شناسه کاربری شما:\n<code>" . e((string) $userId) . '</code>');
                return;

            case '/stats':
            case '/admin':
                if (!$this->isBotAdmin($userId)) {
                    $this->safeSend($chatId, '⛔️ این دستور مخصوص مدیر است.');
                    return;
                }
                $this->sendAdminSummary($chatId);
                return;

            default:
                $this->safeSend($chatId, "دستور ناشناخته است.\n/help را بزنید.");
        }
    }

    private function sendAdminSummary(int $chatId): void
    {
        $today = gmdate('Y-m-d 00:00:00');
        $panel = trim((string) Config::get('APP_URL', ''));
        $text = "⚙️ <b>خلاصه وضعیت</b>\n\n"
            . '👥 کاربران: ' . Users::count() . ' (امروز: ' . Users::countSince($today) . ")\n"
            . '📥 دانلود امروز: ' . Downloads::countSince($today) . "\n"
            . '✅ نرخ موفقیت ۲۴ ساعت: ' . Downloads::successRate(gmdate('Y-m-d 00:00:00', time() - 86400)) . "%\n";
        if ($panel !== '') {
            $text .= "\n🔗 پنل مدیریت: " . e($panel) . '/admin/';
        }
        $this->safeSend($chatId, $text);
    }

    private function sendJoinGate(int $chatId): void
    {
        $markup = Channels::inlineMarkup(true);
        if ($markup === null) {
            return; // gate enabled but no channels configured → don't block users
        }
        $this->safeSend($chatId, Messages::joinRequired(), $markup);
    }

    public function isMember(int $userId): bool
    {
        if (!Settings::bool('membership_gate_enabled', true)) {
            return true;
        }
        $channels = Channels::active();
        if ($channels === []) {
            return true;
        }
        $failOpen = Settings::bool('membership_gate_fail_open', false);
        foreach ($channels as $channel) {
            try {
                $res = $this->bot->getChatMember((string) $channel['chat_id'], $userId);
                if (($res['ok'] ?? false) !== true) {
                    if ($failOpen) {
                        continue;
                    }
                    return false;
                }
                $status = (string) ($res['result']['status'] ?? '');
                if (!in_array($status, ['member', 'administrator', 'creator'], true)) {
                    return false;
                }
            } catch (\Throwable $e) {
                Log::warn('Membership check failed: ' . $e->getMessage(), ['channel' => $channel['chat_id']]);
                if ($failOpen) {
                    continue;
                }
                return false;
            }
        }
        Users::setJoined($userId, true);
        return true;
    }

    private function isBotAdmin(int $userId): bool
    {
        $ids = parse_id_list((string) Config::get('BALE_ADMIN_IDS', ''));
        $ids = array_merge($ids, parse_id_list(Settings::get('bot_admin_ids')));
        return in_array($userId, $ids, true);
    }

    private function safeSend(int $chatId, string $text, ?array $markup = null): void
    {
        try {
            $this->bot->sendMessage($chatId, $text, $markup);
        } catch (\Throwable $e) {
            Log::warn('sendMessage failed: ' . $e->getMessage());
        }
    }
}
