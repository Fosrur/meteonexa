<?php
declare(strict_types=1);
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
    if (isset($hourly[$variable]) && is_array($hourly[$variable])) $keys[] = $variable; 
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
