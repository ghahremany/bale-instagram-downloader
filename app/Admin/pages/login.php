<?php
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\RateLimiter;

/** @var string $pageTitle */
/** @var bool $authPage */
/** @var string $subtitle */

$authPage = true;
$pageTitle = 'ورود به پنل مدیریت';
$subtitle = ' — ورود امن';

$error = null;
$pending = Auth::pendingTwoFactor();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::assertPostToken($_POST['_csrf'] ?? null)) {
        $error = 'اعتبار فرم منقضی شد. دوباره تلاش کنید.';
    } elseif (!RateLimiter::hit('panel:login:' . client_ip(), 20, 900)) {
        $error = 'تلاش‌های ورود از این IP بیش از حد مجاز است. ۱۵ دقیقه دیگر امتحان کنید.';
    } else {
        $result = Auth::attempt(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        switch ($result['status']) {
            case 'ok':
                flash('ok', 'خوش آمدید 👋');
                redirect('admin/');
            case 'otp':
                redirect('admin/?r=otp');
            case 'first_login':
                redirect('admin/?r=first-login');
            default:
                $error = $result['message'];
        }
    }
}

if ($pending !== null) {
    flash('info', 'یک ورود نیمه‌تمام دارید. برای ادامه روی دکمه زیر بزنید.');
}
?>
<?php if ($pending !== null): ?>
  <a class="btn btn-primary btn-lg mb" href="<?= e(url('admin/?r=' . ($pending['purpose'] === 'first_login' ? 'first-login' : 'otp'))) ?>">
    ادامه ورود قبلی
  </a>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/?r=login')) ?>" autocomplete="off" data-busy>
  <?= csrf_field() ?>
  <?php if ($error !== null): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>

  <label>نام کاربری
    <input type="text" name="username" value="<?= e((string) ($_POST['username'] ?? '')) ?>" required autofocus dir="ltr">
  </label>
  <label>رمز عبور
    <input type="password" name="password" required dir="ltr">
  </label>

  <button class="btn btn-primary btn-lg" type="submit">ورود</button>
</form>

<div class="alert alert-info mt">
  پس از تایید رمز عبور، یک کد یکبار مصرف برای شما ارسال می‌شود. اگر کد را دریافت نکردید،
  مدیر باید روش ارسال را در تنظیمات بررسی کند.
</div>
<p class="center small muted">
  فراموشی رمز عبور؟ فایل <code>.env</code> و <code>storage/installed.lock</code> را حذف و نصب را تکرار کنید.
</p>
