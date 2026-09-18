<?php
declare(strict_types=1);

return [
    'version' => 17,
    'name' => 'verified-trust-and-saved-locations',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 17) {
            return $schemaVersion;
        }

        // Verified Trust: server-side model calibration, run history and multi-location.
        $pdo->exec("CREATE TABLE IF NOT EXISTS model_skill_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(191) NOT NULL, location_key VARCHAR(191) NOT NULL,
            model_name VARCHAR(80) NOT NULL, metric VARCHAR(24) NOT NULL,
            horizon_hours INT NOT NULL, target_time VARCHAR(40) NOT NULL,
            predicted_value DOUBLE NULL, observed_value DOUBLE NULL,
            error_value DOUBLE NULL, brier_score DOUBLE NULL,
            issued_at VARCHAR(40) NOT NULL, verified_at VARCHAR(40) NOT NULL DEFAULT '', created_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_model_skill(device_id,location_key,model_name,metric,horizon_hours,target_time),
            KEY idx_model_skill_verify(device_id,location_key,target_time,verified_at),
            KEY idx_model_skill_metric(device_id,location_key,metric,horizon_hours,verified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forecast_run_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(191) NOT NULL, location_key VARCHAR(191) NOT NULL,
            snapshot_json MEDIUMTEXT NOT NULL, created_at VARCHAR(40) NOT NULL,
            KEY idx_forecast_run_lookup(device_id,location_key,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS saved_locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(191) NOT NULL, role VARCHAR(24) NOT NULL DEFAULT 'custom', label VARCHAR(80) NOT NULL,
            location_name VARCHAR(191) NOT NULL, admin1 VARCHAR(191) NOT NULL DEFAULT '',
            latitude DOUBLE NOT NULL, longitude DOUBLE NOT NULL, timezone VARCHAR(80) NOT NULL DEFAULT 'auto', active TINYINT(1) NOT NULL DEFAULT 1,
            created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_saved_location(device_id,label), KEY idx_saved_locations_active(device_id,active,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at');
        $meta->execute([':key'=>'schema_version',':value'=>'17',':updated'=>gmdate('c')]);
        $schemaVersion=17;

        return $schemaVersion;
    },
];
