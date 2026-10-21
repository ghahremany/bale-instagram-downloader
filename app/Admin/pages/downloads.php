<?php
declare(strict_types=1);

use App\Admin\View;
use App\Audit;
use App\Downloads;
use App\RateLimiter;

$pageTitle = 'دانلودها';
$active = 'downloads';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'purge') {
        $days = max(1, min(365, (int) ($_POST['days'] ?? 30)));
        $removed = Downloads::purgeOlderThan($days);
        Audit::log('downloads_purged', "removed {$removed} rows older than {$days}d");
        flash('ok', $removed . ' رکورد قدیمی‌تر از ' . $days . ' روز حذف شد.');
    } elseif ($action === 'reset_limit') {
        RateLimiter::clear('dl:u:' . (int) ($_POST['user_id'] ?? 0));
        flash('ok', 'محدودیت ساعتی این کاربر بازنشانی شد.');
    }
    redirect('admin/?r=downloads');
}

$status = (string) ($_GET['status'] ?? '');
$rows = Downloads::recent(150);
if ($status !== '') {
    $rows = array_values(array_filter($rows, static fn($r) => $r['status'] === $status));
}
$week = gmdate('Y-m-d 00:00:00', time() - 7 * 86400);
$breakdown = Downloads::statusBreakdown($week);
?>

<div class="grid grid-4 mb">
  <?= View::stat('کل دانلودها', number_format(Downloads::total())) ?>
  <?= View::stat('۷ روز اخیر', number_format(Downloads::countSince($week))) ?>
  <?= View::stat('موفق', number_format($breakdown['success']), '', 'ok') ?>
  <?= View::stat('ناموفق', number_format($breakdown['failed']), '', 'bad') ?>
</div>

<div class="card">
  <div class="card-head">
    <h2>گزارش دانلودها</h2>
    <div class="tabs" style="margin:0">
      <?php foreach (['' => 'همه', 'success' => 'موفق', 'partial' => 'ناقص', 'fallback' => 'لینک جایگزین', 'failed' => 'ناموفق'] as $key => $label): ?>
        <a class="<?= $status === $key ? 'active' : '' ?>"
           href="<?= e(url('admin/?r=downloads' . ($key !== '' ? '&status=' . $key : ''))) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>زمان</th><th>کاربر</th><th>لینک</th><th>نوع</th><th>وضعیت</th><th>فایل</th><th>حجم</th><th>مدت</th><th>خطا</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="small"><?= e(format_dt((string) $row['created_at'])) ?></td>
          <td class="small"><?= e((string) ($row['username'] ?? $row['platform_user_id'] ?? '—')) ?></td>
          <td class="small" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?php if (!empty($row['shortcode'])): ?>
              <a href="<?= e((string) $row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $row['shortcode']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><span class="badge badge-muted"><?= e(media_label($row['media_type'] ?? null)) ?></span></td>
          <td><?= View::statusBadge((string) $row['status']) ?></td>
          <td class="num"><?= (int) $row['files_sent'] ?>/<?= (int) $row['files_total'] ?></td>
          <td class="num"><?= e(human_bytes((int) $row['bytes'])) ?></td>
          <td class="num"><?= number_format((int) $row['duration_ms'] / 1000, 1) ?>s</td>
          <td class="small"><?= e(mb_substr((string) ($row['error_message'] ?? ''), 0, 60)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="9" class="empty">رکوردی برای نمایش نیست.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>نگهداری داده‌ها</h2></div>
  <form method="post" action="<?= e(url('admin/?r=downloads')) ?>" class="row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="purge">
    <label style="margin:0">حذف رکوردهای قدیمی‌تر از
      <input type="number" name="days" value="30" min="1" max="365" style="width:110px">
    </label>
    <button class="btn btn-sm btn-bad" type="submit" data-confirm="حذف رکوردهای قدیمی قابل بازگشت نیست. ادامه می‌دهید؟">حذف</button>
  </form>
  <p class="muted small mt">برای جلوگیری از رشد بی‌رویه پایگاه داده، این کار را در کرون ماهانه انجام دهید.</p>
</div>
