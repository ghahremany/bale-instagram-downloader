<?php
declare(strict_types=1);

use App\Admin\View;
use App\Audit;
use App\Channels;
use App\Settings;

$pageTitle = 'کانال‌های عضویت اجباری';
$active = 'channels';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $chatId = trim((string) ($_POST['chat_id'] ?? ''));
        $link = trim((string) ($_POST['link'] ?? ''));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 120) {
            $errors[] = 'عنوان کانال را وارد کنید.';
        }
        if (!preg_match('/^-?\d{4,20}$/', $chatId) && !preg_match('/^@[A-Za-z0-9_]{4,}$/', $chatId)) {
            $errors[] = 'شناسه کانال باید عددی (مثل 1001234567890-) یا @username باشد.';
        }
        if (!preg_match('~^https://~i', $link)) {
            $errors[] = 'لینک کانال باید با https شروع شود.';
        }
        if ($errors !== []) {
            flash('error', implode(' ', $errors));
        } else {
            Channels::create($title, $chatId, $link);
            Audit::log('channel_added', $title);
            flash('ok', 'کانال اضافه شد.');
        }
    } elseif ($action === 'toggle') {
        Channels::toggle((int) ($_POST['id'] ?? 0));
        flash('ok', 'وضعیت کانال تغییر کرد.');
    } elseif ($action === 'delete') {
        Channels::delete((int) ($_POST['id'] ?? 0));
        Audit::log('channel_deleted', 'id=' . (int) ($_POST['id'] ?? 0));
        flash('ok', 'کانال حذف شد.');
    } elseif ($action === 'gate') {
        Settings::set('membership_gate_enabled', isset($_POST['enabled']) ? '1' : '0');
        Settings::set('membership_gate_fail_open', isset($_POST['fail_open']) ? '1' : '0');
        Audit::log('membership_gate_updated', (isset($_POST['enabled']) ? 'on' : 'off'));
        flash('ok', 'تنظیمات عضویت اجباری ذخیره شد.');
    }
    redirect('admin/?r=channels');
}

$rows = Channels::all();
$gate = Settings::bool('membership_gate_enabled', true);
$failOpen = Settings::bool('membership_gate_fail_open', false);
?>

<div class="grid grid-3 mb">
  <?= View::stat('کانال‌های فعال', (string) count(array_filter($rows, static fn($r) => (int) $r['is_active'] === 1))) ?>
  <?= View::stat('وضعیت گیت عضویت', $gate ? 'فعال' : 'غیرفعال', '', $gate ? 'ok' : '') ?>
  <?= View::stat('رفتار در خطای API', $failOpen ? 'عبور کاربر' : 'مسدودسازی', '', $failOpen ? 'warn' : '') ?>
</div>

<div class="card">
  <div class="card-head"><h2>افزودن کانال</h2></div>
  <form method="post" action="<?= e(url('admin/?r=channels')) ?>" data-busy>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="grid-2">
      <label>عنوان نمایشی
        <input type="text" name="title" placeholder="کانال رسمی" required>
      </label>
      <label>شناسه چت کانال
        <input type="text" name="chat_id" placeholder="-1001234567890" dir="ltr" required>
        <small>ربات باید ادمین کانال باشد تا بتواند عضویت را بررسی کند.</small>
      </label>
      <label class="span-2">لینک کانال (https)
        <input type="url" name="link" placeholder="https://t.me/your_channel" dir="ltr" required>
      </label>
    </div>
    <button class="btn btn-primary" type="submit">افزودن</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2>کانال‌ها</h2></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>عنوان</th><th>شناسه چت</th><th>لینک</th><th>وضعیت</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="num"><?= (int) $row['id'] ?></td>
          <td><?= e((string) $row['title']) ?></td>
          <td class="num small"><?= e((string) $row['chat_id']) ?></td>
          <td class="small"><a href="<?= e((string) $row['link']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $row['link']) ?></a></td>
          <td><?= (int) $row['is_active'] ? '<span class="badge badge-ok">فعال</span>' : '<span class="badge badge-muted">غیرفعال</span>' ?></td>
          <td>
            <form method="post" action="<?= e(url('admin/?r=channels')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= (int) $row['is_active'] ? 'غیرفعال' : 'فعال' ?></button>
            </form>
            <form method="post" action="<?= e(url('admin/?r=channels')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="btn btn-sm btn-bad" type="submit" data-confirm="این کانال حذف شود؟">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="6" class="empty">کانالی ثبت نشده است؛ کاربران بدون بررسی عضویت از ربات استفاده می‌کنند.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>تنظیمات گیت عضویت</h2></div>
  <form method="post" action="<?= e(url('admin/?r=channels')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="gate">
    <label class="check-row">
      <input type="checkbox" name="enabled" <?= $gate ? 'checked' : '' ?>>
      بررسی عضویت در کانال‌ها قبل از استفاده از ربات
    </label>
    <label class="check-row">
      <input type="checkbox" name="fail_open" <?= $failOpen ? 'checked' : '' ?>>
      اگر بررسی عضویت با خطا مواجه شد، کاربر مسدود نشود (توصیه: خاموش برای امنیت بیشتر کانال)
    </label>
    <button class="btn btn-primary" type="submit">ذخیره</button>
  </form>
</div>
