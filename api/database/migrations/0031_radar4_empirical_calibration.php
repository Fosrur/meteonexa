<?php
declare(strict_types=1);

return [
    'version' => 31,
    'name' => 'radar4-empirical-calibration-context',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 31) return $schemaVersion;

        $add = static function (string $table, string $column, string $mysqlType, string $sqliteType) use ($pdo, $driver): void {
            if (meteonexa_db_column_exists($pdo, $table, $column)) return;
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . ($driver === 'mysql' ? $mysqlType : $sqliteType));
        };

        $add('radar4_event_predictions', 'area_key', "VARCHAR(32) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('radar4_event_predictions', 'distance_band', "VARCHAR(16) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar4_event_predictions', 'coverage_band', "VARCHAR(16) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar4_event_predictions', 'season', "VARCHAR(16) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar4_event_predictions', 'weather_regime', "VARCHAR(24) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar4_event_predictions', 'terrain_class', "VARCHAR(24) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar4_event_predictions', 'terrain_relief_m', 'DOUBLE NULL', 'REAL NULL');
        $add('radar4_event_predictions', 'terrain_gradient_pct', 'DOUBLE NULL', 'REAL NULL');
        $add('radar4_event_predictions', 'event_observed', 'TINYINT NULL', 'INTEGER NULL');
        $add('radar4_event_predictions', 'calibrated_probability', 'DOUBLE NULL', 'REAL NULL');
        $add('radar4_event_predictions', 'probability_brier', 'DOUBLE NULL', 'REAL NULL');
        $add('radar4_event_predictions', 'calibration_context_json', 'MEDIUMTEXT NULL', 'TEXT NULL');

        $add('radar_eta_predictions', 'area_key', "VARCHAR(32) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        $add('radar_eta_predictions', 'distance_band', "VARCHAR(16) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar_eta_predictions', 'coverage_band', "VARCHAR(16) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar_eta_predictions', 'season', "VARCHAR(16) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar_eta_predictions', 'weather_regime', "VARCHAR(24) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");
        $add('radar_eta_predictions', 'terrain_class', "VARCHAR(24) NOT NULL DEFAULT 'unknown'", "TEXT NOT NULL DEFAULT 'unknown'");

        if ($driver === 'mysql') {
            try { $pdo->exec('CREATE INDEX idx_radar4_event_calibration ON radar4_event_predictions(device_id,event_kind,status,season,distance_band,coverage_band)'); } catch (Throwable $ignored) {}
            try { $pdo->exec('CREATE INDEX idx_radar_eta_calibration ON radar_eta_predictions(device_id,algorithm,status,season,distance_band,coverage_band)'); } catch (Throwable $ignored) {}
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radar4_event_calibration ON radar4_event_predictions(device_id,event_kind,status,season,distance_band,coverage_band)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radar_eta_calibration ON radar_eta_predictions(device_id,algorithm,status,season,distance_band,coverage_band)');
        }

        meteonexa_write_schema_version($pdo, 31);
        return 31;
    },
];
