<?php
declare(strict_types=1);

return [
    'version' => 32,
    'name' => 'official-warning-hub',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 32) return $schemaVersion;

        $add = static function (string $table, string $column, string $mysqlType, string $sqliteType) use ($pdo, $driver): void {
            if (meteonexa_db_column_exists($pdo, $table, $column)) return;
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . ($driver === 'mysql' ? $mysqlType : $sqliteType));
        };

        $add('official_alert_state', 'event_id', "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_state', 'version_id', "VARCHAR(64) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_state', 'area_key', "VARCHAR(64) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_state', 'lifecycle_status', "VARCHAR(24) NOT NULL DEFAULT 'issued'", "TEXT NOT NULL DEFAULT 'issued'");
        $add('official_alert_state', 'authority_name', "VARCHAR(191) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_state', 'sender', "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_state', 'message_type', "VARCHAR(24) NOT NULL DEFAULT 'alert'", "TEXT NOT NULL DEFAULT 'alert'");
        $add('official_alert_state', 'source_name', "VARCHAR(96) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_state', 'geometry_json', "MEDIUMTEXT NULL", "TEXT NULL");

        $add('official_alert_revisions', 'event_id', "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_revisions', 'version_id', "VARCHAR(64) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_revisions', 'area_key', "VARCHAR(64) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('official_alert_revisions', 'lifecycle_status', "VARCHAR(24) NOT NULL DEFAULT 'updated'", "TEXT NOT NULL DEFAULT 'updated'");

        $pdo->exec("UPDATE official_alert_state SET lifecycle_status=CASE WHEN last_change_type='expired' THEN 'expired' WHEN last_change_type='cancelled' THEN 'cancelled' WHEN last_change_type='new' THEN 'issued' ELSE 'updated' END WHERE lifecycle_status='' OR lifecycle_status='issued'");

        if ($driver === 'mysql') {
            try { $pdo->exec('CREATE INDEX idx_official_alert_state_event ON official_alert_state(event_id,area_key,lifecycle_status)'); } catch (Throwable $ignored) {}
            try { $pdo->exec('CREATE INDEX idx_official_alert_state_source ON official_alert_state(source_name,lifecycle_status,last_seen_at)'); } catch (Throwable $ignored) {}
            try { $pdo->exec('CREATE INDEX idx_official_alert_revisions_event ON official_alert_revisions(event_id,area_key,id)'); } catch (Throwable $ignored) {}
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_alert_state_event ON official_alert_state(event_id,area_key,lifecycle_status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_alert_state_source ON official_alert_state(source_name,lifecycle_status,last_seen_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_alert_revisions_event ON official_alert_revisions(event_id,area_key,id)');
        }

        meteonexa_write_schema_version($pdo, 32);
        return 32;
    },
];
