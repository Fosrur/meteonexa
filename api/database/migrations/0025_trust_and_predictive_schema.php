<?php
declare(strict_types=1);

return [
    'version' => 25,
    'name' => 'trust-and-predictive-schema',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 25) {
            return $schemaVersion;
        }
        meteonexa_sync_trust_schema($pdo);
        return 25;
    },
];
