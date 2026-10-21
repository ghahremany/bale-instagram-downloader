<?php
declare(strict_types=1);

use App\Admin\View;
use App\Broadcast;
use App\Db;
use App\Users;

$pageTitle = 'ارسال همگانی';
$active = 'broadcast';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    if ($action === 'cancel') {
        Broadcast::cancel((int) ($_POST['id'] ?? 0));
        flash('ok', 'کمپین لغو شد.');
    } else {
        $body = trim((string) ($_POST['body'] ?? ''));
        $mediaType = (string) ($_POST['media_type'] ?? '');
        $fileId = trim((string) ($_POST['media_file_id'] ?? ''));
        $buttonText = trim((string) ($_POST['button_text'] ?? ''));
        $buttonUrl = trim((string) ($_POST['button_url'] ?? ''));

        $problems = [];
        if ($body === '' && $fileId === '') {
            $problems[] = 'متن پیام یا شناسه فایل را وارد کنید.';
        }
        if (($buttonText === '') !== ($buttonUrl === '')) {
            $problems[] = 'متن دکمه و آدرس آن باید هر دو پر شوند.';
        }
        if ($buttonUrl !== '' && !preg_match('~^https://~i', $buttonUrl)) {
            $problems[] = 'آدرس دکمه باید با https شروع شود.';
        }
        if ($fileId !== '' && !in_array($mediaType, ['photo', 'video', 'audio', 'document'], true)) {
            $problems[] = 'برای فایل، نوع رسانه را انتخاب کنید.';
        }
        if ($problems === []) {
            $id = Broadcast::create([
                'body'          => $body,
                'media_type'    => $fileId !== '' ? $mediaType : '',
                'media_file_id' => $fileId,
                'button_text'   => $buttonText,
                'button_url'    => $buttonUrl,
            ], \App\Auth::id());
            flash('ok', 'کمپین شماره ' . $id . ' در صف قرار گرفت. با کرون یا دکمه «اجرای صف» ارسال می‌شود.');
        } else {
            flash('error', implode(' ', $problems));
        }
    }
    redirect('admin/?r=broadcast');
}

$jobs = Broadcast::recent(10);
$totalUsers = Users::count();
$pendingTargets = (int) (Db::value("SELECT COUNT(*) FROM broadcast_targets WHERE status = 'pending'") ?? 0);
?>

<div class="grid grid-3 mb">
  <?= View::stat('گیرندگان بالقوه', number_format($totalUsers), 'کاربران غیرمسدود') ?>
  <?= View::stat('در انتظار ارسال', number_format($pendingTargets), '', $pendingTargets > 0 ? 'warn' : '') ?>
  <?= View::stat('کمپین‌های اخیر', number_format(count($jobs))) ?>
</div>

<div class="card">
  <div class="card-head"><h2>ساخت پیام همگانی</h2></div>
  <form method="post" action="<?= e(url('admin/?r=broadcast')) ?>" data-busy>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <label>متن پیام (HTML ساده مجاز است: &lt;b&gt; &lt;i&gt; &lt;a&gt; &lt;code&gt;)
      <textarea name="body" placeholder="متن پیام…"></textarea>
      <small>برای ارسال هر کاربر متغیر <code>{name}</code> پشتیبانی نمی‌شود؛ متن ثابت ارسال می‌شود.</small>
    </label>

    <div class="grid-2">
      <label>نوع رسانه (اختیاری)
        <select name="media_type">
          <option value="">بدون فایل</option>
          <option value="photo">تصویر</option>
          <option value="video">ویدیو</option>
          <option value="audio">صدا</option>
          <option value="document">فایل</option>
        </select>
      </label>
      <label>شناسه فایل در بله (file_id)
        <input type="text" name="media_file_id" dir="ltr" placeholder="file_id">
        <small>یک فایل را با همان توکن به ربات بفرستید و file_id را از لاگ/ابزارها بردارید.</small>
      </label>
      <label>متن دکمه (اختیاری)
        <input type="text" name="button_text" maxlength="64">
      </label>
      <label>آدرس دکمه (https)
        <input type="url" name="button_url" dir="ltr" placeholder="https://t.me/…">
      </label>
    </div>

    <div class="alert alert-warning small">
      ارسال با سرعت کنترل‌شده و از طریق صف انجام می‌شود تا محدودیت‌های API رعایت شود.
      اجرای خودکار: کرون هر دقیقه دستور <code dir="ltr">php cli/queue.php 40</code>.
    </div>

    <button class="btn btn-primary btn-lg" type="submit" data-confirm="ارسال برای همه کاربران در صف قرار گیرد؟">افزودن به صف</button>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h2>کمپین‌ها</h2>
    <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_queue">
      <button class="btn btn-sm btn-primary" type="submit">اجرای صف همین حالا</button>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>وضعیت</th><th>متن</th><th>پیشرفت</th><th>موفق</th><th>ناموفق</th><th>ایجاد</th><th>پایان</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($jobs as $job):
          $total = max(1, (int) $job['total']);
          $done = (int) $job['sent'] + (int) $job['failed'];
          $percent = min(100, (int) round($done * 100 / $total));
      ?>
        <tr>
          <td class="num"><?= (int) $job['id'] ?></td>
          <td><?= View::statusBadge((string) $job['status']) ?></td>
          <td class="small" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= e(mb_substr((string) ($job['body'] ?? ''), 0, 70)) ?>
          </td>
          <td style="min-width:130px">
            <div class="progress"><span style="width:<?= $percent ?>%"></span></div>
            <div class="small muted"><?= $done ?>/<?= (int) $job['total'] ?></div>
          </td>
          <td class="num"><?= (int) $job['sent'] ?></td>
          <td class="num"><?= (int) $job['failed'] ?></td>
          <td class="small"><?= e(format_dt((string) $job['created_at'])) ?></td>
          <td class="small"><?= e($job['finished_at'] ? format_dt((string) $job['finished_at']) : '—') ?></td>
          <td>
            <?php if (in_array($job['status'], ['queued', 'running'], true)): ?>
              <form method="post" action="<?= e(url('admin/?r=broadcast')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                <button class="btn btn-sm btn-bad" type="submit" data-confirm="این کمپین لغو شود؟">لغو</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($jobs === []): ?>
        <tr><td colspan="9" class="empty">هنوز کمپینی ساخته نشده است.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
