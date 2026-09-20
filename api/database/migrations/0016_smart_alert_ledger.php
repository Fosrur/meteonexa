<?php
declare(strict_types=1);

return [
    'version' => 16,
    'name' => 'smart-alert-ledger',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 16) {
            return $schemaVersion;
        }

        // Preserve all existing SMTP/AI/runtime rows and
        // create only the server-side Smart Alert event ledger.
        $pdo->exec("CREATE TABLE IF NOT EXISTS weather_alert_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(191) NOT NULL,
            event_key VARCHAR(191) NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            confidence INT NOT NULL DEFAULT 0,
            location_name VARCHAR(255) NOT NULL DEFAULT '',
            starts_at VARCHAR(40) NOT NULL DEFAULT '',
            ends_at VARCHAR(40) NOT NULL DEFAULT '',
            payload_json MEDIUMTEXT NOT NULL,
            delivered_at VARCHAR(40) NOT NULL DEFAULT '',
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_weather_alert_event(device_id,event_key),
            KEY idx_weather_alert_events_device(device_id,created_at),
            KEY idx_weather_alert_events_cleanup(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $seed = $pdo->prepare('INSERT INTO ui_visibility(feature_key,guest_visible,authenticated_visible,updated_at) VALUES(:feature,:guest,:authenticated,:updated) ON CONFLICT(feature_key) DO UPDATE SET guest_visible=excluded.guest_visible,authenticated_visible=excluded.authenticated_visible,updated_at=excluded.updated_at');
        foreach ([
            'feature.smart.demo'=>[1,1],
            'feature.smart.alerts'=>[0,1],
            'feature.official.alerts'=>[1,1],
            'feature.hyperlocal'=>[0,1],
        ] as $featureKey => [$guestVisible,$authenticatedVisible]) {
            $seed->execute([':feature'=>$featureKey,':guest'=>$guestVisible,':authenticated'=>$authenticatedVisible,':updated'=>gmdate('c')]);
        }
        $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at');
        $meta->execute([':key'=>'schema_version',':value'=>'16',':updated'=>gmdate('c')]);
        $schemaVersion=16;

        return $schemaVersion;
    },
];
