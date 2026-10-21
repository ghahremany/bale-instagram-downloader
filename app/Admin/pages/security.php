<?php
declare(strict_types=1);

use App\Admin\View;
use App\Audit;
use App\Auth;
use App\Db;
use App\Settings;

$pageTitle = 'امنیت';
$active = 'security';

$me = Auth::user() ?? [];
$myId = (int) ($me['id'] ?? 0);
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!Auth::verifyPassword($myId, $current)) {
            $errors[] = 'رمز عبور فعلی صحیح نیست.';
        } elseif (($problem = Auth::validatePassword($new)) !== null) {
            $errors[] = $problem;
        } elseif ($new !== $confirm) {
            $errors[] = 'تکرار رمز عبور مطابقت ندارد.';
        } elseif ($current === $new) {
            $errors[] = 'رمز جدید باید با رمز فعلی متفاوت باشد.';
        } else {
            Auth::changePassword($myId, $new);
            flash('ok', 'رمز عبور با موفقیت تغییر کرد.');
        }
    } elseif ($action === 'two_factor') {
        Settings::set('require_2fa', isset($_POST['require_2fa']) ? '1' : '0');
        Audit::log('2fa_updated', isset($_POST['require_2fa']) ? 'enabled' : 'disabled');
        flash('ok', 'تنظیم ورود دومرحله‌ای ذخیره شد.');
        if (!isset($_POST['require_2fa'])) {
            flash('warn', 'ورود دومرحله‌ای خاموش شد؛ توصیه می‌کنیم آن را روشن نگه دارید.');
        }
    } elseif ($action === 'revoke_sessions') {
        Settings::set('session_epoch', (string) (time()));
        $_SESSION['_epoch'] = Settings::get('session_epoch');
        Audit::log('sessions_revoked', 'all other admin sessions invalidated');
        flash('ok', 'همه نشست‌های دیگر باطل شدند. این نشست فعال باقی می‌ماند.');
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    }
    redirect('admin/?r=security');
}

$attempts = Db::all('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 20');
$failed24 = (int) (Db::value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ?', [gmdate('Y-m-d H:i:s', time() - 86400)]) ?? 0);
$admins = Db::all('SELECT id, username, role, is_active, last_login_at, last_login_ip, locked_until FROM admins ORDER BY id');

$envPath = APP_ROOT . '/.env';
$envPerms = is_file($envPath) ? substr(sprintf('%o', fileperms($envPath)), -4) : '—';
$appKeyStrong = strlen((string) \App\Config::get('APP_KEY', '')) >= 48;
$debugOff = !\App\Config::bool('APP_DEBUG', false);
$https = security_is_https();
$checklist = [
    ['کلید برنامه (APP_KEY) تصادفی و بلند', $appKeyStrong, 'APP_KEY باید حداقل ۴۸ کاراکتر تصادفی باشد.'],
    ['حالت اشکال‌زدایی خاموش', $debugOff, 'APP_DEBUG=false تا خطاها به کاربر نمایش داده نشوند.'],
    ['دسترسی فایل .env روی 0600', $envPerms === '0600', 'chmod 600 .env'],
    ['اتصال HTTPS', $https, 'گواهی SSL را فعال کنید.'],
    ['ورود دومرحله‌ای (OTP)', Settings::bool('require_2fa', true), 'در همین صفحه فعال کنید.'],
    ['ارسال کد از طریق بله', Settings::get('otp_delivery') === 'bale', 'روش ارسال «فایل محلی» برای تولید توصیه نمی‌شود.'],
    ['محدودسازی IP (اختیاری)', trim(Settings::get('ip_allowlist')) !== '', 'در صورت ثابت بودن IP مدیر، آن را محدود کنید.'],
    ['حساب‌های مدیر فعال', count($admins) <= 3, 'تعداد حساب‌های مدیر را کم نگه دارید.'],
];
$passed = count(array_filter($checklist, static fn($c) => $c[1]));
?>

<div class="grid grid-3 mb">
  <?= View::stat('امتیاز امنیتی', $passed . '/' . count($checklist), 'موارد رعایت‌شده', $passed === count($checklist) ? 'ok' : ($passed >= 5 ? 'warn' : 'bad')) ?>
  <?= View::stat('تلاش ناموفق ورود (۲۴ ساعت)', number_format($failed24), '', $failed24 > 5 ? 'bad' : '') ?>
  <?= View::stat('آخرین ورود', format_dt($me['last_login_at'] ?? null), (string) ($me['last_login_ip'] ?? '')) ?>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="card-head"><h2>تغییر رمز عبور</h2></div>
    <form method="post" action="<?= e(url('admin/?r=security')) ?>" autocomplete="off" data-busy>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label>رمز عبور فعلی
        <input type="password" name="current_password" required dir="ltr">
      </label>
      <label>رمز عبور جدید
        <input type="password" name="new_password" required minlength="10" dir="ltr">
        <small>حداقل ۱۰ کاراکتر شامل حرف و عدد.</small>
      </label>
      <label>تکرار رمز جدید
        <input type="password" name="new_password_confirm" required minlength="10" dir="ltr">
      </label>
      <button class="btn btn-primary" type="submit">تغییر رمز</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h2>نشست‌ها و ورود دومرحله‌ای</h2></div>
    <form method="post" action="<?= e(url('admin/?r=security')) ?>" class="mb">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="two_factor">
      <label class="check-row">
        <input type="checkbox" name="require_2fa" <?= Settings::bool('require_2fa', true) ? 'checked' : '' ?>>
        درخواست کد یکبار مصرف در هر ورود
      </label>
      <button class="btn btn-sm btn-primary" type="submit">ذخیره</button>
    </form>

    <form method="post" action="<?= e(url('admin/?r=security')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="revoke_sessions">
      <button class="btn btn-sm btn-bad" type="submit" data-confirm="همه نشست‌های دیگر (مرورگرها/دستگاه‌های دیگر) باطل شوند؟">
        باطل کردن سایر نشست‌ها
      </button>
    </form>

    <div class="kv mt">
      <div><span>دسترسی .env</span><code><?= e($envPerms) ?></code></div>
      <div><span>HTTPS</span><?= $https ? '<span class="badge badge-ok">فعال</span>' : '<span class="badge badge-bad">غیرفعال</span>' ?></div>
      <div><span>IP شما</span><code><?= e(client_ip()) ?></code></div>
      <div><span>آخرین ورود</span><span class="small"><?= e(format_dt($me['last_login_at'] ?? null)) ?> — <?= e((string) ($me['last_login_ip'] ?? '—')) ?></span></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>چک‌لیست امنیتی</h2><span class="badge badge-info"><?= $passed ?>/<?= count($checklist) ?></span></div>
  <div class="checks">
    <?php foreach ($checklist as [$label, $ok, $hint]): ?>
      <div class="check <?= $ok ? 'ok' : 'warn' ?>">
        <span class="check-icon"><?= $ok ? '✓' : '!' ?></span>
        <span class="check-label"><?= e($label) ?></span>
        <span class="check-value"><?= $ok ? 'رعایت شده' : 'نیاز به بررسی' ?></span>
        <?php if (!$ok): ?><span class="check-hint"><?= e($hint) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>آخرین تلاش‌های ورود</h2><a class="btn btn-sm" href="<?= e(url('admin/?r=audit')) ?>">گزارش کامل</a></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>زمان</th><th>نام کاربری</th><th>IP</th><th>نتیجه</th></tr></thead>
      <tbody>
      <?php foreach ($attempts as $row): ?>
        <tr>
          <td class="small"><?= e(format_dt((string) $row['created_at'])) ?></td>
          <td class="small"><?= e((string) ($row['username'] ?? '—')) ?></td>
          <td><code><?= e((string) ($row['ip'] ?? '—')) ?></code></td>
          <td><?= (int) $row['success'] ? '<span class="badge badge-ok">موفق</span>' : '<span class="badge badge-bad">ناموفق</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($attempts === []): ?>
        <tr><td colspan="4" class="empty">تلاشی ثبت نشده است.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
