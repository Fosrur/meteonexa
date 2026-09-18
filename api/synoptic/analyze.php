<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'synoptic_ip', 120, 3600);
require_global_rate_limit($pdo, 'synoptic_global', (int)($config['abuse_limits']['synoptic_global_hour']??600), 3600);
$device = clean_device_id($_GET['deviceId']??'');
require_device_access($pdo, $device);
require_device_rate_limit($pdo, 'synoptic_device', $device, 60, 3600);
$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90||abs($lon) > 180)respond(['ok'=>false, 'code'=>'INVALID_COORDINATES', 'message'=>meteonexa_backend_text('api.backend.invalid_coordinates')], 422);
$deltaLat = 1.0;
$deltaLon = 1.0 / max(.25, abs(cos(deg2rad($lat))));
function syn_wrap_lon(float $value) : float {
    while ($value > 180)$value-=360;
    while ($value < - 180)$value+=360;
    return $value;
}
$lats =[$lat, min(90.0, $lat + $deltaLat), max( - 90.0, $lat - $deltaLat), $lat, $lat];
$lons =[$lon, $lon, $lon, syn_wrap_lon($lon + $deltaLon), syn_wrap_lon($lon - $deltaLon)];
$params = http_build_query(['latitude'=>implode(',', $lats), 'longitude'=>implode(',', $lons), 'timezone'=>'UTC', 'forecast_days'=>2, 'hourly'=>'pressure_msl,geopotential_height_500hPa,temperature_850hPa,wind_speed_850hPa,wind_direction_850hPa,relative_humidity_700hPa,vertical_velocity_700hPa,cape,lifted_index,convective_inhibition,precipitation,cloud_cover', 'wind_speed_unit'=>'kmh']);
function syn_nearest(array $times) : int {
    $now = time();
    $best = 0;
    $distance = PHP_INT_MAX;
    foreach ($times as $i=>$time) {
        $d = abs(strtotime((string)$time) - $now);
        if ($d < $distance) {
            $distance = $d;
            $best = (int)$i;
        }
    }
    return $best;
}
function syn_value(array $row, string $key, int $i, float $fallback = 0) : float {
    return isset($row['hourly'][$key][$i]) ? (float)$row['hourly'][$key][$i] : $fallback;
}
function syn_direction(float $degrees) : string {
    $names = array_values(array_filter(array_map('trim', explode('|', meteonexa_backend_text('compass.short'))), static fn($value) : bool=>$value!==''));
    $index =((int)round($degrees / 45)) % 8;
    return count($names)===8 ? $names[$index] : sprintf('%d°', (int)round($degrees) % 360);
}
try {
    $raw = meteonexa_http_json('https://api.open-meteo.com/v1/forecast?' . $params,['timeout'=>22, 'max_bytes'=>2097152]);
    $points = array_is_list($raw) ? $raw :[$raw];
    if (count($points) < 5)throw new RuntimeException('SYNOPTIC_GRID_INCOMPLETE');
    $times = $points[0]['hourly']['time']??[];
    $i = syn_nearest($times);
    $previousIndex = max(0, $i - 3);
    $center = $points[0];
    $p = array_map(fn($row)=>syn_value($row, 'pressure_msl', $i), $points);
    $pressureTendency = $p[0] - syn_value($center, 'pressure_msl', $previousIndex, $p[0]);
    $north = $p[1];
    $south = $p[2];
    $east = $p[3];
    $west = $p[4];
    $gradient = sqrt((($east - $west) / 222.0)**2 +(($north - $south) / 222.0)**2) * 100.0;
    $z500 = syn_value($center, 'geopotential_height_500hPa', $i);
    $z500Trend = $z500 - syn_value($center, 'geopotential_height_500hPa', $previousIndex, $z500);
    $t850 = syn_value($center, 'temperature_850hPa', $i);
    $tNorth = syn_value($points[1], 'temperature_850hPa', $i);
    $tSouth = syn_value($points[2], 'temperature_850hPa', $i);
    $tEast = syn_value($points[3], 'temperature_850hPa', $i);
    $tWest = syn_value($points[4], 'temperature_850hPa', $i);
    $wind = syn_value($center, 'wind_speed_850hPa', $i);
    $windDirection = syn_value($center, 'wind_direction_850hPa', $i);
    $toRad = deg2rad($windDirection);
    $u = - $wind * sin($toRad);
    $v = - $wind * cos($toRad);
    $dTdx =($tEast - $tWest) / 222.0;
    $dTdy =($tNorth - $tSouth) / 222.0;
    $advection = -($u * $dTdx + $v * $dTdy);
    $rh700 = syn_value($center, 'relative_humidity_700hPa', $i);
    $omega700 = syn_value($center, 'vertical_velocity_700hPa', $i);
    $cape = syn_value($center, 'cape', $i);
    $li = syn_value($center, 'lifted_index', $i);
    $cin = syn_value($center, 'convective_inhibition', $i);
    $rain = syn_value($center, 'precipitation', $i);
    $cloud = syn_value($center, 'cloud_cover', $i);
    $locationKey = number_format($lat, 3, '.', '') . ':' . number_format($lon, 3, '.', '');
    $st = $pdo->prepare('SELECT analysis_json FROM synoptic_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC LIMIT 1');
    $st->execute([':device'=>$device, ':location'=>$locationKey]);
    $old = $st->fetch();
    $prior = $old ? json_decode((string)$old['analysis_json'], true) : null;
    $pressureLabel = $pressureTendency<= - 1 ? 'synoptic.pressure.falling' :($pressureTendency>=1 ? 'synoptic.pressure.rising' : 'synoptic.pressure.stable');
    $pressureImpact = $pressureTendency<= - 1 ? 'synoptic.pressure.impact.falling' :($pressureTendency>=1 ? 'synoptic.pressure.impact.rising' : 'synoptic.pressure.impact.stable');
    $upperLabel = $z500Trend < - 12 ? 'synoptic.upper.trough' :($z500Trend > 12 ? 'synoptic.upper.ridge' : 'synoptic.upper.stable');
    $upperImpact = $z500Trend < - 12 ? 'synoptic.upper.impact.trough' :($z500Trend > 12 ? 'synoptic.upper.impact.ridge' : 'synoptic.upper.impact.stable');
    $advectionLabel = $advection > .05 ? 'synoptic.advection.warm' :($advection < - .05 ? 'synoptic.advection.cold' : 'synoptic.advection.weak');
    $advectionImpact = $advection > .05 ? 'synoptic.advection.impact.warm' :($advection < - .05 ? 'synoptic.advection.impact.cold' : 'synoptic.advection.impact.weak');
    $convective = $cape>=800&&$li<= - 2&&$cin < 150;
    $convectionLabel = $convective ? 'synoptic.convection.significant' :($cape>=300 ? 'synoptic.convection.moderate' : 'synoptic.convection.low');
    $convectionImpact = $convective ? 'synoptic.convection.impact.significant' : 'synoptic.convection.impact.low';
    $signals =[['type'=>'pressure', 'labelKey'=>$pressureLabel, 'detailKey'=>'synoptic.pressure.detail', 'detailParams'=>['trend'=>sprintf('%+.1f', $pressureTendency), 'gradient'=>sprintf('%.1f', $gradient)], 'impactKey'=>$pressureImpact],['type'=>'upper', 'labelKey'=>$upperLabel, 'detailKey'=>'synoptic.upper.detail', 'detailParams'=>['height'=>sprintf('%.0f', $z500), 'trend'=>sprintf('%+.0f', $z500Trend)], 'impactKey'=>$upperImpact],['type'=>'advection', 'labelKey'=>$advectionLabel, 'detailKey'=>'synoptic.advection.detail', 'detailParams'=>['temperature'=>sprintf('%.1f', $t850), 'direction'=>syn_direction($windDirection), 'wind'=>sprintf('%.0f', $wind)], 'impactKey'=>$advectionImpact],['type'=>'convection', 'labelKey'=>$convectionLabel, 'detailKey'=>'synoptic.convection.detail', 'detailParams'=>['cape'=>sprintf('%.0f', $cape), 'li'=>sprintf('%.1f', $li), 'cin'=>sprintf('%.0f', $cin), 'humidity'=>sprintf('%.0f', $rh700)], 'impactKey'=>$convectionImpact],];
    $changed =[];
    if (is_array($prior)) {
        foreach (['pressureTendency'=>'synoptic.factor.pressure', 'z500Trend'=>'synoptic.factor.upper', 'cape'=>'synoptic.factor.instability', 'rain'=>'synoptic.factor.rain'] as $key=>$labelKey) {
            $before = (float)($prior[$key]??0);
            $nowVal = match ($key) {
                'pressureTendency'=>$pressureTendency, 'z500Trend'=>$z500Trend, 'cape'=>$cape, default =>$rain
            };
            $delta = $nowVal - $before;
            if (abs($delta) > match ($key) {
                'pressureTendency'=>.7, 'z500Trend'=>8, 'cape'=>150, default =>.2
            })$changed[] =['factorKey'=>$labelKey, 'delta'=>$delta];
        }
    }
    $primaryKey = $pressureImpact;
    if ($convective)$primaryKey = $convectionImpact;
    elseif ($z500Trend < - 12||$z500Trend > 12)$primaryKey = $upperImpact;
    elseif (abs($pressureTendency)>=1)$primaryKey = $pressureImpact;
    $analysis =['at'=>time(), 'pressure'=>$p[0], 'pressureTendency'=>$pressureTendency, 'pressureGradient'=>$gradient, 'z500'=>$z500, 'z500Trend'=>$z500Trend, 'temperature850'=>$t850, 'wind850'=>$wind, 'windDirection850'=>$windDirection, 'thermalAdvection'=>$advection, 'humidity700'=>$rh700, 'verticalVelocity700'=>$omega700, 'cape'=>$cape, 'liftedIndex'=>$li, 'cin'=>$cin, 'rain'=>$rain, 'cloud'=>$cloud, 'signals'=>$signals, 'changes'=>$changed, 'explanationKey'=>$primaryKey, 'confidenceKey'=>$convective||abs($z500Trend)>=12||abs($pressureTendency)>=1 ? 'synoptic.confidence.high' : 'synoptic.confidence.moderate'];
    $json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO synoptic_snapshots(device_id,location_key,analysis_json,created_at) VALUES(:device,:location,:json,:at)')->execute([':device'=>$device, ':location'=>$locationKey, ':json'=>$json, ':at'=>gmdate('c')]);
    $pdo->prepare('DELETE FROM synoptic_snapshots WHERE id NOT IN (SELECT id FROM synoptic_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC LIMIT 24) AND device_id=:device AND location_key=:location')->execute([':device'=>$device, ':location'=>$locationKey]);
    $pdo->prepare('DELETE FROM synoptic_snapshots WHERE id IN (SELECT id FROM synoptic_snapshots WHERE device_id=:device ORDER BY id DESC LIMIT -1 OFFSET 500)')->execute([':device'=>$device]);
    $pdo->prepare('DELETE FROM synoptic_snapshots WHERE created_at < :cutoff')->execute([':cutoff'=>gmdate('c', time() - 30 * 86400)]);
    meteonexa_prune_rows_to_limit($pdo, 'synoptic_snapshots', (int)($config['storage_limits']['synoptic_snapshots']??50000));
    respond(['ok'=>true, 'source'=>meteonexa_backend_text('synoptic.source.pressure_levels'), 'analysis'=>$analysis]);
} catch (Throwable $e) {
    respond(['ok'=>false, 'code'=>'SYNOPTIC_UNAVAILABLE', 'message'=>meteonexa_backend_text('api.backend.synoptic_unavailable')], 502);
}
