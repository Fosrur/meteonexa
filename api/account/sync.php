<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/account_helpers.php';
assert_same_origin();
require_method('GET', 'POST');
$config = load_config();
$pdo = meteonexa_db($config);
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    $device = clean_device_id($_GET['deviceId']??'');
    $session = require_authenticated_device_session($pdo, $config, $device);
    require_device_rate_limit($pdo, 'account_sync_read', $device, 240, 3600);
    $a = meteonexa_account_sync_hash($config, $session);
    $fav = meteonexa_account_sync_read($pdo, $a, 'favorites', 'primary');
    $alerts = meteonexa_account_sync_read($pdo, $a, 'alerts', 'profile');
    respond(['ok'=>true, 'favorites'=>$fav['payload']['rows']??null, 'alerts'=>$alerts['payload']??null, 'activityProfiles'=>meteonexa_account_activity_load($pdo, $a), 'syncScope'=>'authenticated-account']);
}
$data = input_json();
$device = clean_device_id($data['deviceId']??'');
$session = require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'account_sync_write', $device, 120, 3600);
$a = meteonexa_account_sync_hash($config, $session);
$action = strtolower(clean_text($data['action']??'', 32, ''));
if ($action==='favorites') {
    $rows = is_array($data['favorites']??null) ? array_values($data['favorites']) :[];
    if (count($rows) > 24)respond(['ok'=>false, 'code'=>'FAVORITES_LIMIT', 'message'=>'api.account.favorites_limit'], 422);
    $clean =[];
    foreach ($rows as $r) {
        if (!is_array($r)||!is_numeric($r['latitude']??null)||!is_numeric($r['longitude']??null))continue;
        $name = clean_text($r['name']??'', 191, '');
        if ($name==='')continue;
        $clean[] =['name'=>$name, 'admin1'=>clean_text($r['admin1']??'', 191, ''), 'country'=>clean_text($r['country']??'', 120, ''), 'latitude'=>round((float)$r['latitude'], 5), 'longitude'=>round((float)$r['longitude'], 5), 'timezone'=>clean_text($r['timezone']??'auto', 80, 'auto')];
    }
    $rev = meteonexa_account_sync_upsert($pdo, $a, 'favorites', 'primary',['rows'=>$clean], $device);
    respond(['ok'=>true, 'revision'=>$rev, 'favorites'=>$clean]);
}
if ($action==='activity-profile') {
    $activity = strtolower(clean_text($data['activity']??'', 32, ''));
    try {
        $saved = meteonexa_account_activity_upsert($pdo, $a, $activity, is_array($data['thresholds']??null) ? $data['thresholds'] :[], $device);
    } catch (InvalidArgumentException $e) {
        respond(['ok'=>false, 'code'=>'ACTIVITY_INVALID', 'message'=>'api.account.activity_invalid'], 422);
    }
    respond(['ok'=>true, 'activity'=>$activity, 'thresholds'=>$saved]);
}
respond(['ok'=>false, 'code'=>'ACCOUNT_SYNC_ACTION_INVALID', 'message'=>'api.account.action_invalid'], 422);
