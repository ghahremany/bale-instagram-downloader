<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) Config::get('DB_HOST', '127.0.0.1'),
            Config::int('DB_PORT', 3306),
            (string) Config::get('DB_NAME', 'bale_ig')
        );
        try {
            self::$pdo = new PDO($dsn, (string) Config::get('DB_USER', ''), (string) Config::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES', time_zone='+00:00'",
            ]);
        } catch (PDOException $e) {
            Log::error('DB connection failed: ' . $e->getMessage());
            throw new RuntimeException('اتصال به پایگاه داده برقرار نشد.');
        }
        return self::$pdo;
    }

    public static function connect(string $host, int $port, string $user, string $pass, ?string $db = null): PDO
    {
        $dsn = "mysql:host={$host};port={$port}" . ($db ? ";dbname={$db}" : '') . ';charset=utf8mb4';
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($data)));
        $stmt = self::run(
            "UPDATE `{$table}` SET {$sets} WHERE {$where}",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    public static function tableExists(string $table): bool
    {
        try {
            $row = self::first('SHOW TABLES LIKE ?', [$table]);
            return $row !== null;
        } catch (PDOException) {
            return false;
        }
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
