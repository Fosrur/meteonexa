<?php
declare(strict_types=1);

/**
 * MeteoNexa — privacy-preserving reverse geocoding gateway.
 *
 * The browser grants geolocation permission locally, then calls this same-origin
 * endpoint without account/device credentials. Coordinates are rounded to three
 * decimals before the upstream request, so BigDataCloud does not receive the
 * browser IP nor unnecessary GPS precision.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/http_helpers.php';

assert_same_origin();
require_method('GET');
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'reverse_geocode_public_ip', 120, 3600);
require_global_rate_limit($pdo, 'reverse_geocode_public_global', 2400, 3600);

try {
    [$lat, $lon] = meteonexa_validate_coordinates($_GET['latitude'] ?? null, $_GET['longitude'] ?? null);
} catch (Throwable $error) {
    respond(['ok'=>false,'code'=>'INVALID_COORDINATES','message'=>'api.backend.invalid_coordinates'],422);
}
$lat = round($lat, 3);
$lon = round($lon, 3);
$lang = strtolower(trim((string)($_GET['localityLanguage'] ?? 'it')));
if (!in_array($lang, ['it','en','es','fr','de'], true)) $lang = 'it';
$url = 'https://api.bigdatacloud.net/data/reverse-geocode-client?' . http_build_query([
    'latitude'=>number_format($lat,3,'.',''),
    'longitude'=>number_format($lon,3,'.',''),
    'localityLanguage'=>$lang,
]);
try {
    $raw = meteonexa_http_json($url, ['timeout'=>10,'max_bytes'=>262144,'pin_dns'=>true]);
    respond([
        'ok'=>true,
        'city'=>trim((string)($raw['city'] ?? '')),
        'locality'=>trim((string)($raw['locality'] ?? '')),
        'principalSubdivision'=>trim((string)($raw['principalSubdivision'] ?? '')),
        'countryName'=>trim((string)($raw['countryName'] ?? '')),
        'privacy'=>['credentialless'=>true,'coordinatePrecisionDecimals'=>3,'browserIpSharedWithProvider'=>false],
    ]);
} catch (Throwable $error) {
    meteonexa_log_event('reverse_geocode_failed', $error);
    respond(['ok'=>false,'code'=>'REVERSE_GEOCODE_UNAVAILABLE','message'=>'api.backend.service_unavailable'],503);
}
