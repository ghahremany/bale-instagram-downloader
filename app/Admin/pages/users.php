<?php
declare(strict_types=1);

use App\Admin\View;
use App\Audit;
use App\Users;

$pageTitle = 'کاربران';
$active = 'users';

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($id > 0 && in_array($action, ['block', 'unblock'], true)) {
        Users::setBlocked($id, $action === 'block');
        flash('ok', $action === 'block' ? 'کاربر مسدود شد.' : 'محدودیت کاربر برداشته شد.');
    }
    redirect('admin/?' . http_build_query(['r' => 'users', 'q' => $search, 'page' => $page]));
}

$rows = Users::paginate($page, $perPage, $search);
$total = Users::countSearch($search);
$pages = max(1, (int) ceil($total / $perPage));
?>

<div class="grid grid-3 mb">
  <?= View::stat('کل کاربران', number_format(Users::count())) ?>
  <?= View::stat('فعال در ۲۴ ساعت', number_format(Users::activeSince(gmdate('Y-m-d H:i:s', time() - 86400)))) ?>
  <?= View::stat('مسدود', number_format(Users::blockedCount()), '', 'bad') ?>
</div>

<div class="card">
  <div class="card-head">
    <h2>لیست کاربران (<?= number_format($total) ?>)</h2>
    <form method="get" action="<?= e(url('admin/')) ?>" class="row">
      <input type="hidden" name="r" value="users">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="جستجو: نام، یوزرنیم یا شناسه" style="min-width:240px">
      <button class="btn btn-sm btn-primary" type="submit">جستجو</button>
      <?php if ($search !== ''): ?>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/?r=users')) ?>">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>نام</th><th>یوزرنیم</th><th>شناسه بله</th><th>دانلود</th>
          <th>عضویت کانال</th><th>وضعیت</th><th>اولین‌بار</th><th>آخرین فعالیت</th><th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="num"><?= (int) $row['id'] ?></td>
          <td><?= e(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: '—') ?></td>
          <td class="small"><?= $row['username'] ? '@' . e((string) $row['username']) : '—' ?></td>
          <td class="num small"><?= e((string) $row['platform_user_id']) ?></td>
          <td class="num"><?= (int) $row['downloads_count'] ?></td>
          <td><?= (int) $row['is_joined'] ? '<span class="badge badge-ok">عضو</span>' : '<span class="badge badge-muted">نامشخص</span>' ?></td>
          <td><?= (int) $row['is_blocked'] ? '<span class="badge badge-bad">مسدود</span>' : '<span class="badge badge-ok">فعال</span>' ?></td>
          <td class="small"><?= e(format_dt((string) $row['created_at'])) ?></td>
          <td class="small"><?= e(format_dt($row['last_seen_at'] ? (string) $row['last_seen_at'] : null)) ?></td>
          <td>
            <form method="post" action="<?= e(url('admin/?r=users&page=' . $page)) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="action" value="<?= (int) $row['is_blocked'] ? 'unblock' : 'block' ?>">
              <button class="btn btn-sm <?= (int) $row['is_blocked'] ? 'btn-ok' : 'btn-bad' ?>" type="submit"
                <?= (int) $row['is_blocked'] ? '' : 'data-confirm="این کاربر مسدود شود؟"' ?>>
                <?= (int) $row['is_blocked'] ? 'رفع مسدودی' : 'مسدود' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="10" class="empty">کاربری پیدا نشد.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= View::pagination(['page' => $page, 'pages' => $pages, 'route' => 'users', 'params' => ['q' => $search]]) ?>
</div>

<div class="alert alert-info small">
  کاربران با اولین پیام در ربات به‌صورت خودکار ثبت می‌شوند. مسدود کردن کاربر، ارسال همگانی و پاسخ ربات به او را متوقف می‌کند.
</div>
