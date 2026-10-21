# Contributing

<div dir="rtl">

از مشارکت شما خوشحالیم! لطفاً این موارد را رعایت کنید:

1. **شاخه بسازید**: `feature/xyz` یا `fix/xyz`
2. **استاندارد کد**: PHP 8.1+، `declare(strict_types=1)`, type hint کامل، توابع کوتاه
3. **بدون وابستگی جدید** (Composer/npm) مگر با دلیل قوی و توضیح در PR
4. **امنیت**: هر خروجی HTML با `e()` و هر کوئری با prepared statement
5. **تست**: پیش از PR این‌ها را اجرا کنید

```bash
# lint همه فایل‌ها
find app public cli -name '*.php' -print0 | xargs -0 -n1 php -l

# تست یکپارچه
python3 tests/mock_servers.py --log /tmp/mock.jsonl &
php tests/integration.php /tmp/mock.jsonl

# تست دود پنل
BASE=http://127.0.0.1:8080 ./tests/smoke.sh
```

6. **کامیت‌ها** به انگلیسی و imperative: `Fix OTP expiry`, `Add broadcast retry`
7. برای تغییرات ظاهری، اسکرین‌شات بگیرید (`python3 tests/screenshots.py`)
8. برای تغییر رفتار ربات، README و `docs/` را به‌روزرسانی کنید

</div>
