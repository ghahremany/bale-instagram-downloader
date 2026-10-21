<?php
declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = false;
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return;
        }
        self::$values = self::parse($raw);
        self::$loaded = true;
    }

    /** @return array<string,string> */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                    if ($first === '"') {
                        $value = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value);
                    }
                }
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function setDefault(string $key, string $value): void
    {
        if (!isset(self::$values[$key]) || self::$values[$key] === '') {
            self::$values[$key] = $value;
        }
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::$values[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : (int) $v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required config key: {$key}");
        }
        return $v;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::$values;
    }
}
