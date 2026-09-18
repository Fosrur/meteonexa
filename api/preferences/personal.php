<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';

assert_same_origin();
require_method('GET', 'POST');
$config = load_config();
$pdo = meteonexa_db($config);

$allowedActivities = ['run','sea','laundry','motorcycle','trekking','kids'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $deviceId = clean_device_id($_GET['deviceId'] ?? '');
    require_authenticated_device_session($pdo, $config, $deviceId);
    require_device_rate_limit($pdo, 'personal_preferences_read', $deviceId, 240, 3600);
    $statement = $pdo->prepare('SELECT activities_json,briefing_enabled,briefing_hour,briefing_hour_set,proactive_enabled,updated_at FROM personal_weather_preferences WHERE device_id=:device LIMIT 1');
    $statement->execute([':device'=>$deviceId]);
    $row = $statement->fetch();
    $activities = is_array($row) ? json_decode((string)$row['activities_json'], true) : [];
    if (!is_array($activities)) $activities = [];
    $activities = array_values(array_intersect($allowedActivities, array_map('strval', $activities)));
    respond([
        'ok'=>true,
        'preferences'=>[
            'activities'=>$activities,
            'briefingEnabled'=>is_array($row) && (int)$row['briefing_enabled'] === 1,
            'briefingHour'=>is_array($row) ? max(0, min(23, (int)$row['briefing_hour'])) : null,
            'briefingHourSet'=>is_array($row) && (int)($row['briefing_hour_set'] ?? 0) === 1,
            'proactiveEnabled'=>is_array($row) && (int)($row['proactive_enabled'] ?? 0) === 1,
            'updatedAt'=>is_array($row) ? (string)$row['updated_at'] : '',
        ],
    ]);
}

$data = input_json();
$deviceId = clean_device_id($data['deviceId'] ?? '');
require_authenticated_device_session($pdo, $config, $deviceId);
require_ip_rate_limit($pdo, 'personal_preferences_write_ip', 180, 3600);
require_device_rate_limit($pdo, 'personal_preferences_write', $deviceId, 90, 3600);
$activitiesInput = is_array($data['activities'] ?? null) ? $data['activities'] : [];
$activities = array_values(array_unique(array_intersect($allowedActivities, array_map(static fn($value): string => trim((string)$value), $activitiesInput))));
if (count($activities) > 6) {
    respond(['ok'=>false,'code'=>'PERSONAL_PREFERENCES_INVALID','message'=>'api.personal.invalid'],422);
}
$briefingEnabled = filter_var($data['briefingEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$briefingHourSet = filter_var($data['briefingHourSet'] ?? false, FILTER_VALIDATE_BOOLEAN);
$proactiveEnabled = filter_var($data['proactiveEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$briefingHour = filter_var($data['briefingHour'] ?? 8, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>23]]);
if ($briefingHour === false) {
    respond(['ok'=>false,'code'=>'PERSONAL_PREFERENCES_INVALID','message'=>'api.personal.invalid'],422);
}
$json = json_encode($activities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    respond(['ok'=>false,'code'=>'PERSONAL_PREFERENCES_INVALID','message'=>'api.personal.invalid'],422);
}
$statement = $pdo->prepare('INSERT INTO personal_weather_preferences(device_id,activities_json,briefing_enabled,briefing_hour,briefing_hour_set,proactive_enabled,updated_at)
    VALUES(:device,:activities,:enabled,:hour,:hour_set,:proactive,:updated)
    ON CONFLICT(device_id) DO UPDATE SET activities_json=excluded.activities_json,briefing_enabled=excluded.briefing_enabled,
    briefing_hour=excluded.briefing_hour,briefing_hour_set=excluded.briefing_hour_set,proactive_enabled=excluded.proactive_enabled,updated_at=excluded.updated_at');
$statement->execute([
    ':device'=>$deviceId,
    ':activities'=>$json,
    ':enabled'=>$briefingEnabled ? 1 : 0,
    ':hour'=>$briefingHour,
    ':hour_set'=>$briefingHourSet ? 1 : 0,
    ':proactive'=>$proactiveEnabled ? 1 : 0,
    ':updated'=>gmdate('c'),
]);
respond(['ok'=>true,'preferences'=>['activities'=>$activities,'briefingEnabled'=>$briefingEnabled,'briefingHour'=>$briefingHour,'briefingHourSet'=>$briefingHourSet,'proactiveEnabled'=>$proactiveEnabled]]);
