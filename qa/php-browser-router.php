<?php
declare(strict_types=1);

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$flag = trim((string)(getenv('METEONEXA_MAINTENANCE_FLAG') ?: ''));

$exempt = [
    '/maintenance.html',
    '/api/maintenance.php',
    '/css/maintenance.css',
    '/js/maintenance.js',
    '/assets/logo-full.png',
    '/assets/logo.png',
    '/assets/icons/favicon-32.png',
    '/assets/icons/favicon.ico',
    '/assets/icons/apple-touch-icon.png',
    '/assets/icons/icon-192.png',
];

$isI18n = preg_match('#^/assets/i18n/(?:it|en|fr|es|de)\.json$#', $path) === 1;
$isMaintenance = $flag !== '' && is_file($flag);

if (
    $isMaintenance
    && str_contains($accept, 'text/html')
    && !in_array($path, $exempt, true)
    && !$isI18n
) {
    require dirname(__DIR__) . '/api/maintenance.php';
    return true;
}

return false;
