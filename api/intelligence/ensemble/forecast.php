<?php
declare(strict_types=1);
function meteonexa_ensemble_fetch(float $lat, float $lon, int $forecastHours = 360): array
{
    
    
    
    $started = microtime(true);
    $budgetSeconds = 9.0;
    foreach (meteonexa_ensemble_definitions() as $id=>$definition) {
        $remaining = (int)floor($budgetSeconds - (microtime(true) - $started));
        if ($remaining < 3) break;
        $timeout = min(7, $remaining);
        try {
            $raw = meteonexa_intel_provider_cache('ensemble-' . $id . '-' . max(168, min(360, $forecastHours)), $lat, $lon, 900,
                static fn(): array => meteonexa_http_json(meteonexa_ensemble_url($lat, $lon, (string)$definition['modelId'], $forecastHours), [
                    'timeout'=>$timeout,
                    'max_bytes'=>5000000,
                    'headers'=>['Accept: application/json', 'User-Agent: MeteoNexa/20.1'],
                ]),
                21600
            );
            if (!is_array($raw) || empty($raw['hourly']['time'])) continue;
            $hourly = (array)$raw['hourly'];
            if (count(meteonexa_ensemble_series_keys($hourly, 'temperature_2m')) < 10 || count(meteonexa_ensemble_series_keys($hourly, 'wind_gusts_10m')) < 10) continue;
            $raw['_meteonexaEnsemble'] = ['id'=>$id] + $definition;
            return $raw;
        } catch (Throwable $error) {
            if (function_exists('meteonexa_observability_event')) {
                meteonexa_observability_event('provider', 'ensemble-' . $id, 'error', ['class'=>get_class($error)]);
            }
        }
    }
    return [];
}

function meteonexa_ensemble_rows(array $raw): array
{
    $hourly = (array)($raw['hourly'] ?? []);
    $times = array_values((array)($hourly['time'] ?? []));
    if (!$times) return [];
    $temperatureKeys = meteonexa_ensemble_series_keys($hourly, 'temperature_2m');
    $precipitationKeys = meteonexa_ensemble_series_keys($hourly, 'precipitation');
    $windKeys = meteonexa_ensemble_series_keys($hourly, 'wind_gusts_10m');
    $rows = [];
    foreach ($times as $i => $time) {
        $ts = meteonexa_intel_time_utc($time);
        if ($ts === null) continue;
        $temperature = meteonexa_ensemble_values_at($hourly, $temperatureKeys, $i);
        $precipitation = meteonexa_ensemble_values_at($hourly, $precipitationKeys, $i);
        $wind = meteonexa_ensemble_values_at($hourly, $windKeys, $i);
        $tempDist = meteonexa_ensemble_distribution($temperature);
        $rainDist = meteonexa_ensemble_distribution($precipitation);
        $windDist = meteonexa_ensemble_distribution($wind);
        $rows[] = [
            'time'=>gmdate('c', $ts),
            'timestamp'=>$ts,
            'temperature'=>$tempDist,
            'precipitation'=>$rainDist + [
                'gte0_2Pct'=>meteonexa_ensemble_probability($precipitation, static fn(float $v): bool => $v >= .2),
                'gte1Pct'=>meteonexa_ensemble_probability($precipitation, static fn(float $v): bool => $v >= 1.0),
                'gte5Pct'=>meteonexa_ensemble_probability($precipitation, static fn(float $v): bool => $v >= 5.0),
            ],
            'windGust'=>$windDist + [
                'gte50Pct'=>meteonexa_ensemble_probability($wind, static fn(float $v): bool => $v >= 50.0),
                'gte70Pct'=>meteonexa_ensemble_probability($wind, static fn(float $v): bool => $v >= 70.0),
                'gte90Pct'=>meteonexa_ensemble_probability($wind, static fn(float $v): bool => $v >= 90.0),
            ],
            'temperatureThresholds'=>[
                'lte0Pct'=>meteonexa_ensemble_probability($temperature, static fn(float $v): bool => $v <= 0.0),
                'gte30Pct'=>meteonexa_ensemble_probability($temperature, static fn(float $v): bool => $v >= 30.0),
                'gte35Pct'=>meteonexa_ensemble_probability($temperature, static fn(float $v): bool => $v >= 35.0),
            ],
        ];
    }
    return $rows;
}

function meteonexa_ensemble_member_daily_scenarios(array $raw, ?int $referenceTime = null): array
{
    $reference = $referenceTime ?? time();
    $hourly = (array)($raw['hourly'] ?? []);
    $times = array_values((array)($hourly['time'] ?? []));
    if (!$times) return [];
    $variables = [
        'temperature'=>['base'=>'temperature_2m', 'mode'=>'mean'],
        'precipitation'=>['base'=>'precipitation', 'mode'=>'sum'],
        'windGust'=>['base'=>'wind_gusts_10m', 'mode'=>'max'],
    ];
    $keys = [];
    foreach ($variables as $name=>$meta) $keys[$name] = meteonexa_ensemble_series_keys($hourly, $meta['base']);
    $days = [];
    foreach ($times as $i=>$time) {
        $ts = meteonexa_intel_time_utc($time);
        if ($ts === null) continue;
        $hoursAway = ($ts - $reference) / 3600;
        if ($hoursAway < 168 || $hoursAway > 360) continue;
        $date = gmdate('Y-m-d', $ts);
        foreach ($variables as $name=>$meta) {
            foreach ($keys[$name] as $memberKey) {
                $value = $hourly[$memberKey][$i] ?? null;
                if (!is_numeric($value)) continue;
                $days[$date][$name][$memberKey][] = (float)$value;
            }
        }
    }
    $out = [];
    foreach ($days as $date=>$metrics) {
        $row = ['date'=>$date, 'horizon'=>'day-8-15'];
        foreach ($variables as $name=>$meta) {
            $memberAggregates = [];
            foreach ((array)($metrics[$name] ?? []) as $memberValues) {
                if (!$memberValues) continue;
                if ($meta['mode'] === 'sum') $memberAggregates[] = array_sum($memberValues);
                elseif ($meta['mode'] === 'max') $memberAggregates[] = max($memberValues);
                else $memberAggregates[] = array_sum($memberValues) / count($memberValues);
            }
            $row[$name] = meteonexa_ensemble_distribution($memberAggregates);
        }
        $out[] = $row;
    }
    return array_slice($out, 0, 8);
}

function meteonexa_ensemble_nearest_physics_row(array $physicsModel, int $target, int $maxDelta = 10800): ?array
{
    $best = null;
    $delta = PHP_INT_MAX;
    foreach ((array)($physicsModel['rows'] ?? []) as $row) {
        $ts = (int)($row['timestamp'] ?? 0);
        if ($ts <= 0) continue;
        $d = abs($ts - $target);
        if ($d < $delta) { $delta = $d; $best = $row; }
    }
    return $delta <= $maxDelta && is_array($best) ? $best : null;
}

function meteonexa_ensemble_physics_comparison(array $rows, array $physicsModel, int $hours = 72): array
{
    if (empty($physicsModel['available'])) return ['available'=>false, 'reference'=>'ECMWF IFS deterministic'];
    $now = time();
    $compared = 0;
    $contradictions = 0;
    $tempAbs = [];
    $windAbs = [];
    foreach ($rows as $row) {
        $ts = (int)($row['timestamp'] ?? 0);
        if ($ts < $now - 3600 || $ts > $now + $hours * 3600) continue;
        $physics = meteonexa_ensemble_nearest_physics_row($physicsModel, $ts);
        if (!$physics) continue;
        $aifsTemp = $row['temperature']['p50'] ?? null;
        $aifsRain = $row['precipitation']['p50'] ?? null;
        $aifsWind = $row['windGust']['p50'] ?? null;
        $pTemp = $physics['temperature'] ?? null;
        $pRain = $physics['precipitation'] ?? null;
        $pWind = $physics['windGust'] ?? null;
        $isContradiction = false;
        if (is_numeric($aifsTemp) && is_numeric($pTemp)) {
            $d = abs((float)$aifsTemp - (float)$pTemp); $tempAbs[] = $d; if ($d >= 3.0) $isContradiction = true;
        }
        if (is_numeric($aifsWind) && is_numeric($pWind)) {
            $d = abs((float)$aifsWind - (float)$pWind); $windAbs[] = $d; if ($d >= 20.0) $isContradiction = true;
        }
        $compared++;
        if ($isContradiction) $contradictions++;
    }
    $meanAbs = static fn(array $v): ?float => $v ? round(array_sum($v) / count($v), 2) : null;
    return [
        'available'=>$compared > 0,
        'reference'=>'ECMWF IFS deterministic',
        'comparedPoints'=>$compared,
        'contradictionPoints'=>$contradictions,
        'contradictionPct'=>$compared ? (int)round(100 * $contradictions / $compared) : null,
        'meanAbsoluteDelta'=>['temperature'=>$meanAbs($tempAbs), 'precipitation'=>null, 'windGust'=>$meanAbs($windAbs)],
        'policy'=>'independent-physics-reference', 'precipitationComparison'=>'omitted-native-accumulation-vs-hourly-incompatible',
    ];
}

function meteonexa_ensemble_uncertainty(array $rows, int $hours = 72): array
{
    $now = time();
    $tempWidth = [];
    $rainWidth = [];
    $windWidth = [];
    foreach ($rows as $row) {
        $ts = (int)($row['timestamp'] ?? 0);
        if ($ts < $now - 3600 || $ts > $now + $hours * 3600) continue;
        foreach ([['temperature', &$tempWidth], ['precipitation', &$rainWidth], ['windGust', &$windWidth]] as $pair) {
            [$metric, &$target] = $pair;
            $p10 = $row[$metric]['p10'] ?? null;
            $p90 = $row[$metric]['p90'] ?? null;
            if (is_numeric($p10) && is_numeric($p90)) $target[] = max(0.0, (float)$p90 - (float)$p10);
        }
    }
    $median = static fn(array $v): ?float => meteonexa_ensemble_quantile($v, .5);
    $t = $median($tempWidth); $r = $median($rainWidth); $w = $median($windWidth);
    if ($t === null && $r === null && $w === null) return ['available'=>false, 'score'=>null];
    $parts = [];
    if ($t !== null) $parts[] = min(100.0, $t / 8.0 * 100.0);
    if ($r !== null) $parts[] = min(100.0, $r / 5.0 * 100.0);
    if ($w !== null) $parts[] = min(100.0, $w / 30.0 * 100.0);
    $penalty = $parts ? array_sum($parts) / count($parts) : 100.0;
    return [
        'available'=>true,
        'score'=>(int)round(max(0.0, 100.0 - $penalty)),
        'medianP10P90Width'=>[
            'temperature'=>$t === null ? null : round($t, 2),
            'precipitation'=>$r === null ? null : round($r, 2),
            'windGust'=>$w === null ? null : round($w, 2),
        ],
        'method'=>'normalized-p10-p90-spread-v1',
    ];
}
