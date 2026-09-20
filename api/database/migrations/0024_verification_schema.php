<?php
declare(strict_types=1);

return [
    'version' => 24,
    'name' => 'verification-schema',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 24) {
            return $schemaVersion;
        }
        meteonexa_sync_verification_schema($pdo);
        return 24;
    },
];
