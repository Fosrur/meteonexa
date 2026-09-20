<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/engine_helpers.php';

require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'rain_direction_ip', 180, 3600);
require_global_rate_limit($pdo, 'rain_direction_global', (int)($config['abuse_limits']['rain_direction_global_hour'] ?? 1200), 3600);

$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90 || abs($lon) > 180) {
    respond(['ok'=>false,'code'=>'INVALID_COORDINATES','message'=>meteonexa_backend_text('api.backend.invalid_coordinates')], 422);
}

// This helper is public/guest-safe and never persists exact coordinates. Two
// decimals are sufficient for a mesoscale direction estimate and preserve the
// same privacy boundary as the public Intelligence demo.
$lat = round($lat, 2);
$lon = round($lon, 2);
$lonStep = .18 / max(.45, cos(deg2rad($lat)));
$coords = [
    ['latitude'=>$lat,       'longitude'=>$lon],
    ['latitude'=>$lat+.14,   'longitude'=>$lon],
    ['latitude'=>$lat-.14,   'longitude'=>$lon],
    ['latitude'=>$lat,       'longitude'=>$lon+$lonStep],
    ['latitude'=>$lat,       'longitude'=>$lon-$lonStep],
];
foreach ($coords as &$coord) {
    $coord['latitude'] = max(-90.0, min(90.0, $coord['latitude']));
    while ($coord['longitude'] > 180) $coord['longitude'] -= 360;
    while ($coord['longitude'] < -180) $coord['longitude'] += 360;
}
unset($coord);

function motion_quality_haversine(array $a, array $b): float {
    $r=6371.0; $p1=deg2rad((float)$a['latitude']); $p2=deg2rad((float)$b['latitude']);
    $dp=deg2rad((float)$b['latitude']-(float)$a['latitude']); $dl=deg2rad((float)$b['longitude']-(float)$a['longitude']);
    $h=sin($dp/2)**2 + cos($p1)*cos($p2)*sin($dl/2)**2;
    return $r * 2 * atan2(sqrt($h), sqrt(max(0.0,1-$h)));
}
function motion_quality_bearing(array $a, array $b): float {
    $p1=deg2rad((float)$a['latitude']); $p2=deg2rad((float)$b['latitude']); $dl=deg2rad((float)$b['longitude']-(float)$a['longitude']);
    $y=sin($dl)*cos($p2); $x=cos($p1)*sin($p2)-sin($p1)*cos($p2)*cos($dl);
    $deg=fmod(rad2deg(atan2($y,$x))+360.0,360.0); return $deg < 0 ? $deg+360.0 : $deg;
}

try {
    $raw = meteonexa_intel_provider_cache('rain-direction-5pt', $lat, $lon, 180, static function() use ($coords): array {
        $params = http_build_query([
            'latitude'=>implode(',', array_map(static fn($c)=>number_format((float)$c['latitude'],4,'.',''), $coords)),
            'longitude'=>implode(',', array_map(static fn($c)=>number_format((float)$c['longitude'],4,'.',''), $coords)),
            'minutely_15'=>'precipitation',
            'forecast_minutely_15'=>2,
            'timezone'=>'GMT',
        ]);
        return meteonexa_http_json('https://api.open-meteo.com/v1/forecast?' . $params, ['timeout'=>14,'max_bytes'=>700000]);
    }, 21600);
    $cacheMeta = is_array($raw['_meteonexaCache'] ?? null) ? $raw['_meteonexaCache'] : null;
    unset($raw['_meteonexaCache']);
    $rows = array_is_list($raw) ? $raw : array_values($raw);
    if (count($rows) !== count($coords)) {
        respond(['ok'=>true,'available'=>false,'degraded'=>true,'reason'=>'grid_incomplete','source'=>'MeteoNexa server proxy']);
    }
    $centroid = static function(int $index) use ($rows,$coords): ?array {
        $sw=0.0; $slat=0.0; $slon=0.0;
        foreach ($rows as $i=>$row) {
            $value=(float)($row['minutely_15']['precipitation'][$index] ?? 0);
            $weight=max(0.0,$value-.02);
            if ($weight<=0) continue;
            $sw += $weight; $slat += $coords[$i]['latitude']*$weight; $slon += $coords[$i]['longitude']*$weight;
        }
        return $sw>.01 ? ['latitude'=>$slat/$sw,'longitude'=>$slon/$sw,'weight'=>$sw] : null;
    };
    $a=$centroid(0); $b=$centroid(1);
    if (!$a || !$b) {
        respond(['ok'=>true,'available'=>false,'degraded'=>false,'reason'=>'no_directional_signal','source'=>'MeteoNexa server proxy','stale'=>(bool)($cacheMeta['stale']??false)]);
    }
    $distance=motion_quality_haversine($a,$b); $degrees=motion_quality_bearing($a,$b);
    respond([
        'ok'=>true,'available'=>true,'degrees'=>round($degrees,1),'distanceKm'=>round($distance,2),
        'strength'=>round(min((float)$a['weight'],(float)$b['weight']),3),
        'source'=>'MeteoNexa server proxy','stale'=>(bool)($cacheMeta['stale']??false),
        'cacheAgeSeconds'=>(int)($cacheMeta['ageSeconds']??0),
        'coordinatesRoundedDecimals'=>2,
    ]);
} catch (Throwable $error) {
    if (function_exists('meteonexa_log_event')) meteonexa_log_event('rain_direction_upstream_degraded', $error);
    // Upstream 429/5xx is a degraded optional signal, not an application error.
    // Return HTTP 200 so Safari/Firefox do not surface a noisy failed XHR and
    // the rest of Intelligence remains usable.
    respond(['ok'=>true,'available'=>false,'degraded'=>true,'reason'=>'upstream_unavailable','source'=>'MeteoNexa server proxy']);
}
