<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/providers.php';
require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
$device = clean_device_id($_GET['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'observations_current', $device, 60, 3600);
$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90||abs($lon) > 180)respond(['ok'=>false, 'code'=>'INVALID_COORDINATES', 'message'=>'api.backend.invalid_coordinates'], 422);
$observations = meteonexa_observations_collect($pdo, $config, $device, $lat, $lon, true);
respond(['ok'=>true, 'observations'=>$observations, 'generatedAt'=>gmdate('c')]);
