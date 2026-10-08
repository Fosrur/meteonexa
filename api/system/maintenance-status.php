<?php
declare(strict_types=1);








$flag = trim((string)(getenv('METEONEXA_MAINTENANCE_FLAG') ?: '/var/lib/meteonexa/maintenance.flag'));
if ($flag === '') {
    $flag = '/var/lib/meteonexa/maintenance.flag';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo json_encode([
    'ok' => true,
    'maintenance' => is_file($flag),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
