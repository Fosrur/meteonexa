<?php
declare(strict_types=1);

return [
    'version' => 20,
    'name' => 'observation-and-nowcast-evidence',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 20) {
            return $schemaVersion;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS observation_evidence (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(191) NOT NULL, location_key VARCHAR(191) NOT NULL,
            source_type VARCHAR(40) NOT NULL, source_id VARCHAR(191) NOT NULL DEFAULT '', observed_at VARCHAR(40) NOT NULL,
            temperature DOUBLE NULL, precipitation DOUBLE NULL, wind_gust DOUBLE NULL, rain_event DOUBLE NULL, storm_event DOUBLE NULL, snow_event DOUBLE NULL,
            distance_km DOUBLE NULL, quality_score INT NOT NULL DEFAULT 0, payload_json MEDIUMTEXT NOT NULL, created_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_observation_evidence(device_id,location_key,source_type,source_id,observed_at), KEY idx_observation_evidence_lookup(device_id,location_key,observed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS nowcast_fusion_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(191) NOT NULL, location_key VARCHAR(191) NOT NULL,
            snapshot_json MEDIUMTEXT NOT NULL, created_at VARCHAR(40) NOT NULL, KEY idx_nowcast_fusion_lookup(device_id,location_key,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)');
        $meta->execute([':key'=>'schema_version',':value'=>'20',':updated'=>gmdate('c')]);$schemaVersion=20;

        return $schemaVersion;
    },
];
