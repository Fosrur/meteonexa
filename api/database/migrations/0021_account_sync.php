<?php
declare(strict_types=1);

return [
    'version' => 21,
    'name' => 'account-sync',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 21) {
            return $schemaVersion;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS account_sync_state (
            account_hash VARCHAR(128) NOT NULL, namespace VARCHAR(40) NOT NULL, item_key VARCHAR(191) NOT NULL, payload_json MEDIUMTEXT NOT NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0, revision BIGINT NOT NULL DEFAULT 1, updated_by_device VARCHAR(191) NOT NULL DEFAULT '', updated_at VARCHAR(40) NOT NULL,
            PRIMARY KEY(account_hash,namespace,item_key), KEY idx_account_sync_updated(account_hash,updated_at), KEY idx_account_sync_namespace(account_hash,namespace,deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS account_activity_profiles (
            account_hash VARCHAR(128) NOT NULL, activity VARCHAR(32) NOT NULL, thresholds_json MEDIUMTEXT NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
            updated_by_device VARCHAR(191) NOT NULL DEFAULT '', updated_at VARCHAR(40) NOT NULL, PRIMARY KEY(account_hash,activity), KEY idx_account_activity_updated(account_hash,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)');
        $meta->execute([':key'=>'schema_version',':value'=>'21',':updated'=>gmdate('c')]);$schemaVersion=21;

        return $schemaVersion;
    },
];
