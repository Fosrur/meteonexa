<?php
declare(strict_types=1);
return['version'=>22, 'name'=>'industrial-weather-pipeline', 'drivers'=>['mysql'], 'up'=>static function(PDO $pdo, int $schemaVersion, string $driver) : int {
    if ($driver!=='mysql'||$schemaVersion>=22) {
        return $schemaVersion;
    }
    // Industrial Weather Pipeline: provider health/SLA, worker runs,
    // official-alert lifecycle revisions and server-side lightning/radar quality evidence.
    $pdo->exec("CREATE TABLE IF NOT EXISTS weather_provider_health (
            provider_id VARCHAR(96) PRIMARY KEY,status VARCHAR(24) NOT NULL DEFAULT 'unknown',last_attempt_at VARCHAR(40) NOT NULL DEFAULT '',last_success_at VARCHAR(40) NOT NULL DEFAULT '',last_failure_at VARCHAR(40) NOT NULL DEFAULT '',latency_ms INT NOT NULL DEFAULT 0,consecutive_failures INT NOT NULL DEFAULT 0,freshness_seconds INT NULL,details_json MEDIUMTEXT NOT NULL,updated_at VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS weather_pipeline_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,worker_name VARCHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,started_at VARCHAR(40) NOT NULL,finished_at VARCHAR(40) NOT NULL DEFAULT '',duration_ms INT NOT NULL DEFAULT 0,locations_processed INT NOT NULL DEFAULT 0,success_count INT NOT NULL DEFAULT 0,failure_count INT NOT NULL DEFAULT 0,details_json MEDIUMTEXT NOT NULL,KEY idx_weather_pipeline_runs_worker(worker_name,id),KEY idx_weather_pipeline_runs_started(started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS official_alert_state (
            location_key VARCHAR(96) NOT NULL,alert_key VARCHAR(96) NOT NULL,location_name VARCHAR(191) NOT NULL DEFAULT '',provider_alert_id VARCHAR(255) NOT NULL DEFAULT '',severity VARCHAR(16) NOT NULL DEFAULT 'yellow',starts_at VARCHAR(40) NOT NULL DEFAULT '',ends_at VARCHAR(40) NOT NULL DEFAULT '',source_updated_at VARCHAR(40) NOT NULL DEFAULT '',content_hash VARCHAR(128) NOT NULL,first_seen_at VARCHAR(40) NOT NULL,last_seen_at VARCHAR(40) NOT NULL,last_change_type VARCHAR(24) NOT NULL DEFAULT 'new',last_change_at VARCHAR(40) NOT NULL,previous_severity VARCHAR(16) NOT NULL DEFAULT '',previous_ends_at VARCHAR(40) NOT NULL DEFAULT '',payload_json MEDIUMTEXT NOT NULL,PRIMARY KEY(location_key,alert_key),KEY idx_official_alert_state_change(location_key,last_change_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS official_alert_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,location_key VARCHAR(96) NOT NULL,alert_key VARCHAR(96) NOT NULL,revision_type VARCHAR(24) NOT NULL,previous_severity VARCHAR(16) NOT NULL DEFAULT '',new_severity VARCHAR(16) NOT NULL DEFAULT '',previous_ends_at VARCHAR(40) NOT NULL DEFAULT '',new_ends_at VARCHAR(40) NOT NULL DEFAULT '',provider_alert_id VARCHAR(255) NOT NULL DEFAULT '',payload_json MEDIUMTEXT NOT NULL,observed_at VARCHAR(40) NOT NULL,KEY idx_official_alert_revisions_location(location_key,id),KEY idx_official_alert_revisions_time(observed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lightning_observation_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(191) NOT NULL,location_key VARCHAR(96) NOT NULL,observed_at VARCHAR(40) NOT NULL,source VARCHAR(80) NOT NULL,count_30m INT NOT NULL DEFAULT 0,nearest_km DOUBLE NULL,approaching TINYINT(1) NOT NULL DEFAULT 0,payload_json MEDIUMTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,KEY idx_lightning_snapshots_location(device_id,location_key,id),KEY idx_lightning_snapshots_time(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS radar_frame_quality (
            frame_id BIGINT UNSIGNED PRIMARY KEY,quality_score INT NOT NULL DEFAULT 0,signal_coverage DOUBLE NOT NULL DEFAULT 0,fetch_latency_ms INT NOT NULL DEFAULT 0,provider_frame_age_seconds INT NOT NULL DEFAULT 0,checked_at VARCHAR(40) NOT NULL,KEY idx_radar_quality_checked(checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $meta = $pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)');
    $meta->execute([':key'=>'schema_version', ':value'=>'22', ':updated'=>gmdate('c')]);
    $schemaVersion = 22;
    return $schemaVersion;
},];
