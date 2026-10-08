<?php
declare(strict_types=1);
function meteonexa_intelq_model_definitions() : array {
    return['ecmwf'=>['label'=>'ECMWF IFS', 'endpoint'=>'https://api.open-meteo.com/v1/ecmwf', 'cadenceMinutes'=>360], 'aifs'=>['label'=>'ECMWF AIFS AI', 'endpoint'=>'https://api.open-meteo.com/v1/ecmwf?models=ecmwf_aifs025', 'cadenceMinutes'=>360], 'icon'=>['label'=>'ICON', 'endpoint'=>'https://api.open-meteo.com/v1/dwd-icon', 'cadenceMinutes'=>180], 'gfs'=>['label'=>'GFS', 'endpoint'=>'https://api.open-meteo.com/v1/gfs', 'cadenceMinutes'=>60], 'meteofrance'=>['label'=>'Météo-France', 'endpoint'=>'https://api.open-meteo.com/v1/meteofrance', 'cadenceMinutes'=>60], 'ukmo'=>['label'=>'UKMO', 'endpoint'=>'https://api.open-meteo.com/v1/forecast?models=ukmo_seamless', 'cadenceMinutes'=>60],];
}
function meteonexa_intelq_model_url(string $endpoint, float $lat, float $lon, int $hours = 72) : string {
    $hours = max(24, min(96, $hours));
    $params = http_build_query(['latitude'=>round($lat, 4), 'longitude'=>round($lon, 4), 'hourly'=>'temperature_2m,precipitation,weather_code,wind_gusts_10m', 'forecast_hours'=>$hours, 'timezone'=>'GMT', 'wind_speed_unit'=>'kmh',], '', '&', PHP_QUERY_RFC3986);
    return $endpoint .(str_contains($endpoint, '?') ? '&' : '?') . $params;
}
function meteonexa_intelq_normalize_model(string $id, array $definition, array $raw) : array {
    $hourly = (array)($raw['hourly']??[]);
    $times = array_values((array)($hourly['time']??[]));
    $temperature = array_values((array)($hourly['temperature_2m']??[]));
    $precipitation = array_values((array)($hourly['precipitation']??[]));
    $codes = array_values((array)($hourly['weather_code']??[]));
    $gusts = array_values((array)($hourly['wind_gusts_10m']??[]));
    $count = min(count($times), max(count($temperature), count($precipitation), count($codes), count($gusts)));
    $rows =[];
    for ($i = 0; $i < $count; $i++) {
        $ts = meteonexa_intel_time_utc($times[$i]??'');
        if ($ts===null)continue;
        $rows[] =['time'=>gmdate('c', $ts), 'timestamp'=>$ts, 'temperature'=>is_numeric($temperature[$i]??null) ? round((float)$temperature[$i], 2) : null, 'precipitation'=>is_numeric($precipitation[$i]??null) ? max(0.0, round((float)$precipitation[$i], 3)) : null, 'weatherCode'=>is_numeric($codes[$i]??null) ? (int)$codes[$i] : null, 'windGust'=>is_numeric($gusts[$i]??null) ? max(0.0, round((float)$gusts[$i], 1)) : null,];
    }
    $cacheMeta = is_array($raw['_meteonexaCache']??null) ? (array)$raw['_meteonexaCache'] :[];
    $stale = !empty($cacheMeta['stale']);
    $ageSeconds = is_numeric($cacheMeta['ageSeconds']??null) ? max(0, (int)$cacheMeta['ageSeconds']) : 0;
    $fetchedAt = trim((string)($cacheMeta['fetchedAt']??''));
    if ($fetchedAt==='')$fetchedAt = gmdate('c');
    $cadence = max(1, (int)($definition['cadenceMinutes']??60));
    
    
    
    
    $now = time();
    $cadenceSeconds = $cadence * 60;
    $runTs = (int)(floor($now / $cadenceSeconds) * $cadenceSeconds);
    return['id'=>$id, 'label'=>(string)($definition['label']??strtoupper($id)), 'available'=>$rows!==[], 'rows'=>$rows, 'retrievedAt'=>$fetchedAt, 'ageMinutes'=>(int)ceil($ageSeconds / 60), 'fetchAgeMinutes'=>(int)ceil($ageSeconds / 60), 'cacheAgeMinutes'=>(int)ceil($ageSeconds / 60), 'modelRunEstimatedAt'=>gmdate('c', $runTs), 'modelRunAgeMinutes'=>(int)floor(($now - $runTs) / 60), 'runTimeEstimated'=>true, 'cacheHit'=>!empty($cacheMeta['cacheHit']), 'providerLatencyMs'=>is_numeric($cacheMeta['latencyMs']??null) ? (int)$cacheMeta['latencyMs'] : null, 'stale'=>$stale, 'cadenceMinutes'=>$cadence,];
}
function meteonexa_model_cache_path(string $kind, float $lat, float $lon) : string {
    $dir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($dir))@mkdir($dir, 0770, true);
    $key = hash('sha256', $kind . '|' . number_format($lat, 4, '.', '') . '|' . number_format($lon, 4, '.', ''));
    return $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '-', $kind) . '-' . substr($key, 0, 24) . '.json';
}






function meteonexa_fetch_models_parallel(array $definitions, float $lat, float $lon, int $hours, array $options =[]) : array {
    $ttl = 300;
    $staleTtl = 21600;
    $budget = max(3, min(20, (int)($options['budget']??9)));
    $perTimeout = max(3, min(18, (int)($options['timeout']??8)));
    $maxBytes = 1300000;
    $result =[];
    $pending =[];
    $cachedById =[];
    foreach ($definitions as $id=>$definition) {
        $kind = 'model-' . $id . '-' . $hours;
        $path = meteonexa_model_cache_path($kind, $lat, $lon);
        $cached = null;
        $age = null;
        $mtime = 0;
        if (is_file($path)) {
            $mtime = (int)@filemtime($path);
            $age = max(0, time() - $mtime);
            $decoded = json_decode((string)@file_get_contents($path), true);
            if (is_array($decoded))$cached = $decoded;
        }
        if (is_array($cached)&&$age!==null&&$age < $ttl) {
            $cached['_meteonexaCache'] =['stale'=>false, 'ageSeconds'=>$age, 'fetchedAt'=>$mtime ? gmdate('c', $mtime) : null, 'cacheHit'=>true];
            $result[$id] = meteonexa_intelq_normalize_model($id, $definition, $cached);
            continue;
        }
        $cachedById[$id] =['data'=>$cached, 'age'=>$age, 'mtime'=>$mtime, 'path'=>$path, 'kind'=>$kind];
        $pending[$id] =['definition'=>$definition, 'url'=>meteonexa_intelq_model_url((string)$definition['endpoint'], $lat, $lon, $hours)];
    }
    if (!$pending)return $result;
    if (!function_exists('curl_multi_init')||!function_exists('curl_init'))throw new RuntimeException('PARALLEL_TRANSPORT_UNAVAILABLE');
    $mh = curl_multi_init();
    $handles =[];
    $started = microtime(true);
    foreach ($pending as $id=>$item) {
        $ch = curl_init($item['url']);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_CONNECTTIMEOUT=>min(5, $perTimeout), CURLOPT_TIMEOUT=>$perTimeout, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_HTTPHEADER=>['Accept: application/json', 'User-Agent: MeteoNexa/20.1']]);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] =['handle'=>$ch, 'start'=>microtime(true)];
    }
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status!==CURLM_OK)break;
        if (!$active)break;
        $elapsed = microtime(true) - $started;
        if ($elapsed>=$budget)break;
        $selected = curl_multi_select($mh, max(.05, min(.5, $budget - $elapsed)));
        if ($selected=== - 1)usleep(20000);
    }
    while (true);
    foreach ($handles as $id=>$entry) {
        $ch = $entry['handle'];
        $body = (string)curl_multi_getcontent($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $latency = (int)round((microtime(true) - $entry['start']) * 1000);
        $definition = $pending[$id]['definition'];
        $cache = $cachedById[$id];
        $raw = null;
        $status = 'error';
        if ($errno===0&&$http>=200&&$http < 300&&strlen($body) > 0&&strlen($body)<=$maxBytes) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $raw = $decoded;
                $persist = $decoded;
                unset($persist['_meteonexaCache']);
                @file_put_contents($cache['path'], json_encode($persist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                @chmod($cache['path'], 0600);
                $raw['_meteonexaCache'] =['stale'=>false, 'ageSeconds'=>0, 'fetchedAt'=>gmdate('c'), 'cacheHit'=>false, 'latencyMs'=>$latency];
                $status = 'ok';
            }
        }
        if ($raw===null&&is_array($cache['data'])&&is_numeric($cache['age'])&&(int)$cache['age']<=$staleTtl) {
            $raw = $cache['data'];
            $raw['_meteonexaCache'] =['stale'=>true, 'ageSeconds'=>(int)$cache['age'], 'fetchedAt'=>$cache['mtime'] ? gmdate('c', (int)$cache['mtime']) : null, 'cacheHit'=>true, 'latencyMs'=>$latency];
            $status = 'stale_fallback';
        }
        if (is_array($raw))$result[$id] = meteonexa_intelq_normalize_model($id, $definition, $raw);
        else $result[$id] =['id'=>$id, 'label'=>$definition['label'], 'available'=>false, 'rows'=>[], 'retrievedAt'=>gmdate('c'), 'ageMinutes'=>null, 'fetchAgeMinutes'=>null, 'cacheAgeMinutes'=>null, 'modelRunEstimatedAt'=>null, 'modelRunAgeMinutes'=>null, 'runTimeEstimated'=>true, 'stale'=>false, 'cadenceMinutes'=>$definition['cadenceMinutes'], 'reason'=>'provider_unavailable'];
        if (function_exists('meteonexa_observability_event'))meteonexa_observability_event('provider', 'model-' . $id, $status,['latencyMs'=>$latency, 'http'=>$http, 'budgetSeconds'=>$budget]);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $result;
}
function meteonexa_intelq_fetch_models(float $lat, float $lon, bool $demo = false, int $hours = 72) : array {
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    $definitions = meteonexa_intelq_model_definitions();
    $config = function_exists('load_config') ? load_config() :[];
    $orchestrator = (array)($config['provider_orchestrator']??[]);
    if (($orchestrator['enabled']??true)&&function_exists('curl_multi_init')) {
        try {
            return meteonexa_fetch_models_parallel($definitions, $lat, $lon, $hours,['budget'=>$orchestrator['total_budget_seconds']??9, 'timeout'=>$orchestrator['per_provider_timeout_seconds']??8]);
        } catch (Throwable $error) {
            if (function_exists('meteonexa_observability_event'))meteonexa_observability_event('provider', 'model-orchestrator', 'degraded',['class'=>get_class($error)]);
        }
    }
    $result =[];
    foreach ($definitions as $id=>$definition) {
        try {
            $raw = meteonexa_intel_provider_cache('model-' . $id . '-' . $hours, $lat, $lon, 300, static function()use($definition, $lat, $lon, $hours) : array {
                return meteonexa_http_json(meteonexa_intelq_model_url((string)$definition['endpoint'], $lat, $lon, $hours),['timeout'=>18, 'max_bytes'=>1300000]);
            });
            $result[$id] = meteonexa_intelq_normalize_model($id, $definition, $raw);
        } catch (Throwable $error) {
            $result[$id] =['id'=>$id, 'label'=>$definition['label'], 'available'=>false, 'rows'=>[], 'retrievedAt'=>gmdate('c'), 'ageMinutes'=>null, 'fetchAgeMinutes'=>null, 'cacheAgeMinutes'=>null, 'modelRunEstimatedAt'=>null, 'modelRunAgeMinutes'=>null, 'runTimeEstimated'=>true, 'stale'=>false, 'cadenceMinutes'=>$definition['cadenceMinutes'], 'reason'=>'provider_unavailable'];
        }
    }
    return $result;
}
function meteonexa_intelq_is_rain_row(array $row) : bool {
    $mm = is_numeric($row['precipitation']??null) ? (float)$row['precipitation'] : 0.0;
    $code = is_numeric($row['weatherCode']??null) ? (int)$row['weatherCode'] : 0;
    return $mm>=0.2||in_array($code,[51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99], true);
}
function meteonexa_intelq_is_storm_row(array $row) : bool {
    return in_array((int)($row['weatherCode']??0),[95, 96, 99], true);
}
function meteonexa_intelq_is_snow_row(array $row) : bool {
    return in_array((int)($row['weatherCode']??0),[71, 73, 75, 77, 85, 86], true);
}
function meteonexa_intelq_hour_buckets(array $models) : array {
    $buckets =[];
    foreach ($models as $id=>$model) {
        if (empty($model['available']))continue;
        foreach ((array)($model['rows']??[]) as $row) {
            $ts = (int)($row['timestamp']??0);
            if ($ts<=0)continue;
            $key = gmdate('Y-m-d\TH:00:00\Z', $ts);
            $buckets[$key][$id] = $row;
        }
    }
    ksort($buckets);
    return $buckets;
}
function meteonexa_intelq_contiguous_windows(array $hourly, string $type, int $minimumVotes = 3) : array {
    $windows =[];
    $current = null;
    foreach ($hourly as $row) {
        $votes = (int)($row[$type . 'Votes']??0);
        $available = (int)($row['available']??0);
        $hit = $available>=3&&$votes>=min($minimumVotes, $available);
        if ($hit) {
            if ($current===null) {
                $current =['start'=>$row['time'], 'end'=>$row['time'], 'rows'=>[]];
            }
            $current['end'] = $row['time'];
            $current['rows'][] = $row;
        } elseif ($current!==null) {
            $windows[] = $current;
            $current = null;
        }
    }
    if ($current!==null)$windows[] = $current;
    foreach ($windows as &$window) {
        $rows = $window['rows'];
        $agreements = array_map(static fn($r)=>((int)$r['available'] > 0) ? 100 * (int)$r[$type . 'Votes'] / (int)$r['available'] : 0, $rows);
        
        
        
        
        $representative = null;
        $bestRatio = - 1.0;
        $bestVotes = - 1;
        foreach ($rows as $r) {
            $available = (int)($r['available']??0);
            $votes = (int)($r[$type . 'Votes']??0);
            $ratio = $available > 0 ? 100 * $votes / $available : 0.0;
            if ($representative===null||$ratio > $bestRatio||($ratio===$bestRatio&&$votes > $bestVotes)) {
                $representative = $r;
                $bestRatio = $ratio;
                $bestVotes = $votes;
            }
        }
        $representative = is_array($representative) ? $representative : $rows[0];
        $start = meteonexa_intel_time_utc($window['start']);
        $end = meteonexa_intel_time_utc($window['end']);
        $window['startsAt'] = $start ? gmdate('c', $start) : $window['start'];
        $window['endsAt'] = $end ? gmdate('c', $end + 3600) : $window['end'];
        $window['votes'] = (int)($representative[$type . 'Votes']??0);
        $window['available'] = (int)($representative['available']??0);
        $window['agreementPct'] = $window['available'] > 0 ? (int)round(100 * $window['votes'] / $window['available']) : 0;
        $window['windowAgreementPct'] = (int)round(array_sum($agreements) / max(1, count($agreements)));
        $window['representativeAt'] = $representative['time']??null;
        $window['modelVotes'] = (array)($representative[$type . 'Models']??[]);
        unset($window['rows'], $window['start'], $window['end']);
    }
    unset($window);
    return $windows;
}
function meteonexa_intelq_consensus(array $models) : array {
    $buckets = meteonexa_intelq_hour_buckets($models);
    $hourly =[];
    foreach ($buckets as $time=>$rows) {
        $available = count($rows);
        if ($available===0)continue;
        $rainModels =[];
        $stormModels =[];
        $snowModels =[];
        $precipModels =[];
        $windGustModels =[];
        $temperatures =[];
        $gusts =[];
        $rainMm =[];
        foreach ($rows as $id=>$row) {
            $rain = meteonexa_intelq_is_rain_row($row);
            $storm = meteonexa_intelq_is_storm_row($row);
            $snow = meteonexa_intelq_is_snow_row($row);
            $rainModels[$id] = $rain;
            $stormModels[$id] = $storm;
            $snowModels[$id] = $snow;
            $precipModels[$id] = is_numeric($row['precipitation']??null)&&(float)$row['precipitation']>=0.1;
            if (is_numeric($row['temperature']??null))$temperatures[] = (float)$row['temperature'];
            if (is_numeric($row['windGust']??null)) {
                $gust = (float)$row['windGust'];
                $gusts[] = $gust;
                $windGustModels[$id] = $gust;
            } else {
                $windGustModels[$id] = null;
            }
            if (is_numeric($row['precipitation']??null))$rainMm[] = (float)$row['precipitation'];
        }
        $rainMmSorted = $rainMm;
        sort($rainMmSorted, SORT_NUMERIC);
        $rainCount = count($rainMmSorted);
        $rainMedian = null;
        if ($rainCount > 0) {
            $mid = intdiv($rainCount, 2);
            $rainMedian = $rainCount % 2===1 ? (float)$rainMmSorted[$mid] :((float)$rainMmSorted[$mid - 1] + (float)$rainMmSorted[$mid]) / 2;
        }
        $wetRainMm = array_values(array_filter($rainMm, static fn(float $mm) : bool=>$mm>=0.1));
        $hourly[] =['time'=>$time, 'available'=>$available, 'rainVotes'=>count(array_filter($rainModels)), 'rainModels'=>$rainModels, 'stormVotes'=>count(array_filter($stormModels)), 'stormModels'=>$stormModels, 'snowVotes'=>count(array_filter($snowModels)), 'snowModels'=>$snowModels,
        
        
        
        'precipitationVotes'=>count(array_filter($precipModels)), 'precipitationModels'=>$precipModels, 'temperatureSpread'=>$temperatures ? round(max($temperatures) - min($temperatures), 2) : null, 'temperatureMean'=>$temperatures ? round(array_sum($temperatures) / count($temperatures), 2) : null, 'windSpread'=>$gusts ? round(max($gusts) - min($gusts), 1) : null, 'windGustMean'=>$gusts ? round(array_sum($gusts) / count($gusts), 1) : null, 'windGustMax'=>$gusts ? round(max($gusts), 1) : null, 'windGustModels'=>$windGustModels, 'precipitationSpread'=>$rainMm ? round(max($rainMm) - min($rainMm), 2) : null, 'precipitationMean'=>$rainMm ? round(array_sum($rainMm) / count($rainMm), 2) : null,
        
        
        
        
        
        'precipitationMedian'=>$rainMedian===null ? null : round($rainMedian, 2), 'precipitationWetMean'=>$wetRainMm ? round(array_sum($wetRainMm) / count($wetRainMm), 2) : null, 'precipitationMax'=>$rainMm ? round(max($rainMm), 2) : null,];
    }
    $rainWindows = meteonexa_intelq_contiguous_windows($hourly, 'rain');
    $stormWindows = meteonexa_intelq_contiguous_windows($hourly, 'storm');
    $primary = $stormWindows[0]??$rainWindows[0]??null;
    $primaryType = $stormWindows ? 'storm' :($rainWindows ? 'rain' : null);
    $availableModels =[];
    foreach ($models as $id=>$model) if (!empty($model['available']))$availableModels[] = $id;
    if (is_array($primary)) {
        $primary['type'] = $primaryType;
        $yes =[];
        $no =[];
        foreach ($availableModels as $id) {
            if (!empty($primary['modelVotes'][$id]))$yes[] = $id;
            else $no[] = $id;
        }
        $primary['agreeingModels'] = $yes;
        $primary['outliers'] = $no;
    }
    return['available'=>count($availableModels)>=2, 'modelsAvailable'=>count($availableModels), 'modelsExpected'=>count(meteonexa_intelq_model_definitions()), 'modelIds'=>$availableModels, 'primary'=>$primary, 'rainWindows'=>$rainWindows, 'stormWindows'=>$stormWindows, 'hourly'=>array_slice($hourly, 0, 72),];
}
