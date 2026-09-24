<?php
declare(strict_types=1);

return [
    'version' => 29,
    'name' => 'probabilistic-ensemble-verification',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 29) return $schemaVersion;
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ensemble_verification_samples (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(191) NOT NULL,
                location_key VARCHAR(191) NOT NULL,
                model_id VARCHAR(80) NOT NULL,
                metric VARCHAR(24) NOT NULL,
                horizon_hours INT NOT NULL,
                target_time VARCHAR(40) NOT NULL,
                member_count INT NOT NULL DEFAULT 0,
                members_json MEDIUMTEXT NOT NULL,
                p10 DOUBLE NULL,
                p50 DOUBLE NULL,
                p90 DOUBLE NULL,
                observed_value DOUBLE NULL,
                crps DOUBLE NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                issued_at VARCHAR(40) NOT NULL,
                verified_at VARCHAR(40) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_ensemble_verify(device_id,location_key,model_id,metric,horizon_hours,target_time),
                KEY idx_ensemble_verify_pending(device_id,location_key,status,target_time),
                KEY idx_ensemble_verify_skill(device_id,location_key,model_id,metric,horizon_hours,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ensemble_verification_samples (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id TEXT NOT NULL,
                location_key TEXT NOT NULL,
                model_id TEXT NOT NULL,
                metric TEXT NOT NULL,
                horizon_hours INTEGER NOT NULL,
                target_time TEXT NOT NULL,
                member_count INTEGER NOT NULL DEFAULT 0,
                members_json TEXT NOT NULL,
                p10 REAL NULL,
                p50 REAL NULL,
                p90 REAL NULL,
                observed_value REAL NULL,
                crps REAL NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                issued_at TEXT NOT NULL,
                verified_at TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                UNIQUE(device_id,location_key,model_id,metric,horizon_hours,target_time)
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ensemble_verify_pending ON ensemble_verification_samples(device_id,location_key,status,target_time)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ensemble_verify_skill ON ensemble_verification_samples(device_id,location_key,model_id,metric,horizon_hours,status)");
        }
        meteonexa_write_schema_version($pdo, 29);
        return 29;
    },
];
