<?php
declare(strict_types=1);

/**
 * MeteoNexa P2 — probabilistic ensemble helpers.
 *
 * AIFS ENS is treated as probabilistic evidence only. It never replaces the
 * deterministic multi-model consensus and ECMWF IFS remains an independent
 * physical NWP reference. The upstream request uses native ensemble cadence so
 * 8–15 day guidance is represented as scenarios/ranges, not fake hourly detail.
 */
require_once __DIR__ . '/engine_helpers.php';

function meteonexa_ensemble_quantile(array $values, float $q): ?float
{
    $clean = array_values(array_map('floatval', array_filter($values, 'is_numeric')));
    if (!$clean) return null;
    sort($clean, SORT_NUMERIC);
    $q = meteonexa_intel_clamp($q, 0.0, 1.0);
    if (count($clean) === 1) return $clean[0];
    $position = $q * (count($clean) - 1);
    $lo = (int)floor($position);
    $hi = (int)ceil($position);
    if ($lo === $hi) return $clean[$lo];
    $fraction = $position - $lo;
    return $clean[$lo] + ($clean[$hi] - $clean[$lo]) * $fraction;
}

function meteonexa_ensemble_mean(array $values): ?float
{
    $clean = array_values(array_map('floatval', array_filter($values, 'is_numeric')));
    return $clean ? array_sum($clean) / count($clean) : null;
}

function meteonexa_ensemble_stddev(array $values): ?float
{
    $clean = array_values(array_map('floatval', array_filter($values, 'is_numeric')));
    if (count($clean) < 2) return $clean ? 0.0 : null;
    $mean = array_sum($clean) / count($clean);
    $sum = 0.0;
    foreach ($clean as $value) $sum += ($value - $mean) ** 2;
    return sqrt($sum / count($clean));
}

/** Empirical CRPS: E|X-y| - 1/2 E|X-X'|. */
function meteonexa_ensemble_crps(array $members, float $observation): ?float
{
    $values = array_values(array_map('floatval', array_filter($members, 'is_numeric')));
    $n = count($values);
    if ($n < 2) return null;
    sort($values, SORT_NUMERIC);
    $absolute = 0.0;
    foreach ($values as $value) $absolute += abs($value - $observation);
    $absolute /= $n;
    $pair = 0.0;
    foreach ($values as $i => $value) {
        $rank = $i + 1;
        $pair += (2 * $rank - $n - 1) * $value;
    }
    $pair /= ($n * $n);
    return max(0.0, $absolute - $pair);
}

function meteonexa_ensemble_series_keys(array $hourly, string $variable): array
{
    $keys = [];
    if (isset($hourly[$variable]) && is_array($hourly[$variable])) $keys[] = $variable; // control member
    $prefix = $variable . '_member';
    foreach ($hourly as $key => $values) {
        if (!is_array($values) || !str_starts_with((string)$key, $prefix)) continue;
        if (!preg_match('/^' . preg_quote($variable, '/') . '_member\d+$/', (string)$key)) continue;
        $keys[] = (string)$key;
    }
    usort($keys, static function(string $a, string $b) use ($variable): int {
        if ($a === $variable) return -1;
        if ($b === $variable) return 1;
        return strnatcmp($a, $b);
    });
    return $keys;
}

function meteonexa_ensemble_values_at(array $hourly, array $keys, int $index): array
{
    $values = [];
    foreach ($keys as $key) {
        $value = $hourly[$key][$index] ?? null;
        if (is_numeric($value)) $values[] = (float)$value;
    }
    return $values;
}

function meteonexa_ensemble_probability(array $values, callable $predicate): ?int
{
    $clean = array_values(array_map('floatval', array_filter($values, 'is_numeric')));
    if (!$clean) return null;
    $hits = 0;
    foreach ($clean as $value) if ($predicate($value)) $hits++;
    return (int)round(100 * $hits / count($clean));
}

function meteonexa_ensemble_distribution(array $values): array
{
    if (!$values) return ['available'=>false, 'members'=>0];
    $p10 = meteonexa_ensemble_quantile($values, .10);
    $p50 = meteonexa_ensemble_quantile($values, .50);
    $p90 = meteonexa_ensemble_quantile($values, .90);
    return [
        'available'=>true,
        'members'=>count(array_filter($values, 'is_numeric')),
        'p10'=>$p10 === null ? null : round($p10, 2),
        'p50'=>$p50 === null ? null : round($p50, 2),
        'p90'=>$p90 === null ? null : round($p90, 2),
        'mean'=>($mean = meteonexa_ensemble_mean($values)) === null ? null : round($mean, 2),
        'spread'=>($spread = meteonexa_ensemble_stddev($values)) === null ? null : round($spread, 2),
    ];
}

function meteonexa_ensemble_definitions(): array
{
    return [
        'aifs_ens'=>[
            'label'=>'ECMWF AIFS ENS',
            'modelId'=>'ecmwf_aifs025_ensemble',
            'membersExpected'=>51,
            'sourceType'=>'ai-ensemble',
            'cadenceMinutes'=>360,
        ],
        'ifs_ens'=>[
            'label'=>'ECMWF IFS ENS',
            'modelId'=>'ecmwf_ifs025_ensemble',
            'membersExpected'=>51,
            'sourceType'=>'physical-ensemble-fallback',
            'cadenceMinutes'=>360,
        ],
    ];
}

function meteonexa_ensemble_url(float $lat, float $lon, string $modelId, int $forecastHours = 360): string
{
    $params = http_build_query([
        'latitude'=>round($lat, 4),
        'longitude'=>round($lon, 4),
        'models'=>$modelId,
        'hourly'=>'temperature_2m,precipitation,wind_gusts_10m',
        'forecast_hours'=>max(168, min(360, $forecastHours)),
        'temporal_resolution'=>'native',
        'timezone'=>'GMT',
        'wind_speed_unit'=>'kmh',
        'precipitation_unit'=>'mm',
    ], '', '&', PHP_QUERY_RFC3986);
    return 'https://ensemble-api.open-meteo.com/v1/ensemble?' . $params;
}

function meteonexa_ensemble_fetch(float $lat, float $lon, int $forecastHours = 360): array
{
    // Keep P2 fail-soft inside the same total upstream budget used by the
    // deterministic provider orchestrator. A cold/slow ensemble source must
    // never add two full provider timeouts to Intelligence bootstrap.
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

function meteonexa_ensemble_nearest_index(array $times, int $target, int $maxDelta = 10800): ?int
{
    $best = null;
    $delta = PHP_INT_MAX;
    foreach ($times as $index=>$value) {
        $ts = meteonexa_intel_time_utc($value);
        if ($ts === null) continue;
        $d = abs($ts - $target);
        if ($d < $delta) { $delta = $d; $best = (int)$index; }
    }
    return $best !== null && $delta <= $maxDelta ? $best : null;
}

function meteonexa_ensemble_queue_verification(PDO $pdo, string $deviceId, string $locationKey, array $raw): int
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples')) return 0;
    $hourly = (array)($raw['hourly'] ?? []);
    $times = array_values((array)($hourly['time'] ?? []));
    if (!$times) return 0;
    $meta = (array)($raw['_meteonexaEnsemble'] ?? []);
    $modelId = (string)($meta['modelId'] ?? 'ecmwf_aifs025_ensemble');
    $issuedAt = gmdate('c');
    $insertSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "INSERT IGNORE INTO ensemble_verification_samples(device_id,location_key,model_id,metric,horizon_hours,target_time,member_count,members_json,p10,p50,p90,observed_value,crps,status,issued_at,verified_at,created_at) VALUES(:device,:location,:model,:metric,:horizon,:target,:count,:members,:p10,:p50,:p90,NULL,NULL,'pending',:issued,'',:created)" : "INSERT OR IGNORE INTO ensemble_verification_samples(device_id,location_key,model_id,metric,horizon_hours,target_time,member_count,members_json,p10,p50,p90,observed_value,crps,status,issued_at,verified_at,created_at) VALUES(:device,:location,:model,:metric,:horizon,:target,:count,:members,:p10,:p50,:p90,NULL,NULL,'pending',:issued,'',:created)";
    $insert = $pdo->prepare($insertSql);
    $now = time();
    $count = 0;
    foreach ([6,24,48,72] as $horizon) {
        $index = meteonexa_ensemble_nearest_index($times, $now + $horizon * 3600);
        if ($index === null) continue;
        $targetTs = meteonexa_intel_time_utc($times[$index] ?? '');
        if ($targetTs === null) continue;
        foreach (['temperature'=>'temperature_2m','wind'=>'wind_gusts_10m'] as $metric=>$variable) {
            $members = meteonexa_ensemble_values_at($hourly, meteonexa_ensemble_series_keys($hourly, $variable), $index);
            if (count($members) < 10) continue;
            $dist = meteonexa_ensemble_distribution($members);
            $insert->execute([
                ':device'=>$deviceId, ':location'=>$locationKey, ':model'=>$modelId, ':metric'=>$metric,
                ':horizon'=>$horizon, ':target'=>gmdate('c', $targetTs), ':count'=>count($members),
                ':members'=>json_encode(array_values($members), JSON_UNESCAPED_SLASHES),
                ':p10'=>$dist['p10'] ?? null, ':p50'=>$dist['p50'] ?? null, ':p90'=>$dist['p90'] ?? null,
                ':issued'=>$issuedAt, ':created'=>$issuedAt,
            ]);
            $count += $insert->rowCount() ? 1 : 0;
        }
    }
    return $count;
}

function meteonexa_ensemble_verify_pending(PDO $pdo, string $deviceId, string $locationKey, array $observations): int
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples') || empty($observations['available']) || empty($observations['independentFromNwp'])) return 0;
    $observedTs = meteonexa_intel_time_utc($observations['observedAt'] ?? '');
    if ($observedTs === null) return 0;
    $select = $pdo->prepare("SELECT id,metric,target_time,members_json FROM ensemble_verification_samples WHERE device_id=:device AND location_key=:location AND status='pending' ORDER BY id ASC LIMIT 300");
    $select->execute([':device'=>$deviceId, ':location'=>$locationKey]);
    $update = $pdo->prepare("UPDATE ensemble_verification_samples SET observed_value=:observed,crps=:crps,status='verified',verified_at=:verified WHERE id=:id AND status='pending'");
    $verified = 0;
    foreach ($select->fetchAll() as $row) {
        $targetTs = meteonexa_intel_time_utc($row['target_time'] ?? '');
        if ($targetTs === null || abs($targetTs - $observedTs) > 5400) continue;
        $metric = (string)($row['metric'] ?? '');
        $observed = $observations[$metric] ?? null;
        if (!is_numeric($observed)) continue;
        $members = json_decode((string)($row['members_json'] ?? ''), true);
        if (!is_array($members)) continue;
        $crps = meteonexa_ensemble_crps($members, (float)$observed);
        if ($crps === null) continue;
        $update->execute([':observed'=>(float)$observed, ':crps'=>round($crps, 6), ':verified'=>gmdate('c'), ':id'=>(int)$row['id']]);
        $verified += $update->rowCount() ? 1 : 0;
    }
    return $verified;
}

function meteonexa_ensemble_verification_summary(PDO $pdo, string $deviceId, string $locationKey): array
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples')) return ['available'=>false,'samples'=>0];
    $st = $pdo->prepare("SELECT model_id,metric,horizon_hours,COUNT(*) samples,AVG(crps) mean_crps FROM ensemble_verification_samples WHERE device_id=:device AND location_key=:location AND status='verified' AND crps IS NOT NULL GROUP BY model_id,metric,horizon_hours ORDER BY metric,horizon_hours");
    $st->execute([':device'=>$deviceId, ':location'=>$locationKey]);
    $rows = [];
    $samples = 0;
    foreach ($st->fetchAll() as $row) {
        $n = (int)($row['samples'] ?? 0); $samples += $n;
        $rows[] = ['modelId'=>(string)$row['model_id'],'metric'=>(string)$row['metric'],'horizonHours'=>(int)$row['horizon_hours'],'samples'=>$n,'meanCrps'=>is_numeric($row['mean_crps'] ?? null) ? round((float)$row['mean_crps'],4) : null];
    }
    return ['available'=>$samples > 0,'samples'=>$samples,'rows'=>$rows,'method'=>'empirical-crps-independent-observations-v1'];
}

function meteonexa_ensemble_prune_verification(PDO $pdo): int
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples')) return 0;
    $cutoff = gmdate('c', time() - 120 * 86400);
    $st = $pdo->prepare("DELETE FROM ensemble_verification_samples WHERE created_at<:cutoff AND (status='verified' OR target_time<:cutoff)");
    $st->execute([':cutoff'=>$cutoff]);
    return $st->rowCount();
}

function meteonexa_ensemble_verification_touch(PDO $pdo, string $deviceId, string $locationKey, array $raw, array $observations): array
{
    try {
        $verified = meteonexa_ensemble_verify_pending($pdo, $deviceId, $locationKey, $observations);
        $queued = meteonexa_ensemble_queue_verification($pdo, $deviceId, $locationKey, $raw);
        $pruned = meteonexa_ensemble_prune_verification($pdo);
        return ['available'=>true,'verified'=>$verified,'queued'=>$queued,'pruned'=>$pruned,'skill'=>meteonexa_ensemble_verification_summary($pdo,$deviceId,$locationKey)];
    } catch (Throwable $ignored) {
        return ['available'=>false,'verified'=>0,'queued'=>0,'pruned'=>0,'reason'=>'verification_touch_failed'];
    }
}

function meteonexa_probabilistic_ensemble(float $lat, float $lon, array $physicsModel = [], array $observations = [], int $forecastHours = 360, ?PDO $pdo = null, string $deviceId = '', string $locationKey = ''): array
{
    $raw = meteonexa_ensemble_fetch($lat, $lon, $forecastHours);
    if (!$raw) return ['available'=>false, 'model'=>'ECMWF AIFS ENS', 'reason'=>'provider_unavailable', 'policy'=>['authority'=>'probabilistic-evidence-only','physicsReference'=>'ECMWF IFS deterministic']];
    $meta = (array)($raw['_meteonexaEnsemble'] ?? []);
    $rows = meteonexa_ensemble_rows($raw);
    if (!$rows) return ['available'=>false, 'model'=>(string)($meta['label'] ?? 'ECMWF AIFS ENS'), 'reason'=>'no_member_rows', 'policy'=>['authority'=>'probabilistic-evidence-only','physicsReference'=>'ECMWF IFS deterministic']];
    $now = time();
    $headline = null; $headlineDelta = PHP_INT_MAX;
    foreach ($rows as $row) {
        $delta = abs((int)$row['timestamp'] - ($now + 24 * 3600));
        if ($delta < $headlineDelta) { $headlineDelta = $delta; $headline = $row; }
    }
    $memberCount = 0;
    foreach (['temperature','precipitation','windGust'] as $metric) $memberCount = max($memberCount, (int)($headline[$metric]['members'] ?? 0));
    $cache = (array)($raw['_meteonexaCache'] ?? []);
    $verification = ['available'=>false,'reason'=>'not_persisted'];
    if ($pdo instanceof PDO && $deviceId !== '' && $locationKey !== '') $verification = meteonexa_ensemble_verification_touch($pdo, $deviceId, $locationKey, $raw, $observations);
    return [
        'available'=>true,
        'model'=>(string)($meta['label'] ?? 'ECMWF AIFS ENS'),
        'modelId'=>(string)($meta['modelId'] ?? 'ecmwf_aifs025_ensemble'),
        'sourceType'=>(string)($meta['sourceType'] ?? 'ai-ensemble'),
        'fallbackUsed'=>(string)($meta['id'] ?? 'aifs_ens') !== 'aifs_ens',
        'memberCount'=>$memberCount,
        'membersExpected'=>(int)($meta['membersExpected'] ?? 51),
        'memberCoveragePct'=>(int)round(100 * $memberCount / max(1,(int)($meta['membersExpected'] ?? 51))),
        'temporalResolution'=>'native-model-resolution',
        'forecastHours'=>max(168, min(360, $forecastHours)),
        'headline24h'=>$headline,
        'shortRange'=>array_values(array_filter($rows, static fn(array $row): bool => (int)$row['timestamp'] <= time() + 72 * 3600)),
        'extendedScenarios'=>meteonexa_ensemble_member_daily_scenarios($raw, $now),
        'uncertainty'=>meteonexa_ensemble_uncertainty($rows, 72),
        'physicsComparison'=>meteonexa_ensemble_physics_comparison($rows, $physicsModel, 72),
        'verification'=>$verification,
        'freshness'=>[
            'stale'=>!empty($cache['stale']),
            'cacheHit'=>!empty($cache['cacheHit']),
            'ageMinutes'=>is_numeric($cache['ageSeconds'] ?? null) ? (int)ceil((int)$cache['ageSeconds'] / 60) : null,
            'fetchedAt'=>$cache['fetchedAt'] ?? null,
        ],
        'policy'=>[
            'authority'=>'probabilistic-evidence-only',
            'physicsReference'=>'ECMWF IFS deterministic',
            'deterministicConsensusUnaffected'=>true,
            'extendedRangeIsDailyScenario'=>true,
            'crpsRequiresIndependentObservations'=>true,
        ],
        'generatedAt'=>gmdate('c'),
    ];
}
