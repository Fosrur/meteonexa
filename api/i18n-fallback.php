<?php
declare(strict_types=1);

// Database-independent translation fallback for first boot / migration windows.
// It serves only the requested locale from the packaged release seed and never
// reads user/account data or deployment secrets.
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

function fallback_language(mixed $value): string
{
    $raw = strtolower(trim((string)$value));
    $raw = str_replace('_', '-', $raw);
    $code = explode('-', $raw)[0] ?: 'it';
    return in_array($code, ['it', 'en', 'fr', 'es', 'de'], true) ? $code : 'it';
}

function fallback_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    fallback_respond(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405);
}

$language = fallback_language($_GET['language'] ?? $_GET['browserLanguage'] ?? 'it');
$path = __DIR__ . '/install/translations.json';
$raw = is_file($path) ? @file_get_contents($path) : false;
$seed = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($seed) || !is_array($seed['rows'] ?? null)) {
    fallback_respond(['ok' => false, 'code' => 'CATALOG_SEED_UNAVAILABLE'], 503);
}

$catalog = [];
foreach ($seed['rows'] as $row) {
    if (!is_array($row) || (string)($row['locale'] ?? '') !== $language) continue;
    $key = (string)($row['text_key'] ?? '');
    if ($key !== '') $catalog[$key] = (string)($row['translation'] ?? '');
}
if ($catalog === []) fallback_respond(['ok' => false, 'code' => 'CATALOG_EMPTY'], 503);

$supportedLanguages = [];
foreach (['it', 'en', 'fr', 'es', 'de'] as $code) {
    $supportedLanguages[] = ['code' => $code, 'label' => (string)($catalog['language.' . $code] ?? strtoupper($code))];
}
$seedVersion = trim((string)($seed['version'] ?? 'unknown'));
$etag = '"' . hash('sha256', $seedVersion . '|' . $language) . '"';
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

fallback_respond([
    'ok' => true,
    'fallback' => true,
    'language' => $language,
    'supportedLanguages' => $supportedLanguages,
    'translations' => $catalog,
    'translationsUpdatedAt' => 'seed:' . hash('sha256', $seedVersion . '|' . $language),
    'version' => '20.1',
]);
