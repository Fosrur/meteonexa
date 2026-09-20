<?php
declare(strict_types=1);

return [
    'version' => 26,
    'name' => 'product-metrics-and-current-metadata',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 26) {
            return $schemaVersion;
        }
        meteonexa_sync_product_metrics_schema($pdo);
        meteonexa_write_schema_version($pdo, 26);
        return 26;
    },
];
