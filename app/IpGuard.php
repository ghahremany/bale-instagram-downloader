<?php
declare(strict_types=1);

namespace App;

final class IpGuard
{
    /** Optional IP allow-list for the admin panel (empty list = allow everyone). */
    public static function allowed(string $ip): bool
    {
        $raw = trim(Settings::get('ip_allowlist'));
        if ($raw === '') {
            return true;
        }
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::inCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($entry === $ip) {
                return true;
            }
        }
        return false;
    }

    /** Too many failed logins from this IP inside the window. */
    public static function blocked(string $ip, int $windowMinutes = 60, int $threshold = 20): bool
    {
        try {
            $since = gmdate('Y-m-d H:i:s', time() - $windowMinutes * 60);
            $count = (int) Db::value(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?',
                [$ip, $since]
            );
            return $count >= $threshold;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = array_pad(explode('/', $cidr, 2), 2, null);
        $bits = (int) $bitsRaw;
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
    }
}
