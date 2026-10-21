<?php
declare(strict_types=1);

use App\Admin\View;
use App\Audit;
use App\Bale;
use App\Broadcast;
use App\Db;
use App\Downloader;
use App\Instagram;
use App\Settings;

$pageTitle = 'ابزارها';
$active = 'tools';

$output = [];
$bot = Bale::fromSettings();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'run_queue':
                $result = Broadcast::run(40);
                $output[] = $result['job']
                    ? 'صف شماره ' . $result['job'] . ': ' . $result['sent'] . ' ارسال، ' . $result['failed'] . ' خطا' . ($result['done'] ? ' — پایان یافت.' : '.')
                    : 'هیچ کمپین در صفی وجود ندارد.';
                Audit::log('queue_run', 'manual queue run');
                break;

            case 'test_bot':
                $me = $bot->getMe();
                $output[] = ($me['ok'] ?? false) === true
                    ? '✅ اتصال برقرار است — ربات: @' . (string) ($me['result']['username'] ?? '?') . ' (id: ' . (string) ($me['result']['id'] ?? '?') . ')'
                    : '❌ پاسخ ناموفق: ' . (string) ($me['description'] ?? 'نامشخص');
                break;

            case 'send_test':
                $chatId = trim((string) ($_POST['chat_id'] ?? Settings::get('otp_chat_id')));
                if (!preg_match('/^-?\d{4,20}$/', $chatId)) {
                    $output[] = '❌ شناسه چت نامعتبر است.';
                    break;
                }
                $res = $bot->sendMessage($chatId, "✅ پیام آزمایشی از پنل مدیریت\n" . gmdate('Y-m-d H:i:s') . ' UTC');
                $output[] = ($res['ok'] ?? false) ? '✅ پیام آزمایشی ارسال شد.' : '❌ ارسال ناموفق: ' . (string) ($res['description'] ?? '');
                break;

            case 'set_webhook':
                $secret = Settings::get('webhook_secret');
                if ($secret === '') {
                    $secret = bin2hex(random_bytes(24));
                    Settings::set('webhook_secret', $secret);
                }
                $url = url('bot.php?secret=' . $secret);
                $res = $bot->setWebhook($url, $secret);
                $output[] = ($res['ok'] ?? false) === true
                    ? '✅ وب‌هوک تنظیم شد: ' . $url
                    : '❌ تنظیم وب‌هوک ناموفق: ' . (string) ($res['description'] ?? '');
                Audit::log('webhook_set', 'webhook configured');
                break;

            case 'delete_webhook':
                $res = $bot->deleteWebhook();
                $output[] = ($res['ok'] ?? false) === true ? '✅ وب‌هوک حذف شد؛ ربات به حالت polling برمی‌گردد.' : '❌ حذف ناموفق.';
                Audit::log('webhook_deleted', 'webhook removed');
                break;

            case 'cleanup':
                $n = Downloader::gcTmp(0);
                Db::run('DELETE FROM processed_updates WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
                Db::run('DELETE FROM rate_limits WHERE expires_at < ?', [time()]);
                $output[] = '🧹 ' . $n . ' فایل موقت حذف شد و رکوردهای قدیمی پاک‌سازی شدند.';
                Audit::log('cleanup', 'tmp files: ' . $n);
                break;

            case 'test_instagram':
                $url = Instagram::normalizeUrl((string) ($_POST['url'] ?? ''));
                if ($url === null || !Instagram::isPostUrl($url)) {
                    $output[] = '❌ لینک اینستاگرام معتبر نیست.';
                    break;
                }
                $post = Instagram::fetch($url);
                $output[] = ($post['ok'] ?? false)
                    ? '✅ پست خوانده شد — عنوان: ' . mb_substr((string) $post['title'], 0, 40)
                        . ' | نویسنده: ' . $post['author'] . ' | فایل‌ها: ' . count($post['files'])
                    : '❌ خطا: ' . (string) ($post['error'] ?? '');
                break;

            case 'new_webhook_secret':
                Settings::set('webhook_secret', bin2hex(random_bytes(24)));
                $output[] = '✅ کلید وب‌هوک جدید ساخته شد. اگر وب‌هوک فعال است، دوباره تنظیمش کنید.';
                Audit::log('webhook_secret_rotated', 'new webhook secret');
                break;

            default:
                $output[] = 'عملیات ناشناخته.';
        }
    } catch (\Throwable $e) {
        $output[] = '❌ ' . $e->getMessage();
        Audit::log('tool_error', $action . ': ' . mb_substr($e->getMessage(), 0, 200));
    }
}

$webhook = $bot->isConfigured() ? (function () use ($bot) {
    try {
        $info = $bot->call('getWebhookInfo');
        return $info['result'] ?? [];
    } catch (\Throwable) {
        return [];
    }
})() : [];

$tables = [];
try {
    foreach (Db::all('SHOW TABLE STATUS') as $row) {
        $tables[] = ['name' => (string) $row['Name'], 'rows' => (int) $row['Rows'], 'size' => (int) $row['Data_length'] + (int) $row['Index_length']];
    }
} catch (\Throwable) {
}
$tmpCount = count(glob(APP_ROOT . '/storage/tmp/*') ?: []);
$tmpSize = 0;
foreach (glob(APP_ROOT . '/storage/tmp/*') ?: [] as $f) {
    $tmpSize += (int) @filesize($f);
}
?>

<?php if ($output !== []): ?>
  <div class="card">
    <div class="card-head"><h2>نتیجه اجرا</h2></div>
    <?php foreach ($output as $line): ?>
      <div class="alert <?= str_starts_with($line, '❌') ? 'alert-error' : (str_starts_with($line, '🧹') || str_starts_with($line, '✅') ? 'alert-ok' : 'alert-info') ?>">
        <?= e($line) ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="card-head"><h2>ربات و وب‌هوک</h2></div>
    <div class="kv">
      <div><span>توکن</span><?= $bot->isConfigured() ? '<span class="badge badge-ok">تنظیم شده</span>' : '<span class="badge badge-bad">خالی</span>' ?></div>
      <div><span>نام ربات</span><span class="small"><?= e(Settings::get('bot_username') !== '' ? '@' . Settings::get('bot_username') : '—') ?></span></div>
      <div><span>آدرس وب‌هوک</span><code class="small"><?= e((string) ($webhook['url'] ?? 'تنظیم نشده')) ?></code></div>
      <?php if (!empty($webhook['last_error_message'])): ?>
        <div><span>آخرین خطای وب‌هوک</span><span class="small"><?= e((string) $webhook['last_error_message']) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="row">
      <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="test_bot">
        <button class="btn btn-sm btn-primary" type="submit">تست اتصال (getMe)</button>
      </form>
      <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_webhook">
        <button class="btn btn-sm" type="submit" data-confirm="وب‌هوک روی این دامنه تنظیم شود؟ (سرور باید HTTPS معتبر داشته باشد)">تنظیم وب‌هوک</button>
      </form>
      <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_webhook">
        <button class="btn btn-sm btn-bad" type="submit" data-confirm="وب‌هوک حذف شود؟">حذف وب‌هوک</button>
      </form>
    </div>
    <form method="post" action="<?= e(url('admin/?r=tools')) ?>" class="mt">
      <?= csrf_field() ?><input type="hidden" name="action" value="send_test">
      <label>ارسال پیام آزمایشی به شناسه چت
        <input type="text" name="chat_id" value="<?= e(Settings::get('otp_chat_id')) ?>" dir="ltr" placeholder="123456789">
      </label>
      <button class="btn btn-sm" type="submit">ارسال پیام تست</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h2>صف ارسال و نگهداری</h2></div>
    <p class="muted small">
      کمپین‌ها به‌صورت پیش‌فرض با کرون اجرا می‌شوند
      (<code dir="ltr">* * * * * php /path/cli/queue.php 40</code>).
      با دکمه زیر می‌توانید فوراً یک دسته را اجرا کنید.
    </p>
    <div class="row mb">
      <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="run_queue">
        <button class="btn btn-sm btn-primary" type="submit">اجرای صف (۴۰ پیام)</button>
      </form>
      <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="new_webhook_secret">
        <button class="btn btn-sm" type="submit" data-confirm="کلید وب‌هوک تغییر کند؟">چرخش کلید وب‌هوک</button>
      </form>
      <form method="post" action="<?= e(url('admin/?r=tools')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="cleanup">
        <button class="btn btn-sm" type="submit">پاک‌سازی فایل‌های موقت</button>
      </form>
    </div>
    <div class="kv">
      <div><span>فایل‌های موقت</span><span class="small"><?= $tmpCount ?> فایل — <?= e(human_bytes($tmpSize)) ?></span></div>
      <div><span>وضعیت کرون</span><span class="badge badge-muted">دستی بررسی کنید</span></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>تست سرویس اینستاگرام</h2></div>
  <form method="post" action="<?= e(url('admin/?r=tools')) ?>" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_instagram">
    <input type="url" name="url" placeholder="https://www.instagram.com/reel/…" dir="ltr" style="flex:1;min-width:260px">
    <button class="btn btn-primary" type="submit">تست دریافت پست</button>
  </form>
  <p class="muted small mt">این تست فقط اطلاعات پست را می‌خواند و هیچ فایلی برای کاربران ارسال نمی‌کند.</p>
</div>

<div class="card">
  <div class="card-head"><h2>وضعیت پایگاه داده</h2><span class="badge badge-info"><?= e((string) \App\Config::get('DB_NAME', '')) ?></span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>جدول</th><th>تعداد ردیف</th><th>حجم</th></tr></thead>
      <tbody>
      <?php foreach ($tables as $t): ?>
        <tr>
          <td><code><?= e($t['name']) ?></code></td>
          <td class="num"><?= number_format($t['rows']) ?></td>
          <td class="num"><?= e(human_bytes($t['size'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>دستورهای CLI</h2></div>
  <div class="kv">
    <div><span>کرون صف</span><code dir="ltr">php cli/queue.php 40</code></div>
    <div><span>دریافت پیام‌ها (polling)</span><code dir="ltr">php cli/poll.php</code></div>
    <div><span>مشاهده آخرین کد ورود (حالت فایل)</span><code dir="ltr">php cli/console.php otp:last</code></div>
    <div><span>ساخت کد فعال‌سازی جدید</span><code dir="ltr">php cli/console.php otp:new-activation</code></div>
  </div>
</div>
