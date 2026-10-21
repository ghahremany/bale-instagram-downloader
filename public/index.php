<?php
/**
 * Public landing point. The bot itself talks to Bale (polling or webhook),
 * so there is nothing to publish here — visitors are sent to the panel.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (!App\Installer::isInstalled()) {
    redirect(App\Installer::installUrl());
}
redirect('admin/');
