# معماری و جریان داده

<div dir="rtl">

## نمای کلی

```
                ┌────────────────────────────┐
   کاربر بله ──▶│  Bale Bot API (tapi.bale.ai)│
                └──────────────┬─────────────┘
                               │ getUpdates (poll)  یا  POST /bot.php (webhook)
                     ┌─────────▼──────────┐
                     │   app/Bot.php      │  روتر آپدیت‌ها
                     └───┬───────────┬────┘
        بررسی عضویت      │           │  لینک معتبر؟
        (Channels/Bale)  │           ▼
                         │   app/Instagram.php ──▶ سرویس استخراج (fast-creat)
                         │           │ metadata (title, cover, files)
                         │           ▼
                         │   app/MediaProcessor.php
                         │           ├─ app/Downloader.php   دانلود امن HTTPS
                         │           ├─ app/Bale.php         sendVideo/sendAudio/…
                         │           └─ fallback: لینک مستقیم
                         ▼
                app/Downloads.php  ──▶ MySQL (downloads, users)
```

پنل مدیریت (`public/admin/index.php` → `App\Admin\Router`):
`Auth` (رمز + OTP) → `Csrf` → صفحه‌ی ماژول (`app/Admin/pages/*`) → `View` (layout RTL).

## لایه‌ها

| لایه | مسئولیت | فایل‌ها |
|---|---|---|
| Bootstrap | autoload، `.env`، مدیریت خطا، سشن | `app/bootstrap.php` |
| Infrastructure | DB، تنظیمات، لاگ، rate limit، audit | `Db`, `Settings`, `Log`, `RateLimiter`, `Audit` |
| Domain | ربات، اینستاگرام، دانلود، ارسال، همگانی | `Bot`, `Instagram`, `Downloader`, `MediaProcessor`, `Broadcast`, `Messages` |
| Presentation | پنل و نصب‌کننده | `Admin/*`, `public/install.php` |
| Entry points | CLI و HTTP | `public/*.php`, `cli/*.php` |

## جریان یک درخواست دانلود

1. آپدیت از بله می‌رسد (polling یا webhook).
2. `Bot::handle()` در `processed_updates` بررسی تکرار می‌کند (dedupe).
3. `Users::touch()` کاربر را ثبت/به‌روزرسانی می‌کند.
4. اگر مسدود است → پیام بلاک (حداکثر هر ۵ دقیقه یک‌بار).
5. اگر گیت عضویت فعال است → `getChatMember` برای هر کانال.
6. `RateLimiter::hit('dl:u:<id>', limit, 3600)`.
7. اعتبارسنجی لینک (`Instagram::normalizeUrl` + `shortcode`).
8. پیام «در حال دریافت…» ارسال و شناسه آن نگه داشته می‌شود.
9. `Instagram::fetch()` → metadata نرمال‌شده (`files[]` با نوع/کیفیت/پسوند).
10. `MediaProcessor::deliver()` برای هر فایل:
    - بررسی حجم اعلام‌شده و `Downloader::unsafeReason()` (SSRF)
    - دانلود به `storage/tmp` با سقف بایت و تایم‌اوت
    - ارسال با متد مناسب + کپشن escape شده
    - پاک‌سازی فایل (finally)
    - در خطا → جمع‌آوری لینک برای fallback
11. `Downloads::record()` + `users.downloads_count++`.
12. پیام «در حال…» حذف (موفق) یا ویرایش (خطا).

## مدل داده

| جدول | نقش |
|---|---|
| `users` | کاربران بله (یکتا روی `platform+platform_user_id`) |
| `downloads` | تاریخچه هر لینک با وضعیت/حجم/مدت/خطا |
| `broadcasts` / `broadcast_targets` | صف پایدار ارسال همگانی |
| `channels` | کانال‌های عضویت اجباری |
| `settings` | key/value تنظیمات (رازها رمزنگاری‌شده) |
| `admins` | حساب‌های پنل (نقش/قفل/آخرین ورود) |
| `setup_tokens` | کدهای فعال‌سازی یکبارمصرف (هش) |
| `login_attempts` | تاریخچه تلاش ورود (IP/نتیجه) |
| `rate_limits` | پنجره‌های ثابت محدودیت نرخ |
| `processed_updates` / `bot_state` | ضد تکرار و offset پولینگ |
| `audit_log` | گزارش فقط‌افزودنی اقدامات |

## تصمیم‌های طراحی

- **بدون فریم‌ورک**: پروژه باید روی هر هاست اشتراکی PHP با `public_html` هم اجرا شود؛ autoloader
  ساده کافی است و سطح حمله/وابستگی کم می‌ماند.
- **صف در دیتابیس به‌جای حلقه در حافظه**: ارسال همگانی با ری‌استارت سرور از بین نمی‌رود و
  پیشرفت واقعی در پنل دیده می‌شود.
- **Webhook و Polling هر دو**: یک `Bot` بدون حالت (stateless) هر دو مسیر را پوشش می‌دهد.
- **Fallback به لینک**: تجربه کاربر از «هیچ» بهتر است؛ سیاست ارسال فایل در بله سخت‌گیرانه است.
- **تنظیمات در DB نه .env**: مدیر می‌تواند بدون دسترسی به سرور رفتار ربات را تغییر دهد؛
  مقادیر حساس رمزنگاری می‌شوند.
- **Jalali در UI**: تاریخ‌ها با تبدیل داخلی (بدون نیاز به `intl`) به شمسی نمایش داده می‌شوند.

## توسعه

```bash
# افزودن صفحه جدید به پنل
touch app/Admin/pages/reports.php          # صفحه (متغیرهای $pageTitle/$active و خروجی HTML)
# سپس در Router::ROUTES و View::NAV اضافه کنید

# افزودن تنظیم جدید
# 1) در Settings::DEFAULTS مقدار پیش‌فرض
# 2) در app/Admin/pages/settings.php فیلد فرم + ذخیره با Settings::set()
```

قواعد کد: `declare(strict_types=1)`، type hint کامل، بدون وابستگی خارجی،
همه خروجی‌های HTML با `e()`، همه کوئری‌ها با پارامتر bind شده.

</div>
