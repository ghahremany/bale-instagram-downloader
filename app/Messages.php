<?php
declare(strict_types=1);

namespace App;

/**
 * All user-facing texts. Parse mode is HTML, so every dynamic value is escaped
 * here — user supplied names can never inject markup.
 */
final class Messages
{
    public static function welcome(string $firstName): string
    {
        $name = $firstName !== '' ? $firstName : 'دوست عزیز';
        $custom = trim(Settings::get('welcome_text'));
        if ($custom !== '') {
            // Plain text only: escaped, newlines preserved, {name} substituted.
            return nl2br(e(str_replace('{name}', $name, $custom)), false);
        }
        return "🎉 <b>خوش آمدید، " . e($name) . "</b>\n\n"
            . "من ربات دانلودر اینستاگرام هستم. کافی است لینک پست، ریلز یا IGTV را برایم بفرستید؛ "
            . "ویدیو، صدا و تصاویر را با بهترین کیفیت برایتان ارسال می‌کنم.\n\n"
            . "📌 <b>چطور کار می‌کنم؟</b>\n"
            . "۱. لینک اینستاگرام را بفرستید\n"
            . "۲. چند لحظه صبر کنید\n"
            . "۳. فایل‌ها را دریافت کنید\n\n"
            . "/help — راهنمای کامل";
    }

    public static function help(): string
    {
        $limit = Settings::int('rate_limit_per_hour', 30);
        $support = trim(Settings::get('support_text'));
        $text = "📚 <b>راهنمای استفاده</b>\n\n"
            . "یک لینک از اینستاگرام بفرستید؛ نمونه‌ها:\n"
            . "<code>https://www.instagram.com/reel/XXXXXXXXX/</code>\n"
            . "<code>https://www.instagram.com/p/XXXXXXXXX/</code>\n"
            . "<code>https://www.instagram.com/tv/XXXXXXXXX/</code>\n\n"
            . "• پست‌های چندتایی (کاروسل) هم پشتیبانی می‌شوند.\n"
            . "• اگر ارسال فایل ممکن نباشد، لینک مستقیم برایتان فرستاده می‌شود.\n"
            . "• سقف استفاده: {$limit} لینک در ساعت.\n\n"
            . "دستورها:\n"
            . "/start شروع • /help راهنما • /id شناسه شما";
        if ($support !== '') {
            $text .= "\n\n" . e($support);
        }
        return $text;
    }

    public static function joinRequired(): string
    {
        $channels = Channels::active();
        $text = "📣 <b>برای استفاده از ربات، ابتدا در کانال‌های زیر عضو شوید:</b>\n\n";
        foreach ($channels as $ch) {
            $text .= '• ' . e((string) $ch['title']) . "\n";
        }
        $text .= "\nپس از عضویت، دکمه «✅ عضو شدم» را بزنید.";
        return $text;
    }

    public static function notJoinedAlert(): string
    {
        return '❌ شما هنوز عضو همه کانال‌ها نشده‌اید!';
    }

    public static function joinedSuccess(): string
    {
        return "✅ <b>تأیید شد!</b>\n\nحالا می‌توانید لینک اینستاگرام بفرستید. 🚀";
    }

    public static function invalidLink(): string
    {
        return "❌ لطفاً یک لینک معتبر اینستاگرام بفرستید.\n"
            . "نمونه:\n<code>https://www.instagram.com/reel/XXXXXXXXX/</code>";
    }

    public static function rateLimited(int $limit): string
    {
        return "⏳ به سقف مجاز رسیده‌اید ({$limit} لینک در ساعت).\nلطفاً کمی بعد دوباره تلاش کنید.";
    }

    public static function blocked(): string
    {
        return '⛔️ دسترسی شما به ربات مسدود شده است.';
    }

    public static function processing(): string
    {
        return '⏳ در حال دریافت اطلاعات پست…';
    }

    public static function fetchFailed(string $reason): string
    {
        return "❌ <b>خطا در دریافت اطلاعات</b>\n\n" . e($reason);
    }

    public static function captionVideo(string $author, string $quality): string
    {
        $caption = '🎥 <b>ویدیو</b>' . "\n👤 " . e($author);
        return $quality !== '' ? $caption . "\n🎚 کیفیت: " . e($quality) : $caption;
    }

    public static function captionAudio(string $author, string $quality): string
    {
        $caption = '🎵 <b>صدا</b>' . "\n👤 " . e($author);
        return $quality !== '' ? $caption . "\n🎚 کیفیت: " . e($quality) : $caption;
    }

    public static function captionPhoto(string $author): string
    {
        return '🖼 <b>تصویر</b>' . "\n👤 " . e($author);
    }

    public static function captionDocument(string $author, string $ext): string
    {
        return '📎 <b>فایل</b> (' . e($ext) . ')' . "\n👤 " . e($author);
    }

    public static function captionCover(string $title, string $author): string
    {
        return '🖼 <b>' . e(mb_substr($title, 0, 120)) . "</b>\n👤 " . e($author);
    }

    public static function fallbackLinks(array $links): string
    {
        $text = "🔗 <b>لینک مستقیم فایل‌ها</b>\n\n";
        $text .= "ارسال فایل از طریق بله ممکن نشد. می‌توانید از لینک‌های زیر دانلود کنید:\n\n";
        foreach (array_slice($links, 0, 5) as $i => $link) {
            $text .= ($i + 1) . '. <a href="' . e($link) . '">دانلود فایل ' . ($i + 1) . "</a>\n";
        }
        return $text;
    }

    public static function cleanupNote(): void
    {
    }

    public static function aboutBot(): string
    {
        return AppName::title();
    }
}
