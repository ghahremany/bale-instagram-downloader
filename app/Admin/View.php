<?php
declare(strict_types=1);

namespace App\Admin;

use App\AppName;
use App\Auth;

final class View
{
    /** @var array<string,array{0:string,1:string}> route => [label, icon] */
    private const NAV = [
        'dashboard' => ['داشبورد', '📊'],
        'users'     => ['کاربران', '👥'],
        'downloads' => ['دانلودها', '📥'],
        'broadcast' => ['ارسال همگانی', '📢'],
        'channels'  => ['کانال‌ها', '📣'],
        'settings'  => ['تنظیمات', '⚙️'],
        'security'  => ['امنیت', '🛡'],
        'admins'    => ['مدیران', '🔑'],
        'tools'     => ['ابزارها', '🧰'],
        'audit'     => ['گزارش رویدادها', '📜'],
    ];

    private static function flashes(): string
    {
        $html = '';
        foreach (take_flashes() as $flash) {
            $cls = match ($flash['type']) {
                'ok', 'success' => 'alert-ok',
                'error', 'bad'  => 'alert-error',
                'warn'          => 'alert-warning',
                default         => 'alert-info',
            };
            $html .= '<div class="alert ' . $cls . '">' . e($flash['message']) . '</div>';
        }
        return $html;
    }

    public static function layout(string $title, string $active, string $content): string
    {
        $user = Auth::user() ?? [];
        $name = (string) ($user['display_name'] ?? $user['username'] ?? '');
        $initial = mb_substr($name !== '' ? $name : 'م', 0, 1);
        $role = (string) ($user['role'] ?? '');
        $nav = '';
        foreach (self::NAV as $route => $meta) {
            [$label, $icon] = $meta;
            if ($route === 'admins' && !Auth::isOwner()) {
                continue;
            }
            $nav .= '<a href="' . e(url('admin/?r=' . $route)) . '" class="' . ($active === $route ? 'active' : '') . '">'
                . '<span class="ico">' . $icon . '</span>' . e($label) . '</a>';
        }

        $titleEsc = e($title);
        $panelTitle = e(AppName::title());
        $version = e(APP_VERSION);
        $asset = e(url('assets/admin.css'));
        $logout = e(url('admin/?r=logout'));
        $csrf = csrf_field();
        $js = e(url('assets/admin.js'));
        $flashes = self::flashes();

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$titleEsc} | {$panelTitle}</title>
<link rel="stylesheet" href="{$asset}">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-badge">بله</div>
      <div>
        <div class="brand-name">{$panelTitle}</div>
        <div class="brand-sub">پنل مدیریت v{$version}</div>
      </div>
    </div>
    <nav class="nav">
      <div class="nav-title">مدیریت</div>
      {$nav}
      <div class="nav-title">حساب</div>
      <a href="{$logout}" onclick="event.preventDefault();document.getElementById('logout-form').submit()">
        <span class="ico">🚪</span>خروج از حساب
      </a>
    </nav>
    <div class="sidebar-foot">
      نسخه {$version}<br>
      <span class="muted">Bale Instagram Downloader</span>
    </div>
  </aside>
  <main class="main">
    <div class="topbar">
      <div>
        <div class="crumbs">پنل مدیریت / {$titleEsc}</div>
        <div class="page-title">{$titleEsc}</div>
      </div>
      <div class="user-chip">
        <span class="avatar">{$initial}</span>
        <span>{$name}</span>
        <span class="badge badge-info">{$role}</span>
      </div>
    </div>
    {$flashes}
    {$content}
  </main>
</div>
<form id="logout-form" method="post" action="{$logout}" style="display:none">{$csrf}</form>
<script src="{$js}" defer></script>
</body>
</html>
HTML;
    }

    public static function authLayout(string $title, string $content, string $subtitle = '', bool $wide = false): string
    {
        $titleEsc = e($title);
        $subtitleEsc = e($subtitle);
        $panelTitle = e(AppName::title());
        $wrapClass = $wide ? 'auth-wrap install-wrap' : 'auth-wrap';
        $css = e(url('assets/admin.css'));
        $version = e(APP_VERSION);
        $flashes = self::flashes();

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$titleEsc} | {$panelTitle}</title>
<link rel="stylesheet" href="{$css}">
</head>
<body class="auth-body">
<div class="{$wrapClass}">
  <div class="auth-card">
    <div class="auth-head">
      <div class="brand-badge">بله</div>
      <h1>{$titleEsc}</h1>
      <p>{$panelTitle}{$subtitleEsc}</p>
    </div>
    {$flashes}
    {$content}
    <div class="auth-foot"><span>نسخه {$version}</span><span>•</span><span>ورود امن</span></div>
  </div>
</div>
</body>
</html>
HTML;
    }

    public static function stat(string $label, string $value, string $foot = '', string $tone = ''): string
    {
        $toneClass = $tone !== '' ? ' ' . $tone : '';
        return '<div class="stat' . $toneClass . '"><div class="stat-label">' . e($label) . '</div>'
            . '<div class="stat-value">' . e($value) . '</div>'
            . ($foot !== '' ? '<div class="stat-foot">' . e($foot) . '</div>' : '') . '</div>';
    }

    public static function statusBadge(string $status): string
    {
        [$class, $label] = match ($status) {
            'success'  => ['badge-ok', 'موفق'],
            'partial'  => ['badge-warn', 'ناقص'],
            'fallback' => ['badge-info', 'لینک جایگزین'],
            'failed'   => ['badge-bad', 'ناموفق'],
            'running'  => ['badge-info', 'در حال ارسال'],
            'queued'   => ['badge-muted', 'در صف'],
            'done'     => ['badge-ok', 'پایان‌یافته'],
            'canceled' => ['badge-muted', 'لغو شده'],
            default    => ['badge-muted', (string) $status],
        };
        return '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }

    /** @param array{page:int,pages:int,route:string,params:array<string,string>} $p */
    public static function pagination(array $p): string
    {
        if ($p['pages'] <= 1) {
            return '';
        }
        $build = static function (int $page) use ($p): string {
            return url('admin/?' . http_build_query(array_merge(
                ['r' => $p['route'], 'page' => (string) $page],
                $p['params']
            )));
        };
        $html = '<div class="pagination">';
        if ($p['page'] > 1) {
            $html .= '<a href="' . e($build($p['page'] - 1)) . '">قبلی</a>';
        }
        foreach (range(max(1, $p['page'] - 2), min($p['pages'], $p['page'] + 2)) as $i) {
            $html .= $i === $p['page']
                ? '<span class="current">' . $i . '</span>'
                : '<a href="' . e($build($i)) . '">' . $i . '</a>';
        }
        if ($p['page'] < $p['pages']) {
            $html .= '<a href="' . e($build($p['page'] + 1)) . '">بعدی</a>';
        }
        return $html . '</div>';
    }

    public static function csrf(): string
    {
        return csrf_field();
    }
}
