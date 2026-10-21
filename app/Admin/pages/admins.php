<?php
declare(strict_types=1);

use App\Admin\Router;
use App\Admin\View;
use App\Audit;
use App\Auth;
use App\Db;
use App\Otp;

Router::requireOwner();
$pageTitle = 'مدیران';
$active = 'admins';

$me = Auth::id();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $display = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? 'admin') === 'owner' ? 'owner' : 'admin';

        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
            $errors[] = 'نام کاربری نامعتبر است.';
        } elseif (Db::first('SELECT id FROM admins WHERE username = ?', [$username]) !== null) {
            $errors[] = 'این نام کاربری قبلاً ثبت شده است.';
        } elseif (($problem = Auth::validatePassword($password)) !== null) {
            $errors[] = $problem;
        } elseif ($role === 'owner' && !Auth::isOwner()) {
            $errors[] = 'ساخت مالک جدید فقط توسط مالک ممکن است.';
        } else {
            $newId = Db::insert('admins', [
                'username'             => $username,
                'password_hash'        => Auth::hashPassword($password),
                'display_name'         => $display !== '' ? $display : $username,
                'role'                 => $role,
                'is_active'            => 1,
                'must_change_password' => 1,
                'created_at'           => now_utc(),
                'updated_at'           => now_utc(),
            ]);
            $activation = Otp::createActivationCode($newId);
            Audit::log('admin_created', $username . ' (' . $role . ')');
            flash('ok', 'مدیر جدید ساخته شد. کد فعال‌سازی اولین ورود: ' . $activation['code']
                . ' (اعتبار تا ' . format_dt($activation['expires_at']) . ')');
        }
    } elseif ($action === 'disable' || $action === 'enable') {
        if ($id === $me) {
            $errors[] = 'نمی‌توانید حساب خودتان را غیرفعال کنید.';
        } elseif (Db::first('SELECT id FROM admins WHERE id = ?', [$id]) === null) {
            $errors[] = 'مدیر پیدا نشد.';
        } else {
            Db::update('admins', ['is_active' => $action === 'enable' ? 1 : 0, 'updated_at' => now_utc()], 'id = ?', [$id]);
            Audit::log('admin_' . $action, 'id=' . $id);
            flash('ok', $action === 'enable' ? 'حساب فعال شد.' : 'حساب غیرفعال شد.');
        }
    } elseif ($action === 'delete') {
        if ($id === $me) {
            $errors[] = 'حساب خودتان قابل حذف نیست.';
        } else {
            $row = Db::first('SELECT username FROM admins WHERE id = ?', [$id]);
            Db::run('DELETE FROM admins WHERE id = ?', [$id]);
            Audit::log('admin_deleted', (string) ($row['username'] ?? $id));
            flash('ok', 'حساب مدیر حذف شد.');
        }
    } elseif ($action === 'reset') {
        $row = Db::first('SELECT * FROM admins WHERE id = ?', [$id]);
        if ($row === null) {
            $errors[] = 'مدیر پیدا نشد.';
        } else {
            $temp = Otp::generateCode() . strtoupper(bin2hex(random_bytes(2)));
            Db::update('admins', [
                'password_hash'        => Auth::hashPassword($temp),
                'must_change_password' => 1,
                'failed_attempts'      => 0,
                'locked_until'         => null,
                'updated_at'           => now_utc(),
            ], 'id = ?', [$id]);
            $activation = Otp::createActivationCode($id);
            Audit::log('admin_password_reset', (string) $row['username']);
            flash('ok', 'رمز موقت «' . $row['username'] . '»: ' . $temp
                . ' — کد فعال‌سازی اولین ورود: ' . $activation['code'] . ' (تا ' . format_dt($activation['expires_at']) . ')');
        }
    } elseif ($action === 'unlock') {
        Db::update('admins', ['failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now_utc()], 'id = ?', [$id]);
        Audit::log('admin_unlocked', 'id=' . $id);
        flash('ok', 'قفل حساب برداشته شد.');
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    }
    redirect('admin/?r=admins');
}

$rows = Db::all('SELECT * FROM admins ORDER BY id ASC');
?>

<div class="card">
  <div class="card-head"><h2>افزودن مدیر</h2></div>
  <form method="post" action="<?= e(url('admin/?r=admins')) ?>" autocomplete="off" data-busy>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <label>نام کاربری
        <input type="text" name="username" required dir="ltr" pattern="[a-z0-9._-]{3,32}">
      </label>
      <label>نام نمایشی
        <input type="text" name="display_name">
      </label>
      <label>رمز عبور موقت
        <input type="password" name="password" required minlength="10" dir="ltr">
        <small>کاربر در اولین ورود باید آن را تغییر دهد.</small>
      </label>
      <label>نقش
        <select name="role">
          <option value="admin">مدیر</option>
          <option value="owner">مالک</option>
        </select>
      </label>
    </div>
    <button class="btn btn-primary" type="submit">ساخت حساب</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2>حساب‌های مدیر</h2><span class="badge badge-info"><?= count($rows) ?> حساب</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>نام کاربری</th><th>نقش</th><th>وضعیت</th><th>قفل</th><th>آخرین ورود</th><th>IP</th><th>عملیات</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row):
          $locked = !empty($row['locked_until']) && strtotime((string) $row['locked_until'] . ' UTC') > time();
      ?>
        <tr>
          <td class="num"><?= (int) $row['id'] ?></td>
          <td>
            <?= e((string) $row['username']) ?>
            <?php if ((int) $row['id'] === $me): ?><span class="badge badge-info">شما</span><?php endif; ?>
          </td>
          <td><span class="badge badge-muted"><?= e((string) $row['role']) ?></span></td>
          <td><?= (int) $row['is_active'] ? '<span class="badge badge-ok">فعال</span>' : '<span class="badge badge-bad">غیرفعال</span>' ?></td>
          <td><?= $locked ? '<span class="badge badge-bad">قفل موقت</span>' : '<span class="badge badge-muted">—</span>' ?></td>
          <td class="small"><?= e(format_dt($row['last_login_at'] ? (string) $row['last_login_at'] : null)) ?></td>
          <td><code class="small"><?= e((string) ($row['last_login_ip'] ?? '—')) ?></code></td>
          <td>
            <?php if ((int) $row['id'] !== $me): ?>
              <form method="post" action="<?= e(url('admin/?r=admins')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= (int) $row['is_active'] ? 'disable' : 'enable' ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= (int) $row['is_active'] ? 'غیرفعال' : 'فعال' ?></button>
              </form>
              <form method="post" action="<?= e(url('admin/?r=admins')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn-sm" type="submit" data-confirm="رمز موقت جدید ساخته شود؟">بازنشانی رمز</button>
              </form>
              <?php if ($locked): ?>
                <form method="post" action="<?= e(url('admin/?r=admins')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unlock">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="btn btn-sm btn-ok" type="submit">رفع قفل</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= e(url('admin/?r=admins')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn-sm btn-bad" type="submit" data-confirm="این حساب برای همیشه حذف شود؟">حذف</button>
              </form>
            <?php else: ?>
              <span class="muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-warning small">
  حساب‌های جدید در اولین ورود نیاز به رمز موقت، کد فعال‌سازی (در صورت تنظیم) و تغییر رمز دارند.
  رمزهای موقت را از مسیر امن به مدیر برسانید و بعد از اولین ورود آن‌ها را پاک کنید.
</div>
