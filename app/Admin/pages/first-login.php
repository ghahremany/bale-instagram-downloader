<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Otp;
use App\RateLimiter;
use App\Settings;

/** @var bool $authPage */
$authPage = true;
$pageTitle = 'اولین ورود';
$subtitle = ' — فعال‌سازی حساب';

$pending = Auth::pendingTwoFactor();
if ($pending === null || ($pending['purpose'] ?? '') !== 'first_login') {
    Auth::cancelPending();
    flash('warn', 'برای ادامه، ابتدا با رمز عبور وارد شوید.');
    redirect('admin/?r=login');
}
$adminId = (int) $pending['admin_id'];
$codeVerified = (int) ($_SESSION['first_login_verified_at'] ?? 0) > time() - 600;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::assertPostToken($_POST['_csrf'] ?? null)) {
        $error = 'اعتبار فرم منقضی شد. دوباره تلاش کنید.';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        Auth::cancelPending();
        unset($_SESSION['first_login_verified_at']);
        redirect('admin/?r=login');
    } elseif (!$codeVerified) {
        if (!RateLimiter::hit('firstlogin:' . client_ip(), 10, 900)) {
            $error = 'تلاش‌های ناموفق بیش از حد مجاز. کمی بعد دوباره تلاش کنید.';
        } elseif (Otp::consumeActivationCode((string) ($_POST['code'] ?? ''), $adminId) !== null) {
            $_SESSION['first_login_verified_at'] = time();
            $codeVerified = true;
            flash('ok', 'کد فعال‌سازی تایید شد. حالا رمز عبور جدید را انتخاب کنید.');
        } else {
            $error = 'کد فعال‌سازی نامعتبر، منقضی یا قبلاً استفاده شده است.';
        }
    } else {
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $problem = Auth::validatePassword($new);
        if ($problem !== null) {
            $error = $problem;
        } elseif ($new !== $confirm) {
            $error = 'تکرار رمز عبور مطابقت ندارد.';
        } elseif (Auth::verifyPassword($adminId, $new)) {
            $error = 'رمز جدید نباید با رمز فعلی یکسان باشد.';
        } else {
            Auth::changePassword($adminId, $new);
            unset($_SESSION['first_login_verified_at']);
            if (Settings::bool('require_2fa', true)) {
                $_SESSION['2fa_pending'] = ['admin_id' => $adminId, 'purpose' => 'login', 'at' => time()];
                $_SESSION['otp_delivery'] = Otp::issueForLogin($adminId);
                flash('ok', 'رمز عبور به‌روزرسانی شد. کد ورود دومرحله‌ای ارسال شد.');
                redirect('admin/?r=otp');
            }
            Auth::completeLogin($adminId);
            flash('ok', 'حساب شما فعال شد. خوش آمدید 👋');
            redirect('admin/');
        }
    }
}
?>
<?php if ($error !== null): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$codeVerified): ?>
  <p class="muted">
    کد فعال‌سازی ۸ رقمی را که در پایان نصب نمایش داده شد (یا در بله برایتان ارسال شد) وارد کنید.
    این کد فقط یک‌بار قابل استفاده است.
  </p>
  <form method="post" action="<?= e(url('admin/?r=first-login')) ?>" autocomplete="off" data-busy>
    <?= csrf_field() ?>
    <label>کد فعال‌سازی
      <input class="otp-input" type="text" name="code" inputmode="numeric" data-otp maxlength="10" required autofocus dir="ltr">
    </label>
    <button class="btn btn-primary btn-lg" type="submit">تایید کد</button>
  </form>
<?php else: ?>
  <div class="alert alert-ok">✅ کد تایید شد. رمز عبور جدید خود را انتخاب کنید.</div>
  <form method="post" action="<?= e(url('admin/?r=first-login')) ?>" autocomplete="off" data-busy>
    <?= csrf_field() ?>
    <label>رمز عبور جدید
      <input type="password" name="new_password" required minlength="10" dir="ltr">
      <small>حداقل ۱۰ کاراکتر، شامل حرف و عدد. از رمزهای رایج استفاده نکنید.</small>
    </label>
    <label>تکرار رمز عبور جدید
      <input type="password" name="new_password_confirm" required minlength="10" dir="ltr">
    </label>
    <button class="btn btn-primary btn-lg" type="submit">ذخیره و ورود</button>
  </form>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/?r=first-login')) ?>" class="mt">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="cancel">
  <button class="btn btn-sm btn-ghost" type="submit">انصراف</button>
</form>

<div class="alert alert-info mt small">
  اگر کد فعال‌سازی را گم کرده‌اید: با دسترسی SSH دستور
  <code dir="ltr">php cli/console.php otp:new-activation</code> را اجرا کنید تا کد تازه ساخته شود.
</div>
