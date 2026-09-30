<?php
declare(strict_types=1);

return [
    'version' => 30,
    'name' => 'radar4-probabilistic-verification',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 30) return $schemaVersion;
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS radar4_event_predictions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(191) NOT NULL,
                location_key VARCHAR(191) NOT NULL,
                prediction_key VARCHAR(64) NOT NULL,
                event_kind VARCHAR(24) NOT NULL,
                issued_at VARCHAR(40) NOT NULL,
                p10_at VARCHAR(40) NOT NULL,
                p50_at VARCHAR(40) NOT NULL,
                p90_at VARCHAR(40) NOT NULL,
                event_probability DOUBLE NOT NULL DEFAULT 0,
                confidence INT NOT NULL DEFAULT 0,
                predicted_value DOUBLE NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                verified_at VARCHAR(40) NOT NULL DEFAULT '',
                observed_at VARCHAR(40) NOT NULL DEFAULT '',
                observed_value DOUBLE NULL,
                error_minutes DOUBLE NULL,
                absolute_error_minutes DOUBLE NULL,
                absolute_error_value DOUBLE NULL,
                within_interval TINYINT NULL,
                ground_truth_source VARCHAR(64) NOT NULL DEFAULT '',
                ground_truth_quality INT NOT NULL DEFAULT 0,
                ground_truth_json MEDIUMTEXT NOT NULL,
                verification_method VARCHAR(64) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_radar4_event_prediction(device_id,location_key,prediction_key),
                KEY idx_radar4_event_pending(device_id,location_key,status,issued_at),
                KEY idx_radar4_event_skill(device_id,location_key,event_kind,status,verified_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS radar4_event_predictions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id TEXT NOT NULL,
                location_key TEXT NOT NULL,
                prediction_key TEXT NOT NULL,
                event_kind TEXT NOT NULL,
                issued_at TEXT NOT NULL,
                p10_at TEXT NOT NULL,
                p50_at TEXT NOT NULL,
                p90_at TEXT NOT NULL,
                event_probability REAL NOT NULL DEFAULT 0,
                confidence INTEGER NOT NULL DEFAULT 0,
                predicted_value REAL NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                verified_at TEXT NOT NULL DEFAULT '',
                observed_at TEXT NOT NULL DEFAULT '',
                observed_value REAL NULL,
                error_minutes REAL NULL,
                absolute_error_minutes REAL NULL,
                absolute_error_value REAL NULL,
                within_interval INTEGER NULL,
                ground_truth_source TEXT NOT NULL DEFAULT '',
                ground_truth_quality INTEGER NOT NULL DEFAULT 0,
                ground_truth_json TEXT NOT NULL DEFAULT '',
                verification_method TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                UNIQUE(device_id,location_key,prediction_key)
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radar4_event_pending ON radar4_event_predictions(device_id,location_key,status,issued_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radar4_event_skill ON radar4_event_predictions(device_id,location_key,event_kind,status,verified_at)');
        }
        meteonexa_write_schema_version($pdo, 30);
        return 30;
    },
];
