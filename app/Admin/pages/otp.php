<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Otp;
use App\RateLimiter;
use App\Settings;

/** @var bool $authPage */
$authPage = true;
$pageTitle = 'کد یکبار مصرف';
$subtitle = ' — تایید دومرحله‌ای';

$pending = Auth::pendingTwoFactor();
if ($pending === null || ($pending['purpose'] ?? '') !== 'login') {
    Auth::cancelPending();
    flash('warn', 'نشست ورود منقضی شده است. دوباره وارد شوید.');
    redirect('admin/?r=login');
}

$error = null;
$notice = null;
$delivery = $_SESSION['otp_delivery'] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::assertPostToken($_POST['_csrf'] ?? null)) {
        $error = 'اعتبار فرم منقضی شد. دوباره تلاش کنید.';
    } else {
        $action = (string) ($_POST['action'] ?? 'verify');
        if ($action === 'cancel') {
            Auth::cancelPending();
            redirect('admin/?r=login');
        }
        if ($action === 'resend') {
            if (!RateLimiter::hit('otp:resend:' . client_ip(), 6, 900)) {
                $error = 'درخواست‌های ارسال کد بیش از حد مجاز است. کمی بعد تلاش کنید.';
            } else {
                $info = Otp::issueForLogin((int) $pending['admin_id']);
                $_SESSION['otp_delivery'] = $info;
                $delivery = $info;
                $notice = $info['delivery'] === 'bale'
                    ? 'کد جدید ارسال شد.'
                    : 'ارسال بله ممکن نبود؛ کد جدید در فایل محلی سرور ثبت شد.';
            }
        } else {
            if (!RateLimiter::hit('otp:verify:' . client_ip(), 15, 900)) {
                $error = 'تلاش‌های ناموفق بیش از حد. ۱۵ دقیقه دیگر تلاش کنید.';
            } elseif (Otp::verifyLoginCode((string) ($_POST['code'] ?? ''))) {
                Auth::completeLogin((int) $pending['admin_id']);
                unset($_SESSION['otp_delivery']);
                flash('ok', 'ورود با موفقیت انجام شد.');
                redirect('admin/');
            } else {
                $error = 'کد وارد شده نامعتبر یا منقضی شده است.';
            }
        }
    }
}

$ttl = Settings::int('otp_ttl_minutes', 10);
?>
<form method="post" action="<?= e(url('admin/?r=otp')) ?>" autocomplete="off" data-busy>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="verify">

  <?php if ($error !== null): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice !== null): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>

  <p class="muted">
    کد یکبار مصرف
    <?= ($delivery['delivery'] ?? '') === 'bale' ? 'به حساب بله شما (' . e((string) ($delivery['masked'] ?? '')) . ') ارسال شد' : 'در فایل محلی سرور ثبت شد' ?>
    و تا <b><?= e((string) $ttl) ?></b> دقیقه اعتبار دارد.
  </p>

  <label>کد ۸ رقمی
    <input class="otp-input" type="text" name="code" inputmode="numeric" data-otp maxlength="8" required autofocus dir="ltr">
  </label>

  <button class="btn btn-primary btn-lg" type="submit">تایید و ورود</button>
</form>

<div class="row mt" style="justify-content:space-between">
  <form method="post" action="<?= e(url('admin/?r=otp')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="resend">
    <button class="btn btn-sm" type="submit">ارسال دوباره کد</button>
  </form>
  <form method="post" action="<?= e(url('admin/?r=otp')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel">
    <button class="btn btn-sm btn-ghost" type="submit">انصراف و بازگشت</button>
  </form>
</div>

<?php if (($delivery['delivery'] ?? '') === 'file'): ?>
  <div class="alert alert-warning mt small">
    حالت ارسال محلی فعال است. کد در فایل
    <code>storage/otp/otp.log</code> روی سرور ذخیره شده است؛
    با دستور <code dir="ltr">php cli/console.php otp:last</code> قابل مشاهده است.
    برای امنیت بیشتر، ارسال از طریق بله را در تنظیمات فعال کنید.
  </div>
<?php endif; ?>
