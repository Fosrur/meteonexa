<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';

assert_same_origin();
require_method('GET', 'POST');
$config = load_config();
$pdo = meteonexa_db($config);

function meteonexa_accuracy_location_key(mixed $value): string
{
    $key = clean_text($value, 80);
    if (preg_match('/^(-?\d{1,3}(?:\.\d{1,6})?):(-?\d{1,3}(?:\.\d{1,6})?)$/', $key, $match) !== 1) {
        respond(['ok'=>false,'code'=>'ACCURACY_INVALID','message'=>'api.accuracy.invalid'],422);
    }
    if (abs((float)$match[1]) > 90 || abs((float)$match[2]) > 180) {
        respond(['ok'=>false,'code'=>'ACCURACY_INVALID','message'=>'api.accuracy.invalid'],422);
    }
    return $key;
}

function meteonexa_accuracy_hour(mixed $value): string
{
    $text = trim((string)$value);
    $timestamp = strtotime($text);
    if ($timestamp === false) respond(['ok'=>false,'code'=>'ACCURACY_INVALID','message'=>'api.accuracy.invalid'],422);
    // Match current observations to hourly model target slots. Round to nearest hour.
    $timestamp = (int)(round($timestamp / 3600) * 3600);
    return gmdate('Y-m-d\TH:00:00\Z', $timestamp);
}

function meteonexa_accuracy_number(mixed $value, float $min, float $max): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return is_finite($number) && $number >= $min && $number <= $max ? round($number, 3) : null;
}

function meteonexa_accuracy_score(float $tempMae, float $rainMae, float $windMae): int
{
    return max(0, min(100, (int)round(100 - min(36, $tempMae * 9) - min(34, $rainMae * 17) - min(30, $windMae * 1.7))));
}

function meteonexa_accuracy_shrunk_weights(array $quality, array $samples, int $minimumSamples): array
{
    if ($quality === []) return [];
    $qualitySum = array_sum($quality);
    if ($qualitySum <= 0.0) return [];
    $models = array_keys($quality);
    $uniform = 1.0 / max(1, count($models));
    $shrunk = [];
    foreach ($models as $model) {
        $target = (float)$quality[$model] / $qualitySum;
        // Full trust is reached only after roughly twice the minimum sample
        // count. Before then, move smoothly from equal weights toward the
        // measured skill weight so a single lucky sample cannot dominate.
        $evidence = min(1.0, max(0.0, (float)($samples[$model] ?? 0) / max(1, $minimumSamples * 2)));
        $shrunk[$model] = $uniform * (1.0 - $evidence) + $target * $evidence;
    }
    $sum = array_sum($shrunk);
    if ($sum <= 0.0) return [];
    $weights = [];
    foreach ($shrunk as $model => $value) $weights[$model] = round($value / $sum, 5);
    return $weights;
}

function meteonexa_accuracy_weights(array $rows, string $field, float $floor, int $minimumSamples = 6): array
{
    $quality = [];
    $samples = [];
    foreach ($rows as $row) {
        $model = trim((string)($row['model'] ?? ''));
        $mae = isset($row[$field]) ? (float)$row[$field] : INF;
        if ($model === '' || !is_finite($mae)) continue;
        $samples[$model] = max(0, (int)($row['samples'] ?? 0));
        $quality[$model] = 1.0 / max($floor, $mae + $floor * 0.35);
    }
    return meteonexa_accuracy_shrunk_weights($quality, $samples, $minimumSamples);
}

function meteonexa_accuracy_overall_weights(array $rows, int $minimumSamples = 6): array
{
    $quality = [];
    $samples = [];
    foreach ($rows as $row) {
        $model = trim((string)($row['model'] ?? ''));
        if ($model === '') continue;
        $score = max(1, min(100, (int)($row['score'] ?? 1)));
        $samples[$model] = max(0, (int)($row['samples'] ?? 0));
        $quality[$model] = pow($score / 100, 2.2);
    }
    return meteonexa_accuracy_shrunk_weights($quality, $samples, $minimumSamples);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $deviceId = clean_device_id($_GET['deviceId'] ?? '');
    require_authenticated_device_session($pdo, $config, $deviceId);
    require_device_rate_limit($pdo, 'accuracy_read_device', $deviceId, 180, 3600);
    $locationKey = meteonexa_accuracy_location_key($_GET['locationKey'] ?? '');
    $statement = $pdo->prepare("SELECT f.model_name,
        COUNT(*) AS samples,
        AVG(ABS(f.temperature-o.temperature)) AS temp_mae,
        AVG(ABS(f.precipitation-o.precipitation)) AS rain_mae,
        AVG(ABS(f.wind_gust-o.wind_gust)) AS wind_mae,
        MIN(f.target_time) AS first_target,
        MAX(f.target_time) AS last_target
        FROM model_forecast_samples f
        JOIN model_observation_samples o ON o.device_id=f.device_id AND o.location_key=f.location_key AND o.observed_time=f.target_time
        WHERE f.device_id=:device AND f.location_key=:location AND f.horizon_hours IN (1,3,6,24,48,72)
        GROUP BY f.model_name ORDER BY samples DESC, f.model_name ASC");
    $statement->execute([':device'=>$deviceId,':location'=>$locationKey]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $tempMae = (float)($row['temp_mae'] ?? 0);
        $rainMae = (float)($row['rain_mae'] ?? 0);
        $windMae = (float)($row['wind_mae'] ?? 0);
        $rows[] = [
            'model'=>(string)$row['model_name'],
            'samples'=>(int)$row['samples'],
            'tempMae'=>round($tempMae,2),
            'rainMae'=>round($rainMae,2),
            'windMae'=>round($windMae,1),
            'score'=>meteonexa_accuracy_score($tempMae,$rainMae,$windMae),
            'firstTarget'=>(string)$row['first_target'],
            'lastTarget'=>(string)$row['last_target'],
        ];
    }
    $best = ['overall'=>null,'temperature'=>null,'rain'=>null,'wind'=>null];
    if ($rows !== []) {
        $overall = $rows; usort($overall, static fn(array $a,array $b): int => $b['score'] <=> $a['score']); $best['overall']=$overall[0]['model'];
        foreach (['temperature'=>'tempMae','rain'=>'rainMae','wind'=>'windMae'] as $label=>$field) {
            $metric = array_values(array_filter($rows, static fn(array $row): bool => $row['samples'] > 0));
            usort($metric, static fn(array $a,array $b): int => $a[$field] <=> $b[$field]);
            $best[$label]=$metric[0]['model'] ?? null;
        }
    }
    $minimumSamples = 6;
    $weights = [
        'overall'=>meteonexa_accuracy_overall_weights($rows, $minimumSamples),
        'temperature'=>meteonexa_accuracy_weights($rows, 'tempMae', 0.35, $minimumSamples),
        'rain'=>meteonexa_accuracy_weights($rows, 'rainMae', 0.08, $minimumSamples),
        'wind'=>meteonexa_accuracy_weights($rows, 'windMae', 1.2, $minimumSamples),
    ];

    $horizonStatement = $pdo->prepare("SELECT f.model_name,f.horizon_hours,
        COUNT(*) AS samples,
        AVG(ABS(f.temperature-o.temperature)) AS temp_mae,
        AVG(ABS(f.precipitation-o.precipitation)) AS rain_mae,
        AVG(ABS(f.wind_gust-o.wind_gust)) AS wind_mae
        FROM model_forecast_samples f
        JOIN model_observation_samples o ON o.device_id=f.device_id AND o.location_key=f.location_key AND o.observed_time=f.target_time
        WHERE f.device_id=:device AND f.location_key=:location AND f.horizon_hours IN (1,3,6,24,48,72)
        GROUP BY f.model_name,f.horizon_hours");
    $horizonStatement->execute([':device'=>$deviceId,':location'=>$locationKey]);
    $horizonGroups = [1=>[],3=>[],6=>[],24=>[],48=>[],72=>[]];
    foreach ($horizonStatement->fetchAll() as $row) {
        $h = (int)($row['horizon_hours'] ?? 0);
        if (!isset($horizonGroups[$h])) continue;
        $tempMae = (float)($row['temp_mae'] ?? 0);
        $rainMae = (float)($row['rain_mae'] ?? 0);
        $windMae = (float)($row['wind_mae'] ?? 0);
        $horizonGroups[$h][] = [
            'model'=>(string)$row['model_name'],
            'samples'=>(int)$row['samples'],
            'tempMae'=>round($tempMae,2),
            'rainMae'=>round($rainMae,2),
            'windMae'=>round($windMae,1),
            'score'=>meteonexa_accuracy_score($tempMae,$rainMae,$windMae),
        ];
    }
    $weightsByHorizon = [];
    foreach ($horizonGroups as $horizon=>$groupRows) {
        $weightsByHorizon[(string)$horizon] = [
            'overall'=>meteonexa_accuracy_overall_weights($groupRows, $minimumSamples),
            'temperature'=>meteonexa_accuracy_weights($groupRows, 'tempMae', 0.35, $minimumSamples),
            'rain'=>meteonexa_accuracy_weights($groupRows, 'rainMae', 0.08, $minimumSamples),
            'wind'=>meteonexa_accuracy_weights($groupRows, 'windMae', 1.2, $minimumSamples),
        ];
    }

    $skill=meteonexa_intelq_skill_summary($pdo,$deviceId,$locationKey);
    respond(['ok'=>true,'rows'=>$rows,'best'=>$best,'minimumSamples'=>$minimumSamples,'weights'=>$weights,'weightsByHorizon'=>$weightsByHorizon,'skill'=>$skill,'horizons'=>[1,3,6,24,48,72],'calibrationMode'=>'hybrid-worker-browser']);
}

$data = input_json();
$deviceId = clean_device_id($data['deviceId'] ?? '');
require_authenticated_device_session($pdo, $config, $deviceId);
require_ip_rate_limit($pdo, 'accuracy_write_ip', 240, 3600);
require_device_rate_limit($pdo, 'accuracy_write_device', $deviceId, 120, 3600);
$locationKey = meteonexa_accuracy_location_key($data['locationKey'] ?? '');
$observation = is_array($data['observation'] ?? null) ? $data['observation'] : [];
$observationTime = meteonexa_accuracy_hour($observation['time'] ?? '');
$observationTemp = meteonexa_accuracy_number($observation['temperature'] ?? null, -100, 70);
$observationRain = meteonexa_accuracy_number($observation['precipitation'] ?? null, 0, 500);
$observationWind = meteonexa_accuracy_number($observation['windGust'] ?? null, 0, 600);
if ($observationTemp === null || $observationRain === null || $observationWind === null) {
    respond(['ok'=>false,'code'=>'ACCURACY_INVALID','message'=>'api.accuracy.invalid'],422);
}
$forecastRows = is_array($data['forecasts'] ?? null) ? array_slice($data['forecasts'], 0, 30) : [];
$allowedModels = ['ecmwf','aifs','icon','gfs','meteofrance','ukmo'];
$pdo->exec('BEGIN IMMEDIATE');
try {
    $obs = $pdo->prepare('INSERT INTO model_observation_samples(device_id,location_key,observed_time,temperature,precipitation,wind_gust,created_at)
        VALUES(:device,:location,:time,:temp,:rain,:wind,:created)
        ON CONFLICT(device_id,location_key,observed_time) DO UPDATE SET temperature=excluded.temperature,
        precipitation=excluded.precipitation,wind_gust=excluded.wind_gust,created_at=excluded.created_at');
    $obs->execute([':device'=>$deviceId,':location'=>$locationKey,':time'=>$observationTime,':temp'=>$observationTemp,':rain'=>$observationRain,':wind'=>$observationWind,':created'=>gmdate('c')]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO model_forecast_samples(device_id,location_key,model_name,target_time,horizon_hours,temperature,precipitation,wind_gust,created_at)
        VALUES(:device,:location,:model,:target,:horizon,:temp,:rain,:wind,:created)');
    $now = time();
    foreach ($forecastRows as $row) {
        if (!is_array($row)) continue;
        $model = trim((string)($row['model'] ?? ''));
        if (!in_array($model, $allowedModels, true)) continue;
        $target = meteonexa_accuracy_hour($row['targetTime'] ?? '');
        $targetTimestamp = strtotime($target) ?: 0;
        $horizon = (int)($row['horizonHours'] ?? 0);
        if (!in_array($horizon, [1,3,6,24,48,72], true) || $targetTimestamp < $now - 1800 || $targetTimestamp > $now + 80*3600) continue;
        $temp = meteonexa_accuracy_number($row['temperature'] ?? null, -100, 70);
        $rain = meteonexa_accuracy_number($row['precipitation'] ?? null, 0, 500);
        $wind = meteonexa_accuracy_number($row['windGust'] ?? null, 0, 600);
        if ($temp === null || $rain === null || $wind === null) continue;
        $insert->execute([':device'=>$deviceId,':location'=>$locationKey,':model'=>$model,':target'=>$target,':horizon'=>$horizon,':temp'=>$temp,':rain'=>$rain,':wind'=>$wind,':created'=>gmdate('c')]);
    }
    $cutoff = gmdate('Y-m-d\TH:00:00\Z', time() - 120*86400);
    $pdo->prepare('DELETE FROM model_forecast_samples WHERE target_time < :cutoff')->execute([':cutoff'=>$cutoff]);
    $pdo->prepare('DELETE FROM model_observation_samples WHERE observed_time < :cutoff')->execute([':cutoff'=>$cutoff]);
    $pdo->exec('COMMIT');
} catch (Throwable $error) {
    try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { }
    throw $error;
}
respond(['ok'=>true]);
