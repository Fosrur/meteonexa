<?php
declare(strict_types=1);
/**
 * MeteoNexa Verified Trust Intelligence Quality helpers.
 *
 * The functions in this file are deterministic. No LLM is allowed to create,
 * suppress or raise a weather event. Model consensus, calibration, source
 * freshness, run comparison and geospatial alert matching are explicit data
 * processing steps that can be inspected by QA/Diagnostics.
 */
require_once __DIR__ . '/engine_helpers.php';
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
    // Open-Meteo model endpoints do not currently expose one uniform upstream
    // model-initialisation timestamp. Keep fetch age and model-run age separate:
    // runEstimatedAt is a conservative cadence-bound estimate, explicitly marked
    // estimated so the UI never presents fetch freshness as model-cycle freshness.
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
/**
 * provider orchestrator. Fixed, server-owned model URLs are fetched in
 * parallel when cURL multi is available. A hard total budget returns partial
 * model evidence rather than making the whole Intelligence response wait for
 * one slow provider. Fresh/stale cache semantics remain unchanged.
 */
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
        // Use one representative peak-consensus hour for the headline vote and chips.
        // The previous implementation mixed max votes, average agreement and a union of model votes
        // across the whole window, which could render impossible combinations such as 5/6 = 70%
        // while all five model chips were marked as agreeing.
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
        // Keep measurable precipitation separate from WMO condition codes.
        // Some models can retain a rain code with ~0 mm; Panoramica must not
        // treat that code alone as proof that measurable rain continues.
        'precipitationVotes'=>count(array_filter($precipModels)), 'precipitationModels'=>$precipModels, 'temperatureSpread'=>$temperatures ? round(max($temperatures) - min($temperatures), 2) : null, 'temperatureMean'=>$temperatures ? round(array_sum($temperatures) / count($temperatures), 2) : null, 'windSpread'=>$gusts ? round(max($gusts) - min($gusts), 1) : null, 'windGustMean'=>$gusts ? round(array_sum($gusts) / count($gusts), 1) : null, 'windGustMax'=>$gusts ? round(max($gusts), 1) : null, 'windGustModels'=>$windGustModels, 'precipitationSpread'=>$rainMm ? round(max($rainMm) - min($rainMm), 2) : null, 'precipitationMean'=>$rainMm ? round(array_sum($rainMm) / count($rainMm), 2) : null,
        // Median is deliberately exposed alongside the mean: Panoramica uses
        // this robust consensus amount when >=3 fresh models agree on
        // measurable precipitation. This prevents one very wet outlier from
        // inflating the quantity and prevents a dry Best Match value from
        // hiding a 3/5 or stronger wet consensus.
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
function meteonexa_intelq_canonical_consensus(array $models) : array {
    $fresh = array_filter($models, static fn(array $model) : bool=>!empty($model['available'])&&empty($model['stale']));
    $available = array_filter($models, static fn(array $model) : bool=>!empty($model['available']));
    $useFresh = count($fresh)>=3;
    $selected = $useFresh ? $fresh : $available;
    $consensus = meteonexa_intelq_consensus($selected);
    $consensus['sourceMode'] = $useFresh ? 'fresh_consensus' :(count($available)>=2 ? 'stale_fallback' : 'insufficient_models');
    $consensus['degraded'] = !$useFresh;
    $consensus['freshModelsAvailable'] = count($fresh);
    $consensus['modelsAvailable'] = count($available);
    $consensus['modelsExpected'] = count(meteonexa_intelq_model_definitions());
    return $consensus;
}
function meteonexa_intelq_model_at_target(array $model, int $target) : ? array {
    $best = null;
    $delta = PHP_INT_MAX;
    foreach ((array)($model['rows']??[]) as $row) {
        $ts = (int)($row['timestamp']??0);
        $d = abs($ts - $target);
        if ($d < $delta) {
            $delta = $d;
            $best = $row;
        }
    }
    return $delta<=2700&&is_array($best) ? $best : null;
}
function meteonexa_intelq_brier(float $probability, float $outcome) : float {
    $p = meteonexa_intel_clamp($probability, 0, 1);
    $o = $outcome>=.5 ? 1.0 : 0.0;
    return($p - $o)**2;
}
function meteonexa_intelq_skill_summary(PDO $pdo, string $deviceId, string $locationKey) : array {
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false, 'verifiedSamples'=>0, 'byMetricHorizon'=>[], 'brier'=>null];
    try {
        $sql = "SELECT model_name,metric,horizon_hours,COUNT(*) samples,AVG(error_value) mae,AVG(brier_score) brier
              FROM model_skill_samples WHERE device_id=:device AND location_key=:location AND verified_at<>''
              GROUP BY model_name,metric,horizon_hours ORDER BY metric,horizon_hours,model_name";
        $st = $pdo->prepare($sql);
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey]);
        $rows = $st->fetchAll();
        $groups =[];
        $total = 0;
        $brierSum = 0.0;
        $brierN = 0;
        foreach ($rows as $row) {
            $metric = (string)$row['metric'];
            $h = (string)(int)$row['horizon_hours'];
            $samples = (int)$row['samples'];
            $total+=$samples;
            $level = function_exists('meteonexa_reliability_sample_level') ? meteonexa_reliability_sample_level($samples) :['id'=>($samples>=100 ? 'consolidated' :($samples>=30 ? 'building' :($samples>=10 ? 'preliminary' : 'initial'))), 'publishable'=>$samples>=30];
            $entry =['model'=>(string)$row['model_name'], 'samples'=>$samples, 'calibrationLevel'=>$level['id'], 'eligibleForRanking'=>!empty($level['publishable'])];
            if (in_array($metric,['rain', 'storm', 'snow'], true)) {
                $entry['brier'] = round((float)$row['brier'], 4);
                $brierSum+=(float)$row['brier'] * $samples;
                $brierN+=$samples;
            } else $entry['mae'] = round((float)$row['mae'], 2);
            $groups[$metric][$h][] = $entry;
        }
        $best =[];
        foreach ($groups as $metric=>$horizons) foreach ($horizons as $h=>$entries) {
            usort($entries, static function($a, $b)use($metric) {
                $field = in_array($metric,['rain', 'storm', 'snow'], true) ? 'brier' : 'mae'; return($a[$field]??INF)<=>($b[$field]??INF);
            });
            $groups[$metric][$h] = $entries;
            $eligible = array_values(array_filter($entries, static fn($e)=>!empty($e['eligibleForRanking'])));
            $best[$metric][$h] = $eligible[0]['model']??null;
        }
        return['available'=>$rows!==[], 'verifiedSamples'=>$total, 'byMetricHorizon'=>$groups, 'best'=>$best, 'brier'=>$brierN ? round($brierSum / $brierN, 4) : null, 'leadHours'=>[1, 3, 6, 24, 48, 72]];
    } catch (Throwable $ignored) {
        return['available'=>false, 'verifiedSamples'=>0, 'byMetricHorizon'=>[], 'brier'=>null];
    }
}
function meteonexa_intelq_snapshot_from_consensus(array $consensus, array $analysis) : array {
    $primary = (array)($consensus['primary']??[]);
    $metrics = (array)($analysis['metrics']??[]);
    return['type'=>$primary['type']??null, 'startsAt'=>$primary['startsAt']??null, 'endsAt'=>$primary['endsAt']??null, 'agreementPct'=>(int)($primary['agreementPct']??0), 'votes'=>(int)($primary['votes']??0), 'available'=>(int)($primary['available']??0), 'rainProbability'=>(int)($metrics['rainProbabilityWindow']??$metrics['rainProbability']??0), 'severity'=>(string)($analysis['severity']??'green'), 'confidence'=>(int)($analysis['confidence']??0),];
}
function meteonexa_intelq_compare_snapshots( ? array $previous, array $current) : array {
    if (!$previous)return['available'=>false, 'changed'=>false, 'stabilityPct'=>null];
    $prevStart = meteonexa_intel_time_utc($previous['startsAt']??'');
    $curStart = meteonexa_intel_time_utc($current['startsAt']??'');
    $shift =($prevStart!==null&&$curStart!==null) ? (int)round(($curStart - $prevStart) / 60) : null;
    $rainDelta = (int)($current['rainProbability']??0) - (int)($previous['rainProbability']??0);
    $agreementDelta = (int)($current['agreementPct']??0) - (int)($previous['agreementPct']??0);
    $typeChanged = (string)($previous['type']??'')!==(string)($current['type']??'');
    $changed = $typeChanged||($shift!==null&&abs($shift)>=60)||abs($rainDelta)>=15||abs($agreementDelta)>=15;
    $penalty =($typeChanged ? 35 : 0) + min(30, abs((int)($shift??0)) / 6) + min(20, abs($rainDelta)) * .5 + min(15, abs($agreementDelta)) * .4;
    return['available'=>true, 'changed'=>$changed, 'typeChanged'=>$typeChanged, 'timeShiftMinutes'=>$shift, 'rainProbabilityDelta'=>$rainDelta, 'agreementDelta'=>$agreementDelta, 'previous'=>$previous, 'current'=>$current, 'stabilityPct'=>(int)round(meteonexa_intel_clamp(100 - $penalty, 0, 100))];
}
function meteonexa_intelq_persist_run_snapshot(PDO $pdo, string $deviceId, string $locationKey, array $snapshot) : array {
    if (!meteonexa_db_table_exists($pdo, 'forecast_run_snapshots'))return['available'=>false, 'changed'=>false];
    try {
        $st = $pdo->prepare('SELECT snapshot_json,created_at FROM forecast_run_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC LIMIT 1');
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey]);
        $row = $st->fetch();
        $previous = null;
        if (is_array($row))$previous = json_decode((string)$row['snapshot_json'], true) ? : null;
        $comparison = meteonexa_intelq_compare_snapshots(is_array($previous) ? $previous : null, $snapshot);
        $lastTs = is_array($row) ?(strtotime((string)$row['created_at']) ? : 0) : 0;
        if ($lastTs < time() - 600) {
            $ins = $pdo->prepare('INSERT INTO forecast_run_snapshots(device_id,location_key,snapshot_json,created_at) VALUES(:device,:location,:json,:created)');
            $ins->execute([':device'=>$deviceId, ':location'=>$locationKey, ':json'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':created'=>gmdate('c')]);
            // Keep at most 48 recent snapshots without a self-referencing DELETE, which is not portable to MySQL/MariaDB.
            $ids = $pdo->prepare('SELECT id FROM forecast_run_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC');
            $ids->execute([':device'=>$deviceId, ':location'=>$locationKey]);
            $allIds = array_map('intval', array_column($ids->fetchAll(), 'id'));
            $drop = array_slice($allIds, 48);
            if ($drop) {
                $placeholders = implode(',', array_fill(0, count($drop), '?'));
                $pdo->prepare('DELETE FROM forecast_run_snapshots WHERE id IN (' . $placeholders . ')')->execute($drop);
            }
        }
        return $comparison;
    } catch (Throwable $ignored) {
        return['available'=>false, 'changed'=>false];
    }
}
function meteonexa_intelq_source_freshness(array $models, array $motion, array $lightning, array $satellite, array $official, array $hyperlocal) : array {
    $sources =[];
    foreach ($models as $model) {
        $sources[] =['id'=>(string)$model['id'], 'label'=>(string)$model['label'], 'available'=>(bool)$model['available'], 'retrievedAt'=>$model['retrievedAt']??null, 'ageMinutes'=>$model['ageMinutes']??null, 'fetchAgeMinutes'=>$model['fetchAgeMinutes']??null, 'cacheAgeMinutes'=>$model['cacheAgeMinutes']??null, 'modelRunEstimatedAt'=>$model['modelRunEstimatedAt']??null, 'modelRunAgeMinutes'=>$model['modelRunAgeMinutes']??null, 'runTimeEstimated'=>!empty($model['runTimeEstimated']), 'cacheHit'=>!empty($model['cacheHit']), 'providerLatencyMs'=>$model['providerLatencyMs']??null, 'cadenceMinutes'=>$model['cadenceMinutes']??null, 'freshnessBasis'=>'model-cycle+retrieval', 'kind'=>'model'];
    }
    $sources[] =['id'=>'radar', 'label'=>'Radar', 'available'=>(bool)($motion['available']??false), 'retrievedAt'=>$motion['latestFrameAt']??$motion['observedAt']??null, 'ageMinutes'=>$motion['ageMinutes']??null, 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    $lightningAt = $lightning['observedAt']??null;
    $lightningTs = meteonexa_intel_time_utc($lightningAt??'');
    $sources[] =['id'=>'lightning', 'label'=>'Lightning', 'available'=>(bool)($lightning['available']??false), 'retrievedAt'=>$lightningAt, 'ageMinutes'=>$lightningTs===null ? null : max(0, (int)round((time() - $lightningTs) / 60)), 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    $satAt = $satellite['observedAt']??null;
    $satTs = meteonexa_intel_time_utc($satAt??'');
    $sources[] =['id'=>'satellite', 'label'=>'Satellite', 'available'=>(bool)($satellite['available']??false), 'retrievedAt'=>$satAt, 'ageMinutes'=>$satTs===null ? null : max(0, (int)round((time() - $satTs) / 60)), 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    $officialAt = $official['relevant'][0]['updatedAt']??$official['generatedAt']??null;
    $officialTs = meteonexa_intel_time_utc($officialAt??'');
    $sources[] =['id'=>'official', 'label'=>'MeteoAlarm', 'available'=>(bool)($official['available']??true), 'retrievedAt'=>$officialAt, 'ageMinutes'=>$officialTs===null ? null : max(0, (int)round((time() - $officialTs) / 60)), 'mode'=>$official['mode']??'atom', 'freshnessBasis'=>isset($official['relevant'][0]['updatedAt']) ? 'warning-update' : 'retrieval', 'kind'=>'official'];
    $hyperAt = $hyperlocal['observation']['observedAt']??$hyperlocal['observation']['time']??null;
    if (is_numeric($hyperAt))$hyperAt = gmdate('c', (int)$hyperAt);
    $hyperTs = meteonexa_intel_time_utc($hyperAt??'');
    $sources[] =['id'=>'hyperlocal', 'label'=>'Hyperlocal', 'available'=>(bool)($hyperlocal['available']??false), 'retrievedAt'=>$hyperAt, 'ageMinutes'=>$hyperTs===null ? null : max(0, (int)round((time() - $hyperTs) / 60)), 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    return $sources;
}
function meteonexa_intelq_explainability(array $analysis, array $consensus, array $motion, array $lightning, array $satellite, array $official, array $hyperlocal, array $skill) : array {
    $parts =[];
    $score = 0;
    $agreement = (int)($consensus['primary']['agreementPct']??0);
    if ($agreement > 0) {
        $c = (int)round($agreement * .45);
        $parts[] =['source'=>'models', 'contribution'=>$c, 'status'=>$agreement>=60 ? 'support' : 'conflict', 'detail'=>['agreementPct'=>$agreement, 'votes'=>$consensus['primary']['votes']??0, 'available'=>$consensus['primary']['available']??0]];
        $score+=$c;
    }
    if (!empty($motion['available'])) {
        $c = (int)round(min(20, (float)($motion['confidence']??0) * .20));
        $parts[] =['source'=>'radar', 'contribution'=>$c, 'status'=>'support'];
        $score+=$c;
    }
    if (!empty($lightning['available'])&&(int)($lightning['recent30m']??0) > 0) {
        $c = min(15, 5 + (int)($lightning['recent30m']??0));
        $parts[] =['source'=>'lightning', 'contribution'=>$c, 'status'=>'support'];
        $score+=$c;
    }
    if (!empty($official['relevant'])) {
        $parts[] =['source'=>'official', 'contribution'=>15, 'status'=>'support'];
        $score+=15;
    }
    if (!empty($satellite['available'])) {
        $c = (int)round(min(5, (float)($satellite['support']??0) * 5));
        $parts[] =['source'=>'satellite', 'contribution'=>$c, 'status'=>'context'];
        $score+=$c;
    }
    if (!empty($hyperlocal['available'])) {
        $parts[] =['source'=>'hyperlocal', 'contribution'=>8, 'status'=>'context'];
        $score+=8;
    }
    if (!empty($skill['available'])) {
        $b = $skill['brier'];
        $parts[] =['source'=>'historicalSkill', 'contribution'=>0, 'status'=>'calibration', 'detail'=>['brier'=>$b, 'samples'=>$skill['verifiedSamples']??0]];
    }
    return['method'=>'deterministic-weighted-evidence', 'parts'=>$parts, 'evidenceScore'=>(int)round(meteonexa_intel_clamp($score, 0, 100)), 'displayConfidence'=>(int)($analysis['confidence']??0)];
}
function meteonexa_intelq_point_in_ring(float $lon, float $lat, array $ring) : bool {
    $inside = false;
    $n = count($ring);
    if ($n < 3)return false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float)($ring[$i][0]??0);
        $yi = (float)($ring[$i][1]??0);
        $xj = (float)($ring[$j][0]??0);
        $yj = (float)($ring[$j][1]??0);
        $intersect =(($yi > $lat)!==($yj > $lat))&&($lon <($xj - $xi) *($lat - $yi) /(($yj - $yi) ? : 1e-12) + $xi);
        if ($intersect)$inside = !$inside;
    }
    return $inside;
}
function meteonexa_intelq_geometry_contains(array $geometry, float $lat, float $lon) : bool {
    $type = (string)($geometry['type']??'');
    $coords = (array)($geometry['coordinates']??[]);
    if ($type==='Polygon')return isset($coords[0])&&meteonexa_intelq_point_in_ring($lon, $lat, (array)$coords[0]);
    if ($type==='MultiPolygon') foreach ($coords as $polygon) if (isset($polygon[0])&&meteonexa_intelq_point_in_ring($lon, $lat, (array)$polygon[0]))return true;
    return false;
}
function meteonexa_intelq_meteoalarm_edr(array $config, float $lat, float $lon, string $locale = 'en') : ? array {
    $token = trim((string)($config['official']['meteoalarm_edr_token']??''));
    if ($token==='')return null;
    $country = strtoupper(trim((string)($config['official']['meteoalarm_country']??'IT')));
    if (!preg_match('/^[A-Z]{2}$/', $country))$country = 'IT';
    $language = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) ? $locale : 'en';
    try {
        // MeteoAlarm defines the protected EDR location query by country code (e.g. IT).
        // Fetch the country warning FeatureCollection once per cache window, then do the
        // final point-in-polygon test locally. This avoids the old two-request discovery
        // sequence and preserves API quota on shared hosting.
        $raw = meteonexa_intel_provider_cache('meteoalarm-edr-' . $country . '-' . $language, 0.0, 0.0, 300, static function()use($country, $language, $token) : array {
            $url = 'https://api.meteoalarm.org/edr/v1/collections/warnings/locations/' . rawurlencode($country) . '?' . http_build_query(['f'=>'geojson', 'language'=>$language, 'active'=>gmdate('c') . '/..'], '', '&', PHP_QUERY_RFC3986); return meteonexa_http_json($url,['timeout'=>18, 'headers'=>['Authorization: Bearer ' . $token, 'Accept: application/geo+json, application/json'], 'max_bytes'=>4000000]);
        }, 21600);
        $cacheMeta = is_array($raw['_meteonexaCache']??null) ? (array)$raw['_meteonexaCache'] :[];
        $staleProvider = !empty($cacheMeta['stale']);
        $cacheAge = is_numeric($cacheMeta['ageSeconds']??null) ? max(0, (int)$cacheMeta['ageSeconds']) : 0;
        $relevant =[];
        foreach ((array)($raw['features']??[]) as $feature) {
            if (!is_array($feature))continue;
            $geometry = (array)($feature['geometry']??[]);
            if ($geometry&&!meteonexa_intelq_geometry_contains($geometry, $lat, $lon))continue;
            // Features without a geometry cannot be safely assigned to the requested point.
            if (!$geometry)continue;
            $p = (array)($feature['properties']??[]);
            $level = strtolower((string)($p['awareness_level']??$p['severity']??''));
            $severity =(str_contains($level, 'green')||preg_match('/(^|[^0-9])1(?:[^0-9]|$)/', $level)===1) ? 'green' :(str_contains($level, 'red')||str_contains($level, 'extreme') ? 'red' :(str_contains($level, 'orange')||str_contains($level, 'severe') ? 'orange' : 'yellow'));
            $relevant[] =['id'=>(string)($feature['id']??$p['identifier']??hash('sha256', json_encode($p))), 'title'=>(string)($p['headline']??$p['event']??$p['awareness_type']??'MeteoAlarm'), 'summary'=>(string)($p['description']??$p['instruction']??''), 'severity'=>$severity, 'updatedAt'=>$p['sent']??$p['updated']??null, 'startsAt'=>$p['onset']??$p['effective']??$p['valid_from']??null, 'endsAt'=>$p['expires']??$p['valid_to']??null, 'source'=>'MeteoAlarm EDR', 'geospatialMatch'=>true, 'locationId'=>$country];
        }
        return['available'=>true, 'relevant'=>$relevant, 'mode'=>'edr-geospatial', 'geospatial'=>true, 'locationId'=>$country, 'generatedAt'=>gmdate('c'), 'providerFresh'=>!$staleProvider, 'staleProviderCache'=>$staleProvider, 'cacheAgeSeconds'=>$cacheAge];
    } catch (Throwable $ignored) {
        return null;
    }
}
function meteonexa_intelq_radar_components(array $grid, float $threshold = .12) : array {
    $matrix = (array)($grid['grid']??[]);
    $size = (int)($grid['size']??count($matrix));
    if ($size<=0)return[];
    $seen =[];
    $components =[];
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        $key = "$x:$y";
        if (isset($seen[$key])||((float)($matrix[$y][$x]??0)) < $threshold)continue;
        $queue =[[$x, $y]];
        $seen[$key] = true;
        $mass = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $pixels = 0;
        $peak = 0.0;
        while ($queue) {
            [$px, $py] = array_pop($queue);
            $v = (float)($matrix[$py][$px]??0);
            $mass+=$v;
            $cx+=$px * $v;
            $cy+=$py * $v;
            $pixels++;
            $peak = max($peak, $v);
            foreach ([[1, 0],[ - 1, 0],[0, 1],[0, - 1]] as[$dx, $dy]) {
                $nx = $px + $dx;
                $ny = $py + $dy;
                if ($nx < 0||$ny < 0||$nx>=$size||$ny>=$size)continue;
                $nk = "$nx:$ny";
                if (isset($seen[$nk])||((float)($matrix[$ny][$nx]??0)) < $threshold)continue;
                $seen[$nk] = true;
                $queue[] =[$nx, $ny];
            }
        }
        if ($pixels>=2&&$mass > .25)$components[] =['mass'=>$mass, 'pixels'=>$pixels, 'cx'=>$mass > 0 ? $cx / $mass : $x, 'cy'=>$mass > 0 ? $cy / $mass : $y, 'peak'=>$peak];
    }
    usort($components, static fn($a, $b)=>$b['mass']<=>$a['mass']);
    return $components;
}
function meteonexa_intelq_component_distance(array $a, array $b) : float {
    return sqrt(((float)$a['cx'] - (float)$b['cx'])**2 +((float)$a['cy'] - (float)$b['cy'])**2);
}
function meteonexa_intelq_track_cell_sequence(array $frames) : array {
    if (count($frames) < 2)return[];
    $latestIndex = count($frames) - 1;
    $latest = $frames[$latestIndex];
    $size = (int)($latest['grid']['size']??0);
    $center = max(0,($size - 1) / 2);
    $candidates = (array)($latest['components']??[]);
    if (!$candidates)return[];
    usort($candidates, static function($a, $b)use($center) {
        $sa = meteonexa_intelq_component_distance($a,['cx'=>$center, 'cy'=>$center]) - min(6, (float)($a['mass']??0)) * .25; $sb = meteonexa_intelq_component_distance($b,['cx'=>$center, 'cy'=>$center]) - min(6, (float)($b['mass']??0)) * .25; return $sa<=>$sb;
    });
    $tracked =[['time'=>$latest['time'], 'grid'=>$latest['grid'], 'cell'=>$candidates[0]]];
    $target = $candidates[0];
    $jumps =[];
    for ($i = $latestIndex - 1; $i>=0; $i--) {
        $best = null;
        $bestDistance = INF;
        foreach ((array)($frames[$i]['components']??[]) as $component) {
            $d = meteonexa_intelq_component_distance($target, $component);
            if ($d < $bestDistance) {
                $best = $component;
                $bestDistance = $d;
            }
        }
        $threshold = max(4.5, min(12.0, $size * .24));
        if (!$best||$bestDistance > $threshold)continue;
        array_unshift($tracked,['time'=>$frames[$i]['time'], 'grid'=>$frames[$i]['grid'], 'cell'=>$best]);
        $jumps[] = $bestDistance;
        $target = $best;
    }
    if (count($tracked) < 2)return[];
    $tracked[count($tracked) - 1]['associationMeanJump'] = $jumps ? array_sum($jumps) / count($jumps) : 0.0;
    return $tracked;
}
function meteonexa_intelq_cell_tracking(PDO $pdo, string $deviceId, float $lat, float $lon, array $lightning =[]) : array {
    if (!function_exists('imagecreatefromstring'))return['available'=>false, 'reason'=>'gd'];
    try {
        $stmt = $pdo->prepare('SELECT id,latitude,longitude FROM radar_archive_locations WHERE device_id=:device AND active=1 ORDER BY updated_at DESC');
        $stmt->execute([':device'=>$deviceId]);
        $best = null;
        $distance = INF;
        foreach ($stmt->fetchAll() as $row) {
            $d = haversine_km($lat, $lon, (float)$row['latitude'], (float)$row['longitude']);
            if ($d < $distance) {
                $distance = $d;
                $best = $row;
            }
        }
        if (!$best||$distance > 80)return['available'=>false, 'reason'=>'no_archive'];
        $st = $pdo->prepare('SELECT frame_time,image_path FROM radar_archive_frames WHERE location_id=:id ORDER BY frame_time DESC LIMIT 6');
        $st->execute([':id'=>$best['id']]);
        $frames =[];
        foreach (array_reverse($st->fetchAll()) as $row) {
            $grid = meteonexa_radar_signal_grid((string)$row['image_path']);
            if (!$grid)continue;
            $components = meteonexa_intelq_radar_components($grid);
            if (!$components)continue;
            $frames[] =['time'=>(int)$row['frame_time'], 'grid'=>$grid, 'components'=>$components];
        }
        $tracked = meteonexa_intelq_track_cell_sequence($frames);
        if (count($tracked) < 2)return['available'=>false, 'reason'=>'insufficient_cells'];
        $first = $tracked[0];
        $latest = $tracked[count($tracked) - 1];
        $dt = max(60, $latest['time'] - $first['time']);
        $dx = $latest['cell']['cx'] - $first['cell']['cx'];
        $dy = $latest['cell']['cy'] - $first['cell']['cy'];
        $vx = $dx /($dt / 60);
        $vy = $dy /($dt / 60);
        $growth = 100 *($latest['cell']['mass'] - $first['cell']['mass']) / max(.01, $first['cell']['mass']);
        $stage = $growth > 20 ? 'growing' :($growth < - 20 ? 'decaying' : 'stable');
        $center =((int)$latest['grid']['size'] - 1) / 2;
        $distCells = sqrt(($center - $latest['cell']['cx'])**2 +($center - $latest['cell']['cy'])**2);
        $speed2 = $vx * $vx + $vy * $vy;
        $eta = null;
        $toward = false;
        if ($speed2 > .0001) {
            $tx = $center - $latest['cell']['cx'];
            $ty = $center - $latest['cell']['cy'];
            $dot = $tx * $vx + $ty * $vy;
            $toward = $dot > 0;
            if ($toward) {
                $eta = (int)round($dot / $speed2);
                if ($eta < 0||$eta > 180)$eta = null;
            }
        }
        $meanJump = (float)($latest['associationMeanJump']??0);
        $associationConfidence = (int)round(meteonexa_intel_clamp(100 - $meanJump * 8, 20, 100));
        $trackConfidence = (int)round(meteonexa_intel_clamp(35 + count($tracked) * 12 + $associationConfidence * .25, 25, 95));
        $impact = 20 +($toward ? 27 : 0) + max(0, 27 - $distCells * 1.6) + min(15, max( - 10, $growth / 4));
        $impact*=.7 + .3 *($trackConfidence / 100);
        if (!empty($lightning['available'])&&(int)($lightning['recent30m']??0) > 0)$impact+=10;
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $dir = $dirs[((int)round($angle / 45)) % 8];
        return['available'=>true, 'method'=>'cell-segmentation-associated-multi-frame', 'direction'=>$dir, 'growthPct'=>round($growth, 1), 'stage'=>$stage, 'impactProbability'=>(int)round(meteonexa_intel_clamp($impact, 5, 95)), 'etaMinutes'=>$eta, 'towardLocation'=>$toward, 'cellDistanceGrid'=>round($distCells, 1), 'speedCellsMin'=>round(sqrt($speed2), 4), 'mass'=>round((float)$latest['cell']['mass'], 2), 'peak'=>round((float)$latest['cell']['peak'], 3), 'frameCount'=>count($tracked), 'archiveFrameCount'=>count($frames), 'associationMeanJump'=>round($meanJump, 2), 'trackConfidence'=>$trackConfidence, 'archiveDistanceKm'=>round($distance, 1), 'lightningFused'=>!empty($lightning['available'])&&(int)($lightning['recent30m']??0) > 0, 'latestFrameAt'=>gmdate('c', $latest['time']), 'ageMinutes'=>max(0, (int)round((time() - $latest['time']) / 60))];
    } catch (Throwable $ignored) {
        return['available'=>false, 'reason'=>'analysis_failed'];
    }
}
function meteonexa_intelq_previous_runs(float $lat, float $lon, bool $demo = false) : array {
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    try {
        return meteonexa_intel_provider_cache('previous-runs', $lat, $lon, 1800, static function()use($lat, $lon) : array {
            $params = http_build_query(['latitude'=>$lat, 'longitude'=>$lon, 'hourly'=>'temperature_2m,temperature_2m_previous_day1,precipitation,precipitation_previous_day1,weather_code,weather_code_previous_day1,wind_gusts_10m,wind_gusts_10m_previous_day1', 'forecast_days'=>3, 'past_days'=>1, 'timezone'=>'GMT'], '', '&', PHP_QUERY_RFC3986); $raw = meteonexa_http_json('https://previous-runs-api.open-meteo.com/v1/forecast?' . $params,['timeout'=>20, 'max_bytes'=>1800000]); $h = (array)($raw['hourly']??[]); $times = (array)($h['time']??[]); $rows =[]; foreach ($times as $i=>$t) {
                $ts = meteonexa_intel_time_utc($t); if ($ts===null||$ts < time() - 3600)continue; $rows[] =['time'=>gmdate('c', $ts), 'temperatureNow'=>is_numeric($h['temperature_2m'][$i]??null) ? (float)$h['temperature_2m'][$i] : null, 'temperaturePrevious'=>is_numeric($h['temperature_2m_previous_day1'][$i]??null) ? (float)$h['temperature_2m_previous_day1'][$i] : null, 'rainNow'=>is_numeric($h['precipitation'][$i]??null) ? (float)$h['precipitation'][$i] : null, 'rainPrevious'=>is_numeric($h['precipitation_previous_day1'][$i]??null) ? (float)$h['precipitation_previous_day1'][$i] : null, 'codeNow'=>is_numeric($h['weather_code'][$i]??null) ? (int)$h['weather_code'][$i] : null, 'codePrevious'=>is_numeric($h['weather_code_previous_day1'][$i]??null) ? (int)$h['weather_code_previous_day1'][$i] : null, 'windNow'=>is_numeric($h['wind_gusts_10m'][$i]??null) ? (float)$h['wind_gusts_10m'][$i] : null, 'windPrevious'=>is_numeric($h['wind_gusts_10m_previous_day1'][$i]??null) ? (float)$h['wind_gusts_10m_previous_day1'][$i] : null]; if (count($rows)>=72)break;
            }
            $future = array_slice($rows, 0, 24); $summary =['available'=>false]; if ($future) {
                $rainNow = array_map(static fn($r)=>(float)($r['rainNow']??0), $future); $rainPrev = array_map(static fn($r)=>(float)($r['rainPrevious']??0), $future); $tempNow = array_values(array_filter(array_column($future, 'temperatureNow'), 'is_numeric')); $tempPrev = array_values(array_filter(array_column($future, 'temperaturePrevious'), 'is_numeric')); $windNow = array_values(array_filter(array_column($future, 'windNow'), 'is_numeric')); $windPrev = array_values(array_filter(array_column($future, 'windPrevious'), 'is_numeric')); $firstNow = null; $firstPrev = null; $stormsNow = 0; $stormsPrev = 0; foreach ($future as $r) {
                    if ($firstNow===null&&((float)($r['rainNow']??0)>=.2||in_array((int)($r['codeNow']??0),[51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99], true)))$firstNow = $r['time']; if ($firstPrev===null&&((float)($r['rainPrevious']??0)>=.2||in_array((int)($r['codePrevious']??0),[51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99], true)))$firstPrev = $r['time']; if (in_array((int)($r['codeNow']??0),[95, 96, 99], true))$stormsNow++; if (in_array((int)($r['codePrevious']??0),[95, 96, 99], true))$stormsPrev++;
                }
                $nowTs = meteonexa_intel_time_utc($firstNow??''); $prevTs = meteonexa_intel_time_utc($firstPrev??''); $summary =['available'=>true, 'windowHours'=>24, 'firstRainNow'=>$firstNow, 'firstRainPrevious'=>$firstPrev, 'timeShiftMinutes'=>($nowTs!==null&&$prevTs!==null) ? (int)round(($nowTs - $prevTs) / 60) : null, 'rainTotalNow'=>round(array_sum($rainNow), 1), 'rainTotalPrevious'=>round(array_sum($rainPrev), 1), 'rainTotalDelta'=>round(array_sum($rainNow) - array_sum($rainPrev), 1), 'stormHoursNow'=>$stormsNow, 'stormHoursPrevious'=>$stormsPrev, 'stormHoursDelta'=>$stormsNow - $stormsPrev, 'temperatureMaxNow'=>$tempNow ? round(max($tempNow), 1) : null, 'temperatureMaxPrevious'=>$tempPrev ? round(max($tempPrev), 1) : null, 'temperatureMaxDelta'=>($tempNow&&$tempPrev) ? round(max($tempNow) - max($tempPrev), 1) : null, 'windGustMaxNow'=>$windNow ? round(max($windNow), 1) : null, 'windGustMaxPrevious'=>$windPrev ? round(max($windPrev), 1) : null, 'windGustMaxDelta'=>($windNow&&$windPrev) ? round(max($windNow) - max($windPrev), 1) : null,]; $summary['changed'] =($summary['timeShiftMinutes']!==null&&abs((int)$summary['timeShiftMinutes'])>=60)||abs((float)$summary['rainTotalDelta'])>=2||abs((int)$summary['stormHoursDelta'])>=1||abs((float)($summary['temperatureMaxDelta']??0))>=2||abs((float)($summary['windGustMaxDelta']??0))>=8;
            }
            return['available'=>$rows!==[], 'source'=>'Open-Meteo Previous Runs', 'rows'=>$rows, 'summary'=>$summary, 'retrievedAt'=>gmdate('c')];
        });
    } catch (Throwable $ignored) {
        return['available'=>false, 'source'=>'Open-Meteo Previous Runs', 'rows'=>[], 'summary'=>['available'=>false]];
    }
}
function meteonexa_intelq_decision_windows(array $models, array $customProfiles =[]) : array {
    $consensus = meteonexa_intelq_consensus($models);
    $hourly = (array)($consensus['hourly']??[]);
    // These scores express weather suitability only. They deliberately use observable/modelled
    // weather variables available to all configured NWP/AI forecast families and do not claim route, traffic,
    // avalanche, wave or piste-condition knowledge.
    $profiles =['car'=>['rain'=>.28, 'storm'=>.65, 'wind'=>.12, 'bonus'=>8, 'tempMin'=> - 10, 'tempMax'=>40, 'tempWeight'=>1.0], 'motorcycle'=>['rain'=>.70, 'storm'=>1.05, 'wind'=>.52, 'bonus'=>0, 'tempMin'=>5, 'tempMax'=>34, 'tempWeight'=>3.0], 'bike'=>['rain'=>.66, 'storm'=>1.00, 'wind'=>.50, 'bonus'=>0, 'tempMin'=>4, 'tempMax'=>32, 'tempWeight'=>3.2], 'run'=>['rain'=>.50, 'storm'=>1.00, 'wind'=>.28, 'bonus'=>0, 'tempMin'=>2, 'tempMax'=>30, 'tempWeight'=>4.0], 'trekking'=>['rain'=>.48, 'storm'=>1.05, 'wind'=>.34, 'bonus'=>0, 'tempMin'=>0, 'tempMax'=>30, 'tempWeight'=>3.5], 'sea'=>['rain'=>.30, 'storm'=>1.20, 'wind'=>.72, 'bonus'=>0, 'tempMin'=>18, 'tempMax'=>36, 'tempWeight'=>7.0], 'outdoor_work'=>['rain'=>.52, 'storm'=>1.05, 'wind'=>.45, 'bonus'=>0, 'tempMin'=> - 5, 'tempMax'=>33, 'tempWeight'=>4.5], 'event'=>['rain'=>.62, 'storm'=>1.05, 'wind'=>.38, 'bonus'=>0, 'tempMin'=>5, 'tempMax'=>32, 'tempWeight'=>3.5], 'laundry'=>['rain'=>.92, 'storm'=>.60, 'wind'=>.12, 'bonus'=>0, 'tempMin'=>2, 'tempMax'=>42, 'tempWeight'=>1.0], 'kids'=>['rain'=>.58, 'storm'=>1.10, 'wind'=>.30, 'bonus'=>0, 'tempMin'=>5, 'tempMax'=>30, 'tempWeight'=>6.0], 'pets'=>['rain'=>.48, 'storm'=>1.00, 'wind'=>.30, 'bonus'=>0, 'tempMin'=>0, 'tempMax'=>31, 'tempWeight'=>4.0], 'commute'=>['rain'=>.34, 'storm'=>.78, 'wind'=>.18, 'bonus'=>5, 'tempMin'=> - 10, 'tempMax'=>40, 'tempWeight'=>1.3], 'worksite'=>['rain'=>.58, 'storm'=>1.10, 'wind'=>.48, 'bonus'=>0, 'tempMin'=> - 5, 'tempMax'=>34, 'tempWeight'=>4.8], 'ski'=>['rain'=>.36, 'storm'=>.90, 'wind'=>.58, 'bonus'=>0, 'tempMin'=> - 20, 'tempMax'=>5, 'tempWeight'=>6.0, 'snowRequired'=>true],];
    $out =[];
    foreach ($profiles as $activity=>$profile) {
        $best = null;
        for ($i = 0; $i + 2 < count($hourly)&&$i < 24; $i++) {
            $slice = array_slice($hourly, $i, 3);
            $penalty = 0.0;
            $reasons =['rain'=>0, 'storm'=>0, 'wind'=>0, 'temperature'=>null, 'snow'=>0];
            $temps =[];
            foreach ($slice as $r) {
                $available = max(1, (int)$r['available']);
                $rainPct = 100 * (int)$r['rainVotes'] / $available;
                $stormPct = 100 * (int)$r['stormVotes'] / $available;
                $snowPct = 100 * (int)($r['snowVotes']??0) / $available;
                $gust = (float)($r['windGustMax']??0);
                if (is_numeric($r['temperatureMean']??null))$temps[] = (float)$r['temperatureMean'];
                $reasons['rain'] = max($reasons['rain'], (int)round($rainPct));
                $reasons['storm'] = max($reasons['storm'], (int)round($stormPct));
                $reasons['wind'] = max($reasons['wind'], (int)round($gust));
                $reasons['snow'] = max($reasons['snow'], (int)round($snowPct));
                $customKey = $activity==='outdoor_work' ? 'outdoor' : $activity;
                $custom = is_array($customProfiles[$customKey]??null) ? $customProfiles[$customKey] : null;
                if ($custom) {
                    $penalty+=max(0, $rainPct - (float)($custom['rainMax']??35)) * 1.45 + $stormPct * (float)$profile['storm'] + max(0, $gust - (float)($custom['gustMax']??45)) * 2.2;
                } else {
                    $penalty+=$rainPct * (float)$profile['rain'] + $stormPct * (float)$profile['storm'] + max(0, $gust - 20) * (float)$profile['wind'];
                }
                if (is_numeric($r['temperatureMean']??null)) {
                    $t = (float)$r['temperatureMean'];
                    $delta = 0.0;
                    $minT = $custom ? (float)($custom['tempMin']??$profile['tempMin']) : (float)$profile['tempMin'];
                    $maxT = $custom ? (float)($custom['tempMax']??$profile['tempMax']) : (float)$profile['tempMax'];
                    if ($t < $minT)$delta = $minT - $t;
                    elseif ($t > $maxT)$delta = $t - $maxT;
                    $penalty+=$delta * (float)$profile['tempWeight'];
                }
            }
            $tempMean = $temps ? array_sum($temps) / count($temps) : null;
            $reasons['temperature'] = $tempMean===null ? null : round($tempMean, 1);
            // A dry warm day must never become a high ski score just because rain/storm/wind are absent.
            if (!empty($profile['snowRequired'])&&$reasons['snow'] < 20)$penalty+=240;
            $score = (int)round(meteonexa_intel_clamp(100 - $penalty / 3 + (float)$profile['bonus'], 0, 100));
            if ($best===null||$score > $best['score']) {
                $start = meteonexa_intel_time_utc($slice[0]['time']);
                $end = meteonexa_intel_time_utc($slice[2]['time']);
                $best =['activity'=>$activity, 'score'=>$score, 'startsAt'=>$start ? gmdate('c', $start) : $slice[0]['time'], 'endsAt'=>$end ? gmdate('c', $end + 3600) : $slice[2]['time'], 'reasons'=>$reasons, 'basis'=>'weather-only'];
            }
        }
        if ($best)$out[] = $best;
    }
    usort($out, static fn($a, $b)=>$b['score']<=>$a['score']);
    return $out;
}
function meteonexa_intelq_nearest_horizon( ? string $startsAt) : int {
    $ts = meteonexa_intel_time_utc($startsAt??'');
    $lead = $ts===null ? 24 : max(1, (int)round(($ts - time()) / 3600));
    $choices =[1, 3, 6, 24, 48, 72];
    $best = 24;
    $distance = INF;
    foreach ($choices as $h) {
        $d = abs($lead - $h);
        if ($d < $distance) {
            $distance = $d;
            $best = $h;
        }
    }
    return $best;
}
function meteonexa_intelq_calibrate_probability(PDO $pdo, string $deviceId, string $locationKey, string $metric, float $rawProbability, ? int $horizonHours = null) : array {
    $raw = meteonexa_intel_clamp($rawProbability, 0, 1);
    $horizon = $horizonHours===null ? 24 : (int)$horizonHours;
    if (!in_array($horizon,[1, 3, 6, 24, 48, 72], true))$horizon = 24;
    if (!in_array($metric,['rain', 'storm', 'snow'], true)||!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false, 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($raw * 100), 'samples'=>0, 'brier'=>null, 'horizonHours'=>$horizon];
    try {
        $low = max(0, $raw - .10);
        $high = min(1, $raw + .10);
        $st = $pdo->prepare("SELECT COUNT(*) samples,AVG(observed_value) event_rate,AVG(brier_score) brier FROM model_skill_samples WHERE device_id=:device AND location_key=:location AND model_name='consensus' AND metric=:metric AND horizon_hours=:horizon AND verified_at<>'' AND predicted_value BETWEEN :low AND :high");
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey, ':metric'=>$metric, ':horizon'=>$horizon, ':low'=>$low, ':high'=>$high]);
        $row = $st->fetch();
        $n = (int)($row['samples']??0);
        $level = function_exists('meteonexa_reliability_sample_level') ? meteonexa_reliability_sample_level($n) :['id'=>($n>=100 ? 'consolidated' :($n>=30 ? 'building' :($n>=10 ? 'preliminary' : 'initial'))), 'publishable'=>$n>=30];
        if ($n < 10)return['available'=>false, 'publishable'=>false, 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($raw * 100), 'samples'=>$n, 'brier'=>$n ? round((float)$row['brier'], 4) : null, 'horizonHours'=>$horizon, 'calibrationLevel'=>$level['id'], 'uncertainty95'=>null];
        $eventRate = (float)($row['event_rate']??0);
        $eventCount = $eventRate * $n;
        $cal =($eventCount + 1) /($n + 2);
        $ci = function_exists('meteonexa_wilson_interval') ? meteonexa_wilson_interval($eventRate, $n) : null;
        return['available'=>true, 'publishable'=>!empty($level['publishable']), 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($cal * 100), 'samples'=>$n, 'brier'=>round((float)$row['brier'], 4), 'bin'=>[round($low, 2), round($high, 2)], 'horizonHours'=>$horizon, 'calibrationLevel'=>$level['id'], 'uncertainty95'=>$ci];
    } catch (Throwable $ignored) {
        return['available'=>false, 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($raw * 100), 'samples'=>0, 'brier'=>null, 'horizonHours'=>$horizon];
    }
}
