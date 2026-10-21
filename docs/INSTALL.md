# راهنمای نصب تفصیلی

<div dir="rtl">

این سند نصب روی سرور واقعی (Ubuntu/Debian + Nginx + PHP-FPM + MySQL) را گام‌به‌گام توضیح می‌دهد.

## ۱. آماده‌سازی سرور

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.2-fpm php8.2-mysql php8.2-curl \
                    php8.2-mbstring php8.2-xml php8.2-cli unzip git certbot python3-certbot-nginx
```

بررسی افزونه‌ها:

```bash
php -m | grep -E 'pdo_mysql|curl|openssl|mbstring|json'
```

## ۲. دریافت کد

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://github.com/USERNAME/bale-instagram-downloader.git
sudo chown -R www-data:www-data bale-instagram-downloader
cd bale-instagram-downloader
```

ساختار دسترسی‌ها:

```bash
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chmod -R 770 storage
sudo chmod 600 .env.example
```

## ۳. پایگاه داده

```bash
sudo mysql
```

```sql
CREATE DATABASE bale_ig CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bale_ig'@'localhost' IDENTIFIED BY 'A-Very-Strong-Password';
GRANT ALL PRIVILEGES ON bale_ig.* TO 'bale_ig'@'localhost';
FLUSH PRIVILEGES;
```

> اگر کاربر MySQL اجازه ساخت دیتابیس ندارد، همین دستورها را اجرا کنید و در نصب‌کننده
> فقط اطلاعات همان دیتابیس را وارد کنید.

## ۴. Nginx و SSL

```bash
sudo cp docs/nginx.example.conf /etc/nginx/sites-available/bale-ig
sudo ln -s /etc/nginx/sites-available/bale-ig /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d example.com -d www.example.com
```

DocumentRoot باید **`/var/www/bale-instagram-downloader/public`** باشد؛ نه ریشه پروژه.

## ۵. اجرای نصب‌کننده

مرورگر: `https://example.com/install.php`

| فیلد | مقدار |
|---|---|
| هاست/پورت دیتابیس | `127.0.0.1` / `3306` |
| نام دیتابیس | `bale_ig` |
| کاربر/رمز دیتابیس | `bale_ig` / همان رمز |
| نام کاربری مدیر | مثلاً `owner` |
| رمز عبور | حداقل ۱۰ کاراکتر، حرف + عدد |
| توکن ربات | از [tapi.bale.ai](https://tapi.bale.ai) |
| روش ارسال کد | «ارسال در بله» (پیشنهادی) |
| شناسه چت بله | شناسه عددی خودتان (با `/id` در ربات) |
| آدرس پنل | `https://example.com` |

پس از اتمام:

```bash
ls -l .env                # باید 0600 باشد
ls -l storage/installed.lock
```

**کد فعال‌سازی** نمایش‌داده‌شده را در جای امن نگه دارید؛ فقط یک‌بار قابل استفاده است.

## ۶. ربات

### روش polling (ساده)

```bash
sudo cp docs/bale-bot.service.example /etc/systemd/system/bale-bot.service
sudo nano /etc/systemd/system/bale-bot.service   # مسیرها را اصلاح کنید
sudo systemctl daemon-reload
sudo systemctl enable --now bale-bot
sudo journalctl -u bale-bot -f
```

### روش webhook

```bash
php cli/webhook.php set https://example.com/bot.php
php cli/webhook.php info
```

## ۷. کرون

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * /usr/bin/php /var/www/bale-instagram-downloader/cli/queue.php 40 >> /var/www/bale-instagram-downloader/storage/logs/cron.log 2>&1
```

## ۸. بررسی نهایی (چک‌لیست)

```bash
# ۱) اتصال ربات
php cli/console.php test:bot

# ۲) سرویس اینستاگرام
php cli/console.php test:instagram https://www.instagram.com/reel/XXXXXXXXX/

# ۳) وبهوک (اگر فعال است)
php cli/webhook.php info

# ۴) وضعیت
php cli/console.php stats
```

- [ ] `https://example.com/admin/` صفحه ورود را نشان می‌دهد
- [ ] ورود با رمز + کد یکبار مصرف انجام شد
- [ ] در ربات `/start` پاسخ می‌دهد و لینک ریلز دانلود می‌شود
- [ ] در پنل → داشبورد، دانلود جدید ثبت شده است
- [ ] `storage/otp` و `storage/tmp` قابل نوشتن و خالی هستند
- [ ] SSL فعال و `APP_URL` با `https` است

## ۹. به‌روزرسانی نسخه

```bash
cd /var/www/bale-instagram-downloader
sudo -u www-data git pull
sudo -u www-data php cli/console.php schema:apply   # افزودن جداول جدید (بی‌خطر)
sudo systemctl restart bale-bot
```

> `.env` و `storage/` تحت تأثیر قرار نمی‌گیرند. قبل از هر به‌روزرسانی از دیتابیس بکاپ بگیرید:
> `mysqldump -u bale_ig -p bale_ig > backup-$(date +%F).sql`

## ۱۰. بازگردانی به حالت نصب‌نشده (Reset)

```bash
sudo rm storage/installed.lock .env
# سپس مرورگر را روی /install.php باز کنید
```

داده‌های دیتابیس دست‌نخورده می‌مانند؛ برای حذف کامل:

```sql
DROP DATABASE bale_ig;
```

</div>
