<?php
/**
 * First-run installation wizard.
 * Locks itself permanently once storage/installed.lock exists.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use App\Installer;

$requirements = Installer::requirements();
$allPassed = Installer::allRequirementsPassed($requirements);
$installed = Installer::isInstalled();

$errors = [];
$old = static fn(string $key, string $default = ''): string => e((string) ($_POST[$key] ?? $default));

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors['_form'] = 'اعتبار فرم منقضی شده است. صفحه را دوباره بارگذاری کنید.';
    } elseif (!$allPassed) {
        $errors['_form'] = 'ابتدا پیش‌نیازهای مشخص‌شده را برطرف کنید.';
    } else {
        try {
            $result = Installer::install($_POST);
            $_SESSION['_install_result'] = $result;
            redirect('install.php?done=1');
        } catch (Throwable $e) {
            $errors['_form'] = $e->getMessage();
            App\Log::error('Installation failed: ' . $e->getMessage());
        }
    }
}

$finished = $installed && isset($_GET['done']) && isset($_SESSION['_install_result']);
$result = $finished ? $_SESSION['_install_result'] : null;
$panelUrl = url('admin/');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>نصب | دانلودر اینستاگرام بله</title>
<link rel="stylesheet" href="<?= e(url('assets/admin.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-wrap install-wrap">

  <div class="install-head">
    <div class="brand-badge">بله</div>
    <div>
      <h1>نصب ربات دانلودر اینستاگرام</h1>
      <p class="muted">در چند گام، ربات بله و پنل مدیریت را راه‌اندازی کنید.</p>
    </div>
  </div>

<?php if ($installed && !$finished): ?>
  <div class="card">
    <h2>✅ نصب قبلاً انجام شده است</h2>
    <p class="muted">برای امنیت، اجرای مجدد نصب‌کننده غیرفعال شده است. برای نصب از نو، فایل
      <code>storage/installed.lock</code> و <code>.env</code> را حذف کنید.</p>
    <a class="btn btn-primary" href="<?= e($panelUrl) ?>">ورود به پنل مدیریت</a>
  </div>
<?php elseif ($finished): ?>
  <div class="card success-card">
    <h2>🎉 نصب با موفقیت انجام شد</h2>
    <p class="muted">حالا با اطلاعات زیر وارد پنل شوید. در اولین ورود، «کد فعال‌سازی» زیر از شما خواسته می‌شود.</p>
    <div class="kv">
      <div><span>نام کاربری</span><strong><?= e($result['admin_username']) ?></strong></div>
      <div><span>کد فعال‌سازی یکبار مصرف</span><strong class="code-big"><?= e($result['activation_code']) ?></strong></div>
      <div><span>اعتبار کد</span><strong><?= e(format_dt($result['activation_expires'])) ?></strong></div>
      <div><span>ارسال کد در بله</span><strong><?= $result['otp_sent'] ? '✅ ارسال شد' : '⚠️ ارسال نشد — کد را از همین صفحه یادداشت کنید' ?></strong></div>
    </div>
    <div class="alert alert-warning">
      این کد فقط یک‌بار قابل استفاده است. پس از ورود، رمز عبور جدید انتخاب می‌کنید.
      فایل <code>.env</code> با دسترسی 0600 ساخته شد؛ آن را در git قرار ندهید.
    </div>
    <a class="btn btn-primary" href="<?= e($panelUrl) ?>">رفتن به صفحه ورود</a>
  </div>
<?php else: ?>

  <div class="card">
    <h2>۱. بررسی پیش‌نیازها</h2>
    <div class="checks">
      <?php foreach ($requirements as $key => $check): ?>
        <div class="check <?= $check['ok'] ? 'ok' : ($key === 'https' ? 'warn' : 'bad') ?>">
          <span class="check-icon"><?= $check['ok'] ? '✓' : ($key === 'https' ? '!' : '✕') ?></span>
          <span class="check-label"><?= e($check['label']) ?></span>
          <span class="check-value"><?= e($check['value']) ?></span>
          <?php if (!$check['ok']): ?><span class="check-hint"><?= e($check['hint']) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <form method="post" class="card install-form" autocomplete="off">
    <?= csrf_field() ?>
    <h2>۲. تنظیمات پایگاه داده (MySQL)</h2>
    <div class="grid-2">
      <label>هاست دیتابیس
        <input type="text" name="db_host" value="<?= $old('db_host', '127.0.0.1') ?>" required>
      </label>
      <label>پورت
        <input type="number" name="db_port" value="<?= $old('db_port', '3306') ?>" required>
      </label>
      <label>نام دیتابیس
        <input type="text" name="db_name" value="<?= $old('db_name', 'bale_ig') ?>" required>
      </label>
      <label>کاربر دیتابیس
        <input type="text" name="db_user" value="<?= $old('db_user') ?>" required>
      </label>
      <label class="span-2">رمز دیتابیس
        <input type="password" name="db_pass" value="">
        <small class="muted">اگر دیتابیس وجود نداشته باشد، با همین کاربر ساخته می‌شود.</small>
      </label>
    </div>

    <h2>۳. حساب مدیر (Owner)</h2>
    <div class="grid-2">
      <label>نام کاربری
        <input type="text" name="admin_username" value="<?= $old('admin_username', 'admin') ?>" required>
      </label>
      <label>نام نمایشی
        <input type="text" name="admin_name" value="<?= $old('admin_name', 'مدیر سیستم') ?>">
      </label>
      <label>رمز عبور
        <input type="password" name="admin_password" required>
        <small class="muted">حداقل ۱۰ کاراکتر، شامل حرف و عدد.</small>
      </label>
      <label>تکرار رمز عبور
        <input type="password" name="admin_password_confirm" required>
      </label>
    </div>

    <h2>۴. ربات بله</h2>
    <div class="grid-2">
      <label class="span-2">توکن ربات
        <input type="text" name="bot_token" value="<?= $old('bot_token') ?>" placeholder="123456789:AA..." dir="ltr">
        <small class="muted">از <a href="https://tapi.bale.ai" target="_blank" rel="noopener">تاپی بله</a> دریافت کنید.</small>
      </label>
      <label>روش ارسال کد یکبار مصرف
        <select name="otp_delivery" id="otp_delivery">
          <option value="bale" <?= ($_POST['otp_delivery'] ?? 'bale') === 'bale' ? 'selected' : '' ?>>ارسال در بله (پیشنهادی)</option>
          <option value="file" <?= ($_POST['otp_delivery'] ?? '') === 'file' ? 'selected' : '' ?>>فایل محلی روی سرور</option>
        </select>
      </label>
      <label>شناسه چت بله مدیر
        <input type="text" name="otp_chat_id" value="<?= $old('otp_chat_id') ?>" placeholder="123456789" dir="ltr">
        <small class="muted">شناسه عددی خودتان را با دستور <code dir="ltr">/id</code> در ربات بگیرید.</small>
      </label>
    </div>

    <h2>۵. سرویس اینستاگرام (اختیاری)</h2>
    <div class="grid-2">
      <label>کلید API
        <input type="text" name="ig_api_key" value="<?= $old('ig_api_key') ?>" dir="ltr">
        <small class="muted">کلید سرویس fast-creat (در پنل هم قابل ویرایش است).</small>
      </label>
      <label>عنوان پنل
        <input type="text" name="panel_title" value="<?= $old('panel_title', 'دانلودر اینستاگرام بله') ?>">
      </label>
      <label class="span-2">آدرس پنل (APP_URL)
        <input type="text" name="app_url" value="<?= $old('app_url', (security_is_https() ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?>" dir="ltr">
        <small class="muted">برای وب‌هوک و لینک‌های پنل استفاده می‌شود؛ بدون اسلش انتهایی.</small>
      </label>
    </div>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert alert-error"><?= e($errors['_form']) ?></div>
    <?php endif; ?>

    <div class="alert alert-info">
      با ادامه، جداول MySQL ساخته می‌شوند، حساب مدیر ثبت می‌شود و یک کد فعال‌سازی یکبار مصرف
      برای اولین ورود تولید می‌گردد. نصب‌کننده پس از پایان، خودش را غیرفعال می‌کند.
    </div>

    <button class="btn btn-primary btn-lg" type="submit" <?= $allPassed ? '' : 'disabled' ?>>
      نصب و فعال‌سازی
    </button>
    <?php if (!$allPassed): ?>
      <p class="muted small">تا رفع موارد قرمز، دکمه نصب غیرفعال است.</p>
    <?php endif; ?>
  </form>
<?php endif; ?>

  <footer class="install-foot">
    <span>نسخه <?= e(APP_VERSION) ?></span>
    <span class="dot">•</span>
    <span>Bale Instagram Downloader</span>
  </footer>
</div>
<script>
(function () {
  var sel = document.getElementById('otp_delivery');
  if (!sel) return;
  var chat = document.querySelector('input[name="otp_chat_id"]');
  function sync() { if (!chat) return; chat.closest('label').style.opacity = sel.value === 'bale' ? '1' : '.55'; }
  sel.addEventListener('change', sync); sync();
})();
</script>
</body>
</html>
