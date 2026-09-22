<?php
declare(strict_types=1);

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$flag = trim((string)(getenv('METEONEXA_MAINTENANCE_FLAG') ?: ''));

// Mirror the production Apache contract for the root-scoped Service Worker.
// PHP's built-in server does not read .htaccess, so browser QA must serve the
// worker explicitly with the same Service-Worker-Allowed response header.
if ($path === '/js/sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache');
    readfile(dirname(__DIR__) . '/js/sw.js');
    return true;
}

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
