<?php
declare(strict_types=1);

use App\Admin\View;
use App\Bale;
use App\Broadcast;
use App\Channels;
use App\Db;
use App\Downloads;
use App\Settings;
use App\Users;

$pageTitle = 'داشبورد';
$active = 'dashboard';

$today = gmdate('Y-m-d 00:00:00');
$week = gmdate('Y-m-d 00:00:00', time() - 7 * 86400);
$daily = Downloads::daily(14);
$maxDaily = max(1, max(array_map(static fn($d) => $d['total'], $daily)));
$breakdown = Downloads::statusBreakdown($week);

$botReady = Bale::fromSettings()->isConfigured();
$apiReady = trim(Settings::get('ig_api_key')) !== '';
$channels = Channels::active();
$pendingBroadcasts = (int) (Db::value("SELECT COUNT(*) FROM broadcasts WHERE status IN ('queued','running')") ?? 0);
$lastDownload = Db::first('SELECT created_at FROM downloads ORDER BY id DESC LIMIT 1');
$lastError = Db::first("SELECT created_at, error_message FROM downloads WHERE status IN ('failed','fallback') ORDER BY id DESC LIMIT 1");
?>

<div class="grid grid-4 mb">
  <?= View::stat('کاربران', number_format(Users::count()), 'امروز: ' . number_format(Users::countSince($today)) . ' نفر') ?>
  <?= View::stat('دانلود امروز', number_format(Downloads::countSince($today)), 'کل: ' . number_format(Downloads::total())) ?>
  <?= View::stat('نرخ موفقیت (۷ روز)', number_format(Downloads::successRate($week), 1) . '%', 'موفق: ' . number_format($breakdown['success']), 'ok') ?>
  <?= View::stat('آخرین دانلود', $lastDownload ? format_dt((string) $lastDownload['created_at']) : '—', $lastError ? 'آخرین خطا: ' . format_dt((string) $lastError['created_at']) : 'بدون خطا', '') ?>
</div>

<div class="grid" style="grid-template-columns:2fr 1fr">
  <div class="card">
    <div class="card-head">
      <h2>روند دانلود ۱۴ روز اخیر</h2>
      <span class="badge badge-info">جمع: <?= number_format(array_sum(array_column($daily, 'total'))) ?></span>
    </div>
    <div class="spark" aria-hidden="true">
      <?php foreach ($daily as $day): ?>
        <span title="<?= e($day['day'] . ' — ' . $day['total']) ?>"
              style="height:<?= e((string) max(3, (int) round($day['total'] * 100 / $maxDaily))) ?>%"></span>
      <?php endforeach; ?>
    </div>
    <div class="kv-inline mt small">
      <span>بیشترین روز: <?= e((string) max(array_column($daily, 'total'))) ?></span>
      <span>میانگین: <?= e((string) round(array_sum(array_column($daily, 'total')) / max(1, count($daily)), 1)) ?></span>
    </div>
    <div class="table-wrap mt">
      <table>
        <thead><tr><th>وضعیت (۷ روز)</th><th>تعداد</th><th>سهم</th></tr></thead>
        <tbody>
          <?php
          $labels = ['success' => 'موفق', 'partial' => 'ناقص', 'fallback' => 'لینک جایگزین', 'failed' => 'ناموفق'];
          $sum = max(1, array_sum($breakdown));
          foreach ($labels as $key => $label):
              $value = $breakdown[$key] ?? 0;
          ?>
            <tr>
              <td><?= View::statusBadge($key) ?></td>
              <td class="num"><?= number_format($value) ?></td>
              <td class="num"><?= number_format($value * 100 / $sum, 1) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>سلامت سیستم</h2></div>
    <div class="kv">
      <div><span>ربات بله</span><?= $botReady ? '<span class="badge badge-ok">متصل</span>' : '<span class="badge badge-bad">تنظیم نشده</span>' ?></div>
      <div><span>سرویس اینستاگرام</span><?= $apiReady ? '<span class="badge badge-ok">کلید ثبت شده</span>' : '<span class="badge badge-warn">کلید خالی</span>' ?></div>
      <div><span>کانال‌های عضویت اجباری</span><span class="badge badge-info"><?= count($channels) ?> کانال</span></div>
      <div><span>ارسال همگانی در صف</span><span class="badge <?= $pendingBroadcasts ? 'badge-warn' : 'badge-muted' ?>"><?= $pendingBroadcasts ?></span></div>
      <div><span>حالت دریافت پیام</span><span class="badge badge-muted"><?= e(strtoupper((string) \App\Config::get('BOT_MODE', 'polling'))) ?></span></div>
      <div><span>PHP</span><span class="badge badge-muted"><?= e(PHP_VERSION) ?></span></div>
    </div>
    <div class="row mt">
      <a class="btn btn-sm btn-primary" href="<?= e(url('admin/?r=tools')) ?>">تست اتصال ربات</a>
      <a class="btn btn-sm" href="<?= e(url('admin/?r=settings')) ?>">تنظیمات</a>
    </div>
    <?php if (!$botReady): ?>
      <div class="alert alert-warning small mt">توکن ربات ثبت نشده است؛ ربات به پیام‌ها پاسخ نمی‌دهد.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>آخرین دانلودها</h2>
    <a class="btn btn-sm" href="<?= e(url('admin/?r=downloads')) ?>">همه</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>زمان</th><th>کاربر</th><th>نوع</th><th>وضعیت</th><th>فایل</th><th>حجم</th><th>زمان پردازش</th></tr>
      </thead>
      <tbody>
        <?php foreach (Downloads::recent(12) as $row): ?>
          <tr>
            <td class="small"><?= e(format_dt((string) $row['created_at'])) ?></td>
            <td><?= e((string) ($row['username'] ?? $row['platform_user_id'] ?? '—')) ?></td>
            <td><span class="badge badge-muted"><?= e(media_label($row['media_type'] ?? null)) ?></span></td>
            <td><?= View::statusBadge((string) $row['status']) ?></td>
            <td class="num"><?= (int) $row['files_sent'] ?>/<?= (int) $row['files_total'] ?></td>
            <td class="num"><?= e(human_bytes((int) $row['bytes'])) ?></td>
            <td class="num"><?= number_format((int) $row['duration_ms'] / 1000, 1) ?>s</td>
          </tr>
        <?php endforeach; ?>
        <?php if (Downloads::total() === 0): ?>
          <tr><td colspan="7" class="empty">هنوز دانلودی ثبت نشده است. ربات را در بله تست کنید.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
