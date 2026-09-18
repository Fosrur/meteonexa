<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
function meteonexa_obs_flags(string $wx) : array {
    $w = strtoupper($wx);
    return['rain'=>preg_match('/RA|DZ|SHRA|FZRA/', $w) ? 1.0 : 0.0, 'storm'=>preg_match('/TS|VCTS/', $w) ? 1.0 : 0.0, 'snow'=>preg_match('/SN|SG|SHSN/', $w) ? 1.0 : 0.0];
}
function meteonexa_obs_quality( ? float $distance, ? int $age, int $base = 95) : int {
    return (int)round(meteonexa_intel_clamp($base - min(35,($distance??25) * .28) - min(40,($age??25) * .4), 15, 99));
}
function meteonexa_obs_metar(array $config, float $lat, float $lon) : ? array {
    if (isset($config['observations']['metar_enabled'])&&!$config['observations']['metar_enabled'])return null;
    $qlat = round($lat, 2);
    $qlon = round($lon, 2);
    $max = max(20, min(250, (float)($config['observations']['metar_max_distance_km']??140)));
    $latPad = $max / 111;
    $lonPad = $max / max(25, 111 * cos(deg2rad($qlat)));
    $bbox = implode(',',[round($qlat - $latPad, 2), round($qlon - $lonPad, 2), round($qlat + $latPad, 2), round($qlon + $lonPad, 2)]);
    $url = 'https://aviationweather.gov/api/data/metar?' . http_build_query(['bbox'=>$bbox, 'format'=>'json'], '', '&', PHP_QUERY_RFC3986);
    try {
        $rows = meteonexa_intel_provider_cache('obs-metar', $qlat, $qlon, 300, static fn()=>meteonexa_http_json($url,['timeout'=>16, 'max_bytes'=>1600000, 'pin_dns'=>true, 'headers'=>['Accept: application/json', 'User-Agent: MeteoNexa/20.1']]), 1800);
        $best = null;
        $bestScore = INF;
        foreach ((array)$rows as $r) {
            if (!is_array($r)||!is_numeric($r['lat']??null)||!is_numeric($r['lon']??null))continue;
            $d = haversine_km($lat, $lon, (float)$r['lat'], (float)$r['lon']);
            if ($d > $max)continue;
            $ts = is_numeric($r['obsTime']??null) ? (int)$r['obsTime'] :(strtotime((string)($r['reportTime']??'')) ? : 0);
            if ($ts<=0)continue;
            $age = max(0, (int)round((time() - $ts) / 60));
            if ($age > 150)continue;
            $score = $d + $age * .45;
            if ($score>=$bestScore)continue;
            $flags = meteonexa_obs_flags((string)($r['wxString']??$r['rawOb']??''));
            $wind = is_numeric($r['wgst']??null) ? (float)$r['wgst'] :(is_numeric($r['wspd']??null) ? (float)$r['wspd'] : null);
            $best =['source'=>'NOAA Aviation Weather Center', 'sourceType'=>'metar', 'sourceId'=>(string)($r['icaoId']??''), 'station'=>(string)($r['name']??$r['icaoId']??'METAR'), 'latitude'=>(float)$r['lat'], 'longitude'=>(float)$r['lon'], 'distanceKm'=>round($d, 1), 'observedAt'=>gmdate('c', $ts), 'ageMinutes'=>$age, 'temperature'=>is_numeric($r['temp']??null) ? (float)$r['temp'] : null, 'wind'=>$wind===null ? null : round($wind * 1.852, 1), 'precipitation'=>null, 'rain'=>$flags['rain'], 'storm'=>$flags['storm'], 'snow'=>$flags['snow'], 'qualityScore'=>meteonexa_obs_quality($d, $age, 96), 'independentFromNwp'=>true];
            $bestScore = $score;
        }
        return $best;
    } catch (Throwable $e) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('observation_metar_failed', $e);
        return null;
    }
}
function meteonexa_obs_configured(array $config, string $kind, float $lat, float $lon) : ? array {
    $template = trim((string)($config['observations'][$kind . '_url']??''));
    if ($template==='')return null;
    $url = str_replace(['{lat}', '{lon}'],[(string)round($lat, 2), (string)round($lon, 2)], $template);
    try {
        $raw = meteonexa_http_json($url,['timeout'=>16, 'max_bytes'=>1200000, 'pin_dns'=>true, 'headers'=>['Accept: application/json', 'User-Agent: MeteoNexa/20.1']]);
        $rows = is_array($raw['observations']??null) ? $raw['observations'] :(array_is_list($raw) ? $raw :[$raw]);
        $best = null;
        $score = INF;
        $max = (float)($config['observations'][$kind . '_max_distance_km']??100);
        foreach ($rows as $r) {
            if (!is_array($r))continue;
            $rlat = $r['lat']??$r['latitude']??null;
            $rlon = $r['lon']??$r['longitude']??null;
            if (!is_numeric($rlat)||!is_numeric($rlon))continue;
            $d = haversine_km($lat, $lon, (float)$rlat, (float)$rlon);
            if ($d > $max)continue;
            $ts = meteonexa_intel_time_utc($r['observedAt']??$r['time']??'');
            if ($ts===null)continue;
            $age = max(0, (int)round((time() - $ts) / 60));
            if ($age > 180||$d + $age * .5>=$score)continue;
            $flags = meteonexa_obs_flags((string)($r['weather']??''));
            foreach (['rain', 'storm', 'snow'] as $m) if (array_key_exists($m, $r))$flags[$m] = !empty($r[$m]) ? 1.0 : 0.0;
            $best =['source'=>(string)($r['source']??strtoupper($kind)), 'sourceType'=>$kind, 'sourceId'=>(string)($r['id']??$r['stationId']??''), 'station'=>(string)($r['station']??$r['name']??strtoupper($kind)), 'latitude'=>(float)$rlat, 'longitude'=>(float)$rlon, 'distanceKm'=>round($d, 1), 'observedAt'=>gmdate('c', $ts), 'ageMinutes'=>$age, 'temperature'=>is_numeric($r['temperature']??null) ? (float)$r['temperature'] : null, 'wind'=>is_numeric($r['windGust']??null) ? (float)$r['windGust'] : null, 'precipitation'=>is_numeric($r['precipitation']??null) ? (float)$r['precipitation'] : null, 'rain'=>$flags['rain'], 'storm'=>$flags['storm'], 'snow'=>$flags['snow'], 'qualityScore'=>meteonexa_obs_quality($d, $age, 94), 'independentFromNwp'=>true];
            $score = $d + $age * .5;
        }
        return $best;
    } catch (Throwable $e) {
        return null;
    }
}
function meteonexa_obs_persist(PDO $pdo, string $deviceId, string $locationKey, array $rows) : void {
    if (!meteonexa_db_table_exists($pdo, 'observation_evidence'))return;
    $st = $pdo->prepare('INSERT OR IGNORE INTO observation_evidence(device_id,location_key,source_type,source_id,observed_at,temperature,precipitation,wind_gust,rain_event,storm_event,snow_event,distance_km,quality_score,payload_json,created_at) VALUES(:device,:location,:type,:source,:observed,:temperature,:precipitation,:wind,:rain,:storm,:snow,:distance,:quality,:payload,:created)');
    foreach ($rows as $r) {
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey, ':type'=>$r['sourceType']??'unknown', ':source'=>$r['sourceId']??'', ':observed'=>$r['observedAt']??gmdate('c'), ':temperature'=>$r['temperature']??null, ':precipitation'=>$r['precipitation']??null, ':wind'=>$r['wind']??null, ':rain'=>$r['rain']??null, ':storm'=>$r['storm']??null, ':snow'=>$r['snow']??null, ':distance'=>$r['distanceKm']??null, ':quality'=>$r['qualityScore']??50, ':payload'=>json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':created'=>gmdate('c')]);
    }
}
function meteonexa_observations_collect(PDO $pdo, array $config, string $deviceId, float $lat, float $lon, bool $persist = true) : array {
    $rows =[];
    foreach ([meteonexa_obs_metar($config, $lat, $lon), meteonexa_obs_configured($config, 'synop', $lat, $lon), meteonexa_obs_configured($config, 'arpa', $lat, $lon)] as $r) if (is_array($r))$rows[] = $r;
    $locationKey = meteonexa_intelligence_location_key($lat, $lon);
    if ($persist&&$rows)meteonexa_obs_persist($pdo, $deviceId, $locationKey, $rows);
    $weighted = function(string $metric, bool $binary = false)use($rows) {
        $sum = 0;
        $w = 0;
        foreach ($rows as $r) {
            if (!is_numeric($r[$metric]??null))continue;
            $q = max(.05, min(1, (float)($r['qualityScore']??50) / 100));
            $sum+=(float)$r[$metric] * $q;
            $w+=$q;
        }
        if (!$w)return null;
        $v = $sum / $w;
        return $binary ?($v>=.5 ? 1.0 : 0.0) : round($v, 2);
    };
    return['available'=>$rows!==[], 'independentFromNwp'=>$rows!==[], 'sourceCount'=>count($rows), 'sourceTypes'=>array_values(array_unique(array_column($rows, 'sourceType'))), 'qualityScore'=>$rows ? (int)round(array_sum(array_column($rows, 'qualityScore')) / count($rows)) : 0, 'observedAt'=>$rows[0]['observedAt']??gmdate('c'), 'temperature'=>$weighted('temperature'), 'wind'=>$weighted('wind'), 'precipitation'=>$weighted('precipitation'), 'rain'=>$weighted('rain', true), 'storm'=>$weighted('storm', true), 'snow'=>$weighted('snow', true), 'evidence'=>$rows];
}
