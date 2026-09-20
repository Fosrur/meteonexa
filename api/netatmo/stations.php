<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/helpers.php';
require_method('GET');
assert_same_origin();
$config = load_config();
netatmo_assert_configured($config);
$pdo = meteonexa_db($config);
$device = clean_device_id($_GET['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo, 'netatmo_stations_ip', 240, 3600);
require_global_rate_limit($pdo, 'netatmo_stations_global', (int)($config['abuse_limits']['netatmo_stations_global_hour']??2000), 3600);
require_device_rate_limit($pdo, 'netatmo_stations_device', $device, 120, 3600);
try {
    $token = netatmo_access_token($pdo, $config, $device);
    $raw = meteonexa_http_json('https://api.netatmo.com/api/getstationsdata?get_favorites=true',['timeout'=>20, 'headers'=>['Authorization: Bearer ' . $token, 'Accept: application/json']]);
    $devices = $raw['body']['devices']??[];
    $rows =[];
    foreach (is_array($devices) ? $devices :[] as $station) {
        $modules = array_merge([$station], is_array($station['modules']??null) ? $station['modules'] :[]);
        foreach ($modules as $module) {
            $dash = $module['dashboard_data']??[];
            $place = $station['place']['location']??[];
            $rows[] =['id'=>(string)($module['_id']??''), 'stationId'=>(string)($station['_id']??''), 'name'=>(string)($module['module_name']??$station['station_name']??meteonexa_backend_text('provider.netatmo')), 'type'=>(string)($module['type']??''), 'latitude'=>isset($place[1]) ? (float)$place[1] : null, 'longitude'=>isset($place[0]) ? (float)$place[0] : null, 'temperature'=>$dash['Temperature']??null, 'humidity'=>$dash['Humidity']??null, 'pressure'=>$dash['Pressure']??null, 'co2'=>$dash['CO2']??null, 'noise'=>$dash['Noise']??null, 'rain'=>$dash['Rain']??null, 'wind'=>$dash['WindStrength']??null, 'gust'=>$dash['GustStrength']??null, 'observedAt'=>isset($dash['time_utc']) ? (int)$dash['time_utc'] : null];
        }
    }
    respond(['ok'=>true, 'stations'=>$rows, 'source'=>meteonexa_backend_text('provider.netatmo')]);
} catch (Throwable $e) {
    respond(['ok'=>false, 'code'=>'NETATMO_ERROR', 'message'=>meteonexa_backend_text('api.backend.netatmo_error')], 502);
}
