<?php
declare(strict_types=1);

namespace App;

final class Log
{
    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $file = $dir . '/app-' . gmdate('Y-m-d') . '.log';
        $line = sprintf("[%s] %s: %s", gmdate('c'), strtoupper($level), $message);
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void
    {
        self::write('info', $m, $c);
    }

    public static function warn(string $m, array $c = []): void
    {
        self::write('warning', $m, $c);
    }

    public static function error(string $m, array $c = []): void
    {
        self::write('error', $m, $c);
    }
}
