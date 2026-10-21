<?php
declare(strict_types=1);

use App\Admin\View;
use App\Audit;

$pageTitle = 'گزارش رویدادها';
$active = 'audit';

$limit = max(20, min(500, (int) ($_GET['limit'] ?? 100)));
$rows = Audit::recent($limit);
$labels = [
    'login_success'       => ['ورود موفق', 'badge-ok'],
    'login_failed'        => ['ورود ناموفق', 'badge-bad'],
    'logout'              => ['خروج', 'badge-muted'],
    'password_changed'    => ['تغییر رمز', 'badge-info'],
    'settings_bot_updated' => ['تنظیمات ربات', 'badge-info'],
    'settings_instagram_updated' => ['تنظیمات اینستاگرام', 'badge-info'],
    'settings_behavior_updated' => ['تنظیمات دانلود', 'badge-info'],
    'settings_panel_updated' => ['تنظیمات پنل', 'badge-warn'],
    'settings_texts_updated' => ['ویرایش متن‌ها', 'badge-info'],
    'user_blocked'        => ['مسدودسازی کاربر', 'badge-warn'],
    'user_unblocked'      => ['رفع مسدودی', 'badge-ok'],
    'broadcast_created'   => ['کمپین جدید', 'badge-info'],
    'broadcast_finished'  => ['پایان کمپین', 'badge-ok'],
    'broadcast_canceled'  => ['لغو کمپین', 'badge-muted'],
    'channel_added'       => ['افزودن کانال', 'badge-info'],
    'channel_deleted'     => ['حذف کانال', 'badge-warn'],
    'admin_created'       => ['ساخت مدیر', 'badge-warn'],
    'admin_deleted'       => ['حذف مدیر', 'badge-bad'],
    'admin_password_reset' => ['بازنشانی رمز مدیر', 'badge-warn'],
    'admin_unlocked'      => ['رفع قفل حساب', 'badge-ok'],
    'sessions_revoked'    => ['ابطال نشست‌ها', 'badge-warn'],
    'webhook_set'         => ['تنظیم وب‌هوک', 'badge-info'],
    'webhook_deleted'     => ['حذف وب‌هوک', 'badge-muted'],
    'installed'           => ['نصب', 'badge-ok'],
    'ip_blocked'          => ['IP غیرمجاز', 'badge-bad'],
];
?>

<div class="card">
  <div class="card-head">
    <h2>رویدادهای پنل</h2>
    <form method="get" action="<?= e(url('admin/')) ?>" class="row">
      <input type="hidden" name="r" value="audit">
      <select name="limit" onchange="this.form.submit()">
        <?php foreach ([50, 100, 200, 500] as $option): ?>
          <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?> رکورد</option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>زمان</th><th>رویداد</th><th>کاربر مدیر</th><th>توضیح</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
          [$label, $class] = $labels[(string) $row['action']] ?? [(string) $row['action'], 'badge-muted'];
      ?>
        <tr>
          <td class="small"><?= e(format_dt((string) $row['created_at'])) ?></td>
          <td><span class="badge <?= $class ?>"><?= e($label) ?></span></td>
          <td class="small"><?= e((string) ($row['username'] ?? '—')) ?></td>
          <td class="small"><?= e((string) ($row['details'] ?? '')) ?></td>
          <td><code class="small"><?= e((string) ($row['ip'] ?? '—')) ?></code></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="5" class="empty">رویدادی ثبت نشده است.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-info small">
  رویدادها به‌صورت فقط‌افزودنی ثبت می‌شوند (بدون امکان ویرایش از پنل) تا ردپای تغییرات حفظ شود.
</div>
