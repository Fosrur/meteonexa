<?php
declare(strict_types=1);

function meteonexa_sqlite_path(): string
{
    return meteonexa_runtime_sqlite_path();
}

function meteonexa_pdo_driver(PDO $pdo): string
{
    try { return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)); }
    catch (Throwable $ignored) { return 'sqlite'; }
}

function meteonexa_mysql_rewrite_sql(string $sql): string
{
    $sql = preg_replace('/^\s*BEGIN\s+IMMEDIATE\s*;?\s*$/i', 'START TRANSACTION', $sql) ?? $sql;
    $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\s+INTO\b/i', 'INSERT IGNORE INTO', $sql) ?? $sql;
    if (preg_match('/\s+ON\s+CONFLICT\s*\(([^)]+)\)\s+DO\s+UPDATE\s+SET\s+(.+)$/is', $sql, $match, PREG_OFFSET_CAPTURE)) {
        $offset = (int)$match[0][1];
        $prefix = substr($sql, 0, $offset);
        $update = trim((string)$match[2][0]);
        // SQLite permits an UPDATE-WHERE clause after the UPSERT assignment
        // list. MySQL performs the same write unconditionally; values are equal
        // in the only MeteoNexa use of this clause (app_version).
        $update = preg_replace('/\s+WHERE\s+[A-Za-z0-9_.]+\s*<>\s*excluded\.[A-Za-z0-9_]+\s*$/i', '', $update) ?? $update;
        $update = preg_replace('/\bexcluded\.([A-Za-z0-9_]+)\b/i', 'VALUES($1)', $update) ?? $update;
        $sql = $prefix . ' ON DUPLICATE KEY UPDATE ' . $update;
    }
    $sql = preg_replace('/\bCAST\(([^()]+)\s+AS\s+INTEGER\)/i', 'CAST($1 AS SIGNED)', $sql) ?? $sql;
    $sql = preg_replace('/\bAS\s+TEXT\b/i', 'AS CHAR', $sql) ?? $sql;
    return $sql;
}

final class MeteoNexaMySqlPDO extends PDO
{
    public function __construct(array $settings)
    {
        $host = trim((string)($settings['host'] ?? ''));
        $database = trim((string)($settings['database'] ?? ''));
        $username = trim((string)($settings['username'] ?? ''));
        $password = (string)($settings['password'] ?? '');
        $port = max(1, min(65535, (int)($settings['port'] ?? 3306)));
        if ($host === '' || $database === '' || $username === '') throw new RuntimeException('MYSQL_CONFIG_INVALID');
        parent::__construct(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>true,
                PDO::ATTR_TIMEOUT=>8,
            ]
        );
        $this->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        try { $this->exec("SET time_zone = '+00:00'"); } catch (Throwable $ignored) { }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(meteonexa_mysql_rewrite_sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = meteonexa_mysql_rewrite_sql($query);
        if ($fetchMode === null) return parent::query($query);
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(meteonexa_mysql_rewrite_sql($statement));
    }
}
