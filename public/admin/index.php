<?php
/**
 * Admin panel front controller: /admin/
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Admin\Router::run();
