<?php
declare(strict_types=1);

use App\Admin\Router;
use App\Admin\View;
use App\Audit;
use App\Settings;

$pageTitle = 'تنظیمات';
$active = 'settings';

$tabs = [
    'bot'       => 'ربات بله',
    'instagram' => 'سرویس اینستاگرام',
    'behavior'  => 'رفتار دانلود',
    'panel'     => 'پنل و امنیت',
    'texts'     => 'متن‌ها',
];
$tab = (string) ($_GET['tab'] ?? 'bot');
if (!isset($tabs[$tab])) {
    $tab = 'bot';
}
$ownerOnlyTabs = ['panel'];
if (in_array($tab, $ownerOnlyTabs, true)) {
    Router::requireOwner();
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $section = (string) ($_POST['section'] ?? '');
    $save = static function (string $key, string $value, string $type = 'text'): void {
        Settings::set($key, $value);
    };
    $bool = static fn(string $key): string => isset($_POST[$key]) ? '1' : '0';

    if ($section === 'bot') {
        $token = trim((string) ($_POST['bot_token'] ?? ''));
        if ($token !== '' && !preg_match('/^\d{3,}:[A-Za-z0-9_\-]{10,}$/', $token)) {
            $errors[] = 'قالب توکن بله صحیح نیست.';
        } else {
            if ($token !== '') {
                $save('bot_token', $token);
                try {
                    $bot = \App\Bale::fromSettings();
                    $me = $bot->getMe();
                    if (($me['ok'] ?? false) === true) {
                        $save('bot_username', (string) ($me['result']['username'] ?? ''));
                        flash('ok', 'توکن ذخیره و با موفقیت تایید شد: @' . e((string) ($me['result']['username'] ?? '')));
                    } else {
                        flash('warn', 'توکن ذخیره شد اما تایید آن ناموفق بود.');
                    }
                } catch (\Throwable $e) {
                    flash('warn', 'توکن ذخیره شد اما اتصال به بله برقرار نشد: ' . e($e->getMessage()));
                }
            }
            $save('bot_enabled', $bool('bot_enabled'));
            $save('bot_admin_ids', trim((string) ($_POST['bot_admin_ids'] ?? '')));
            $save('bale_timeout', (string) max(5, min(120, (int) ($_POST['bale_timeout'] ?? 30))));
            Audit::log('settings_bot_updated', 'bot settings changed');
            if ($errors === []) {
                flash('ok', 'تنظیمات ربات ذخیره شد.');
            }
        }
    } elseif ($section === 'instagram') {
        $key = trim((string) ($_POST['ig_api_key'] ?? ''));
        $url = trim((string) ($_POST['ig_api_url'] ?? ''));
        if ($key !== '') {
            if (strlen($key) < 6) {
                $errors[] = 'کلید API بسیار کوتاه است.';
            } else {
                $save('ig_api_key', $key);
            }
        }
        if (!preg_match('~^https://~i', $url)) {
            $errors[] = 'آدرس API باید با https شروع شود.';
        } else {
            $save('ig_api_url', $url);
        }
        $save('ig_provider_mode', preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['ig_provider_mode'] ?? 'post2'))) ?: 'post2');
        $save('ig_request_timeout', (string) max(5, min(60, (int) ($_POST['ig_request_timeout'] ?? 30))));
        Audit::log('settings_instagram_updated', 'instagram provider settings changed');
        if ($errors === []) {
            flash('ok', 'تنظیمات سرویس اینستاگرام ذخیره شد.');
        }
    } elseif ($section === 'behavior') {
        $save('download_timeout', (string) max(5, min(180, (int) ($_POST['download_timeout'] ?? 60))));
        $save('max_file_mb', (string) max(1, min(200, (int) ($_POST['max_file_mb'] ?? 45))));
        $save('max_files_per_post', (string) max(1, min(35, (int) ($_POST['max_files_per_post'] ?? 10))));
        $save('rate_limit_per_hour', (string) max(1, min(1000, (int) ($_POST['rate_limit_per_hour'] ?? 30))));
        foreach (['send_cover', 'send_audio', 'send_caption', 'fallback_to_links'] as $flag) {
            $save($flag, $bool($flag));
        }
        Audit::log('settings_behavior_updated', 'download behaviour changed');
        flash('ok', 'تنظیمات رفتار دانلود ذخیره شد.');
    } elseif ($section === 'panel') {
        $save('panel_title', mb_substr(trim((string) ($_POST['panel_title'] ?? '')), 0, 120));
        $delivery = in_array($_POST['otp_delivery'] ?? '', ['bale', 'file'], true) ? (string) $_POST['otp_delivery'] : 'bale';
        $save('otp_delivery', $delivery);
        $chatId = trim((string) ($_POST['otp_chat_id'] ?? ''));
        if ($chatId !== '' && !preg_match('/^-?\d{4,20}$/', $chatId)) {
            $errors[] = 'شناسه چت باید عددی باشد.';
        } elseif ($chatId !== '') {
            $save('otp_chat_id', $chatId);
        }
        $save('otp_ttl_minutes', (string) max(2, min(60, (int) ($_POST['otp_ttl_minutes'] ?? 10))));
        $save('require_2fa', $bool('require_2fa'));
        $allow = trim((string) ($_POST['ip_allowlist'] ?? ''));
        $lines = [];
        foreach (preg_split('/[\s,]+/', $allow) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!filter_var($entry, FILTER_VALIDATE_IP) && !preg_match('~^[0-9a-fA-F:.]+/\d{1,3}$~', $entry)) {
                $errors[] = 'ورودی لیست IP نامعتبر است: ' . $entry;
                continue;
            }
            $lines[] = $entry;
        }
        $save('ip_allowlist', implode("\n", $lines));
        Audit::log('settings_panel_updated', 'panel/security settings changed');
        if ($errors === []) {
            flash('ok', 'تنظیمات پنل ذخیره شد.' . ($lines !== [] ? ' توجه: از این پس فقط IP‌های مجاز می‌توانند وارد شوند.' : ''));
        }
    } elseif ($section === 'texts') {
        $save('welcome_text', mb_substr(trim((string) ($_POST['welcome_text'] ?? '')), 0, 2000));
        $save('support_text', mb_substr(trim((string) ($_POST['support_text'] ?? '')), 0, 500));
        $save('support_contact', mb_substr(trim((string) ($_POST['support_contact'] ?? '')), 0, 120));
        Audit::log('settings_texts_updated', 'user facing texts changed');
        flash('ok', 'متن‌ها ذخیره شد.');
    }

    if ($errors !== []) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
    }
    redirect('admin/?r=settings&tab=' . urlencode((string) ($_GET['tab'] ?? 'bot')));
}

$s = static fn(string $key, string $default = ''): string => Settings::get($key, $default);
$hasSecret = static function (string $key) use ($s): bool {
    return trim($s($key)) !== '';
};
$botConfigured = \App\Bale::fromSettings()->isConfigured();
?>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'active' : '' ?>" href="<?= e(url('admin/?r=settings&tab=' . $key)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'bot'): ?>
<div class="card">
  <div class="card-head">
    <h2>ربات بله</h2>
    <?= $botConfigured
        ? '<span class="badge badge-ok">توکن تنظیم شده</span>'
        : '<span class="badge badge-bad">توکن تنظیم نشده</span>' ?>
  </div>
  <form method="post" action="<?= e(url('admin/?r=settings&tab=bot')) ?>" data-busy autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="bot">

    <label>توکن ربات
      <input type="password" name="bot_token" dir="ltr"
             placeholder="<?= $hasSecret('bot_token') ? e(mask_secret($s('bot_token'))) . ' — برای تغییر مقدار جدید وارد کنید' : '123456789:AA…' ?>">
      <small>توکن به‌صورت رمزنگاری‌شده (AES-256-GCM) در پایگاه داده ذخیره می‌شود.</small>
    </label>

    <label class="check-row">
      <input type="checkbox" name="bot_enabled" <?= Settings::bool('bot_enabled', true) ? 'checked' : '' ?>>
      ربات فعال باشد و به پیام‌ها پاسخ دهد
    </label>

    <div class="grid-2">
      <label>شناسه‌های مدیر در ربات (با کاما)
        <input type="text" name="bot_admin_ids" value="<?= e($s('bot_admin_ids')) ?>" dir="ltr" placeholder="123456789,987654321">
        <small>این افراد می‌توانند از داخل بله دستور /stats و /admin را اجرا کنند.</small>
      </label>
      <label>زمان انتظار ارتباط با API بله (ثانیه)
        <input type="number" name="bale_timeout" value="<?= e($s('bale_timeout', '30')) ?>" min="5" max="120">
      </label>
    </div>

    <div class="alert alert-info small">
      نام ربات: <b><?= e($s('bot_username') !== '' ? '@' . $s('bot_username') : 'نامشخص') ?></b>
      — حالت دریافت پیام: <code><?= e(strtoupper((string) \App\Config::get('BOT_MODE', 'polling'))) ?></code>
    </div>

    <button class="btn btn-primary" type="submit">ذخیره و بررسی توکن</button>
  </form>
</div>

<?php elseif ($tab === 'instagram'): ?>
<div class="card">
  <div class="card-head"><h2>سرویس استخراج اطلاعات اینستاگرام</h2></div>
  <form method="post" action="<?= e(url('admin/?r=settings&tab=instagram')) ?>" data-busy autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="instagram">

    <label>کلید API
      <input type="password" name="ig_api_key" dir="ltr"
             placeholder="<?= $hasSecret('ig_api_key') ? e(mask_secret($s('ig_api_key'))) . ' — برای تغییر مقدار جدید وارد کنید' : 'API key' ?>">
      <small>کلید در پایگاه داده رمزنگاری می‌شود و هرگز در لاگ‌ها نوشته نمی‌شود.</small>
    </label>

    <div class="grid-2">
      <label>آدرس API
        <input type="url" name="ig_api_url" value="<?= e($s('ig_api_url', 'https://api.fast-creat.ir/instagram')) ?>" dir="ltr">
      </label>
      <label>نوع درخواست (type)
        <input type="text" name="ig_provider_mode" value="<?= e($s('ig_provider_mode', 'post2')) ?>" dir="ltr">
        <small>مقدار پارامتر type در سرویس (مثلاً post2 یا reel).</small>
      </label>
      <label>حداکثر زمان انتظار پاسخ (ثانیه)
        <input type="number" name="ig_request_timeout" value="<?= e($s('ig_request_timeout', '30')) ?>" min="5" max="60">
      </label>
    </div>

    <div class="alert alert-warning small">
      سرویس‌های استخراج محتوای اینستاگرام گاهی قطع می‌شوند. در صورت خطا، متن خطای کاربرانه به کاربر نشان داده می‌شود
      و ربات پایدار می‌ماند.
    </div>

    <button class="btn btn-primary" type="submit">ذخیره</button>
  </form>
</div>

<?php elseif ($tab === 'behavior'): ?>
<div class="card">
  <div class="card-head"><h2>رفتار دانلود و ارسال</h2></div>
  <form method="post" action="<?= e(url('admin/?r=settings&tab=behavior')) ?>" data-busy>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="behavior">
    <div class="grid-2">
      <label>حداکثر زمان دانلود هر فایل (ثانیه)
        <input type="number" name="download_timeout" value="<?= e($s('download_timeout', '60')) ?>" min="5" max="180">
      </label>
      <label>حداکثر حجم هر فایل (مگابایت)
        <input type="number" name="max_file_mb" value="<?= e($s('max_file_mb', '45')) ?>" min="1" max="200">
        <small>سقف ارسال فایل در بله معمولاً ۵۰ مگابایت است.</small>
      </label>
      <label>حداکثر فایل در هر پست
        <input type="number" name="max_files_per_post" value="<?= e($s('max_files_per_post', '10')) ?>" min="1" max="35">
      </label>
      <label>سقف درخواست هر کاربر در ساعت
        <input type="number" name="rate_limit_per_hour" value="<?= e($s('rate_limit_per_hour', '30')) ?>" min="1" max="1000">
      </label>
    </div>

    <label class="check-row"><input type="checkbox" name="send_cover" <?= Settings::bool('send_cover', true) ? 'checked' : '' ?>> ارسال کاور پست</label>
    <label class="check-row"><input type="checkbox" name="send_audio" <?= Settings::bool('send_audio', true) ? 'checked' : '' ?>> ارسال فایل صوتی (در صورت وجود)</label>
    <label class="check-row"><input type="checkbox" name="send_caption" <?= Settings::bool('send_caption', true) ? 'checked' : '' ?>> نوشتن کپشن (نویسنده و کیفیت) روی فایل‌ها</label>
    <label class="check-row"><input type="checkbox" name="fallback_to_links" <?= Settings::bool('fallback_to_links', true) ? 'checked' : '' ?>> اگر ارسال فایل ممکن نبود، لینک مستقیم ارسال شود</label>

    <button class="btn btn-primary" type="submit">ذخیره</button>
  </form>
</div>

<?php elseif ($tab === 'panel'): ?>
<div class="card">
  <div class="card-head"><h2>پنل مدیریت و امنیت ورود</h2></div>
  <form method="post" action="<?= e(url('admin/?r=settings&tab=panel')) ?>" data-busy autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="panel">

    <div class="grid-2">
      <label>عنوان پنل
        <input type="text" name="panel_title" value="<?= e($s('panel_title')) ?>">
      </label>
      <label>اعتبار کد یکبار مصرف (دقیقه)
        <input type="number" name="otp_ttl_minutes" value="<?= e($s('otp_ttl_minutes', '10')) ?>" min="2" max="60">
      </label>
      <label>روش ارسال کد ورود
        <select name="otp_delivery">
          <option value="bale" <?= $s('otp_delivery', 'bale') === 'bale' ? 'selected' : '' ?>>ارسال در بله</option>
          <option value="file" <?= $s('otp_delivery') === 'file' ? 'selected' : '' ?>>فایل محلی سرور</option>
        </select>
      </label>
      <label>شناسه چت بله برای دریافت کد
        <input type="text" name="otp_chat_id" dir="ltr"
               placeholder="<?= $hasSecret('otp_chat_id') ? e(mask_secret($s('otp_chat_id'))) : '123456789' ?>">
      </label>
    </div>

    <label class="check-row">
      <input type="checkbox" name="require_2fa" <?= Settings::bool('require_2fa', true) ? 'checked' : '' ?>>
      درخواست کد یکبار مصرف در هر ورود (توصیه می‌شود روشن بماند)
    </label>

    <label>لیست IP مجاز پنل (هر خط یا با کاما — خالی = همه IPها)
      <textarea name="ip_allowlist" dir="ltr" placeholder="203.0.113.10&#10;10.0.0.0/8"><?= e($s('ip_allowlist')) ?></textarea>
      <small>مثال CIDR: <code>192.168.1.0/24</code>. اگر IP خودتان را اشتباه وارد کنید از پنل بیرون می‌مانید؛
        در این حالت از CLI کمک بگیرید: <code dir="ltr">php cli/console.php ip:allow</code>.</small>
    </label>

    <div class="alert alert-warning small">
      اگر روش «فایل محلی» را انتخاب کنید، کد ورود در <code>storage/otp/otp.log</code> نوشته می‌شود.
      این حالت فقط برای سرورهای تک‌کاربره یا عیب‌یابی مناسب است.
    </div>

    <button class="btn btn-primary" type="submit">ذخیره</button>
  </form>
</div>

<?php else: ?>
<div class="card">
  <div class="card-head"><h2>متن‌های کاربر</h2></div>
  <form method="post" action="<?= e(url('admin/?r=settings&tab=texts')) ?>" data-busy>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="texts">

    <label>متن خوش‌آمدگویی
      <textarea name="welcome_text" placeholder="اگر خالی بماند، متن پیش‌فرض استفاده می‌شود. متغیر {name} مجاز است."><?= e($s('welcome_text')) ?></textarea>
      <small>متغیر <code>{name}</code> با نام کاربر جایگزین می‌شود. HTML مجاز است.</small>
    </label>
    <label>متن پشتیبانی (در پیام راهنما)
      <input type="text" name="support_text" value="<?= e($s('support_text')) ?>">
    </label>
    <label>راه ارتباطی پشتیبانی
      <input type="text" name="support_contact" value="<?= e($s('support_contact')) ?>" dir="ltr" placeholder="@support">
    </label>

    <button class="btn btn-primary" type="submit">ذخیره</button>
  </form>
</div>
<?php endif; ?>
