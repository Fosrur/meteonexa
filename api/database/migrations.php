<?php
declare(strict_types=1);

/**
 * Versioned database migration registry.
 *
 * Revisions 16..26 live in one file per schema revision under
 * api/database/migrations/. SQLite installations older than revision 16 still
 * pass through the quarantined legacy compatibility upgrader; all new schema
 * revisions must be added as standalone migration descriptors.
 */
function meteonexa_current_schema_version(): int
{
    return 28;
}

/** @return list<array{version:int,name:string,drivers:list<string>,up:Closure}> */
function meteonexa_database_migrations(): array
{
    static $migrations = null;
    if (is_array($migrations)) {
        return $migrations;
    }

    $files = glob(__DIR__ . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
    sort($files, SORT_STRING);
    $loaded = [];
    $previousVersion = 15;

    foreach ($files as $file) {
        $migration = require $file;
        if (!is_array($migration)) {
            throw new RuntimeException('DB_MIGRATION_DESCRIPTOR_INVALID');
        }

        $version = (int)($migration['version'] ?? 0);
        $name = trim((string)($migration['name'] ?? ''));
        $drivers = array_values(array_unique(array_map('strval', (array)($migration['drivers'] ?? []))));
        $up = $migration['up'] ?? null;

        if ($version !== $previousVersion + 1 || $name === '' || !$up instanceof Closure) {
            throw new RuntimeException('DB_MIGRATION_SEQUENCE_INVALID');
        }
        foreach ($drivers as $driver) {
            if (!in_array($driver, ['mysql', 'sqlite'], true)) {
                throw new RuntimeException('DB_MIGRATION_DRIVER_INVALID');
            }
        }

        $loaded[] = [
            'version' => $version,
            'name' => $name,
            'drivers' => $drivers,
            'up' => $up,
        ];
        $previousVersion = $version;
    }

    if ($previousVersion !== meteonexa_current_schema_version()) {
        throw new RuntimeException('DB_MIGRATION_CURRENT_VERSION_MISMATCH');
    }

    $migrations = $loaded;
    return $migrations;
}

/** @return array<int,string> */
function meteonexa_migration_manifest(): array
{
    $manifest = [];
    foreach (meteonexa_database_migrations() as $migration) {
        $manifest[$migration['version']] = $migration['name'];
    }
    return $manifest;
}

function meteonexa_write_schema_version(PDO $pdo, int $version): void
{
    $sql = meteonexa_pdo_driver($pdo) === 'mysql'
        ? 'INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) '
            . 'ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)'
        : 'INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) '
            . 'ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at';
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':key' => 'schema_version',
        ':value' => (string)$version,
        ':updated' => gmdate('c'),
    ]);
}

function meteonexa_run_explicit_migrations(PDO $pdo, int $schemaVersion, string $driver): int
{
    foreach (meteonexa_database_migrations() as $migration) {
        $targetVersion = $migration['version'];
        if ($schemaVersion >= $targetVersion || !in_array($driver, $migration['drivers'], true)) {
            continue;
        }

        $nextVersion = ($migration['up'])($pdo, $schemaVersion, $driver);
        if ($nextVersion < $schemaVersion || $nextVersion > $targetVersion) {
            throw new RuntimeException('DB_MIGRATION_RESULT_INVALID');
        }
        $schemaVersion = $nextVersion;
    }

    return $schemaVersion;
}

function meteonexa_run_mysql_migrations(PDO $pdo, int $schemaVersion): int
{
    return meteonexa_run_explicit_migrations($pdo, $schemaVersion, 'mysql');
}

require_once __DIR__ . '/migrations/legacy_sqlite_upgrade.php';

function meteonexa_run_sqlite_migrations(PDO $pdo, int $schemaVersion, int $currentSchema): int
{
    // Revisions before the explicit registry predate per-revision SQLite files.
    // Keep their battle-tested upgrade path isolated; future revisions run only
    // through meteonexa_run_explicit_migrations().
    $legacyCeiling = min($currentSchema, 26);
    if ($schemaVersion < $legacyCeiling) {
        $schemaVersion = meteonexa_run_sqlite_legacy_upgrade($pdo, $schemaVersion, $legacyCeiling);
    }

    if ($schemaVersion < $currentSchema) {
        $schemaVersion = meteonexa_run_explicit_migrations($pdo, $schemaVersion, 'sqlite');
    }

    return $schemaVersion;
}
