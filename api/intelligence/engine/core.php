<?php
declare(strict_types=1);
function meteonexa_intel_clamp(float $value, float $min, float $max) : float {
    return max($min, min($max, $value));
}
function meteonexa_intel_number(mixed $value, float $fallback = 0.0) : float {
    return is_numeric($value) ? (float)$value : $fallback;
}
function meteonexa_intel_arr(array $data, string $key) : array {
    return isset($data[$key])&&is_array($data[$key]) ? $data[$key] :[];
}

function meteonexa_intel_time_utc(mixed $value) : ? int {
    $raw = trim((string)$value);
    if ($raw==='')return null;
    try {
        return(new DateTimeImmutable($raw, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $ignored) {
        return null;
    }
}

function meteonexa_intel_provider_cache(string $kind, float $lat, float $lon, int $ttl, callable $loader, int $staleTtl = 21600) : array {
    $dir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($dir))@mkdir($dir, 0770, true);
    $key = hash('sha256', $kind . '|' . number_format($lat, 4, '.', '') . '|' . number_format($lon, 4, '.', ''));
    $path = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '-', $kind) . '-' . substr($key, 0, 24) . '.json';
    $cached = null;
    $age = null;
    if (is_file($path)) {
        $mtime = (int)@filemtime($path);
        $age = max(0, time() - $mtime);
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded))$cached = $decoded;
        if (is_array($cached)&&$age < max(30, $ttl)) {
            $cached['_meteonexaCache'] =['stale'=>false, 'ageSeconds'=>$age, 'fetchedAt'=>$mtime > 0 ? gmdate('c', $mtime) : null, 'cacheHit'=>true];
            return $cached;
        }
    }
    try {
        $started = microtime(true);
        $data = $loader();
        if (!is_array($data))throw new RuntimeException('PROVIDER_RESPONSE_INVALID');
        $persist = $data;
        unset($persist['_meteonexaCache']);
        @file_put_contents($path, json_encode($persist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($path, 0600);
        $data['_meteonexaCache'] =['stale'=>false, 'ageSeconds'=>0, 'fetchedAt'=>gmdate('c'), 'cacheHit'=>false, 'latencyMs'=>(int)round((microtime(true) - $started) * 1000)];
        return $data;
    } catch (Throwable $error) {
        
        
        
        if (is_array($cached)&&$age!==null&&$age<=max($ttl, $staleTtl)) {
            $cached['_meteonexaCache'] =['stale'=>true, 'ageSeconds'=>$age, 'fetchedAt'=>is_file($path) ? gmdate('c', (int)@filemtime($path)) : null, 'cacheHit'=>true];
            meteonexa_observability_event('provider', $kind, 'stale_fallback',['ageSeconds'=>$age]);
            return $cached;
        }
        meteonexa_observability_event('provider', $kind, 'error',['class'=>get_class($error)]);
        throw $error;
    }
}
function meteonexa_intelligence_location_key(float $lat, float $lon) : string {
    return number_format($lat, 4, '.', '') . ':' . number_format($lon, 4, '.', '');
}
function meteonexa_intelligence_weather(float $lat, float $lon, bool $demo = false, int $forecastHours = 36) : array {
    
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    $forecastHours = max(24, min(96, $forecastHours));
    return meteonexa_intel_provider_cache('weather-' . $forecastHours, $lat, $lon, 180, static function()use($lat, $lon, $forecastHours) : array {
        $params = http_build_query(['latitude'=>$lat, 'longitude'=>$lon, 'current'=>'temperature_2m,apparent_temperature,precipitation,rain,snowfall,weather_code,cloud_cover,wind_speed_10m,wind_gusts_10m,visibility', 'minutely_15'=>'precipitation,rain,snowfall,weather_code', 'forecast_minutely_15'=>8, 'hourly'=>'temperature_2m,apparent_temperature,precipitation_probability,precipitation,rain,snowfall,weather_code,visibility,wind_speed_10m,wind_gusts_10m,cape,relative_humidity_2m,cloud_cover,is_day,sunshine_duration,shortwave_radiation', 'forecast_hours'=>$forecastHours,
        
        'timezone'=>'GMT', 'wind_speed_unit'=>'kmh',]); return meteonexa_http_json('https://api.open-meteo.com/v1/forecast?' . $params,['timeout'=>18, 'max_bytes'=>1500000]);
    });
}
function meteonexa_intelligence_air(float $lat, float $lon, bool $demo = false) : ? array {
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    try {
        return meteonexa_intel_provider_cache('air', $lat, $lon, 300, static function()use($lat, $lon) : array {
            $params = http_build_query(['latitude'=>$lat, 'longitude'=>$lon, 'hourly'=>'european_aqi,pm2_5', 'forecast_days'=>2, 'timezone'=>'GMT',]); return meteonexa_http_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . $params,['timeout'=>14, 'max_bytes'=>700000]);
        });
    } catch (Throwable $ignored) {
        return null;
    }
}
function meteonexa_intelligence_lightning(array $config, float $lat, float $lon) : array {
    $client = trim((string)($config['lightning']['client_id']??''));
    $secret = trim((string)($config['lightning']['client_secret']??''));
    if ($client===''||$secret==='')return['available'=>false, 'count'=>0, 'nearestKm'=>null, 'approaching'=>false];
    $radius = max(10, min(100, (int)($config['lightning']['radius_km']??80)));
    try {
        return meteonexa_intel_provider_cache('lightning-' . $radius, $lat, $lon, 60, static function()use($client, $secret, $lat, $lon, $radius) : array {
            $url = 'https://data.api.xweather.com/lightning/closest?p=' . rawurlencode($lat . ',' . $lon) . '&radius=' . $radius . 'km&limit=100&client_id=' . rawurlencode($client) . '&client_secret=' . rawurlencode($secret); $raw = meteonexa_http_json($url,['timeout'=>14, 'max_bytes'=>1000000]); $responses = is_array($raw['response']??null) ? $raw['response'] :[]; $nearest = null; $recent = 0; $latestTs = 0; foreach ($responses as $row) {
                if (!is_array($row))continue; $distance = null; foreach (['distanceKM', 'distance_km', 'distance'] as $field) if (isset($row[$field])&&is_numeric($row[$field])) {
                    $distance = (float)$row[$field]; break;
                }
                if ($distance!==null)$nearest = $nearest===null ? $distance : min($nearest, $distance); $ts = (int)($row['timestamp']??$row['ob']['timestamp']??0); if ($ts > $latestTs)$latestTs = $ts; $age = $row['ob']['age']??$row['age']??null; if (($ts > 0&&$ts>=time() - 1800)||(is_numeric($age)&&(float)$age>=0&&(float)$age<=1800))$recent++;
            }
            return['available'=>true, 'count'=>count($responses), 'recent30m'=>$recent, 'nearestKm'=>$nearest===null ? null : round($nearest, 1), 'approaching'=>$recent>=3&&($nearest===null||$nearest<=35), 'observedAt'=>$latestTs > 0 ? gmdate('c', $latestTs) : null];
        }, 300);
    } catch (Throwable $ignored) {
        return['available'=>true, 'count'=>0, 'nearestKm'=>null, 'approaching'=>false, 'degraded'=>true];
    }
}
function meteonexa_intelligence_satellite(float $lat, float $lon) : array {
    
    
    
    $cacheDir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($cacheDir))@mkdir($cacheDir, 0770, true);
    $cacheKey = number_format(round($lat, 1), 1, '.', '_') . '_' . number_format(round($lon, 1), 1, '.', '_');
    $cacheFile = $cacheDir . '/satellite-' . $cacheKey . '.json';
    if (is_file($cacheFile)&&time() - (int)filemtime($cacheFile) < 300) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached))return $cached;
    }
    $result =['available'=>false, 'source'=>'Open-Meteo Satellite / EUMETSAT'];
    try {
        $url = 'https://satellite-api.open-meteo.com/v1/archive?' . http_build_query(['latitude'=>round($lat, 4), 'longitude'=>round($lon, 4), 'hourly'=>'shortwave_radiation,shortwave_radiation_clear_sky', 'past_days'=>1, 'forecast_days'=>1, 'timezone'=>'GMT'], '', '&', PHP_QUERY_RFC3986);
        $raw = meteonexa_http_json($url,['timeout'=>15, 'max_bytes'=>750000]);
        $hourly = (array)($raw['hourly']??[]);
        $times = (array)($hourly['time']??[]);
        $actual = (array)($hourly['shortwave_radiation']??[]);
        $clear = (array)($hourly['shortwave_radiation_clear_sky']??[]);
        $best = null;
        $now = time() + 1200;
        for ($i = min(count($times), count($actual), count($clear)) - 1; $i>=0; $i--) {
            if (!is_numeric($actual[$i]??null)||!is_numeric($clear[$i]??null))continue;
            $ts = meteonexa_intel_time_utc($times[$i]??'');
            $clr = (float)$clear[$i];
            if ($ts===null||$ts > $now||$clr < 40)continue;
            $act = max(0.0, (float)$actual[$i]);
            $ratio = meteonexa_intel_clamp($act / max(1.0, $clr), 0, 1.25);
            $attenuation = meteonexa_intel_clamp((1 - $ratio) * 100, 0, 100);
            $best =['available'=>true, 'source'=>'Open-Meteo Satellite / EUMETSAT', 'observedAt'=>gmdate('c', $ts), 'shortwaveWm2'=>round($act, 1), 'clearSkyWm2'=>round($clr, 1), 'cloudAttenuationPct'=>round($attenuation), 'support'=>round($attenuation / 100, 3)];
            break;
        }
        if ($best)$result = $best;
    } catch (Throwable $ignored) {
        $result['degraded'] = true;
    }
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $result;
}
