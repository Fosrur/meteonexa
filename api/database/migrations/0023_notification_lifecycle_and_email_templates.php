<?php
declare(strict_types=1);

return [
    'version' => 23,
    'name' => 'notification-lifecycle-and-email-templates',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 23) {
            return $schemaVersion;
        }

        // Persistent notification read/dismiss lifecycle and database-editable branded email templates.
        if (!meteonexa_db_column_exists($pdo, 'push_notifications', 'read_at')) $pdo->exec("ALTER TABLE push_notifications ADD COLUMN read_at VARCHAR(40) NOT NULL DEFAULT ''");
        if (!meteonexa_db_column_exists($pdo, 'push_notifications', 'dismissed_at')) $pdo->exec("ALTER TABLE push_notifications ADD COLUMN dismissed_at VARCHAR(40) NOT NULL DEFAULT ''");
        if (!meteonexa_db_column_exists($pdo, 'push_notifications', 'expires_at')) $pdo->exec("ALTER TABLE push_notifications ADD COLUMN expires_at VARCHAR(40) NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
            template_key VARCHAR(80) NOT NULL, locale VARCHAR(8) NOT NULL DEFAULT 'it', subject_template VARCHAR(255) NOT NULL,
            kicker VARCHAR(191) NOT NULL DEFAULT '', heading VARCHAR(255) NOT NULL DEFAULT '', intro TEXT NOT NULL,
            footer TEXT NOT NULL, updated_at VARCHAR(40) NOT NULL, PRIMARY KEY(template_key,locale)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)');
        $meta->execute([':key'=>'schema_version',':value'=>'23',':updated'=>gmdate('c')]);$schemaVersion=23;

        return $schemaVersion;
    },
];
