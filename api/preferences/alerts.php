<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/account/account_helpers.php';
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_method('GET', 'POST');
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    $device = clean_device_id($_GET['deviceId']??'');
    $session = require_authenticated_device_session($pdo, $config, $device);
    require_device_rate_limit($pdo, 'alert_profile_read_device', $device, 360, 3600);
    $a = meteonexa_account_sync_hash($config, $session);
    $account = meteonexa_account_sync_read($pdo, $a, 'alerts', 'profile');
    if ($account===null) {
        $st = $pdo->prepare('SELECT profile_json,updated_at FROM alert_profiles WHERE device_id=:d');
        $st->execute([':d'=>$device]);
        $r = $st->fetch();
        if ($r) {
            $p = json_decode((string)$r['profile_json'], true);
            if (is_array($p)) {
                meteonexa_account_sync_upsert($pdo, $a, 'alerts', 'profile', $p, $device);
                $account =['payload'=>$p, 'updatedAt'=>(string)$r['updated_at']];
            }
        }
    }
    respond(['ok'=>true, 'profile'=>$account['payload']??null, 'updatedAt'=>$account['updatedAt']??null, 'syncScope'=>'authenticated-account']);
}
$data = input_json();
$device = clean_device_id($data['deviceId']??'');
$session = require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'alert_profile_write_device', $device, 120, 3600);
$profile = $data['profile']??null;
if (!is_array($profile))respond(['ok'=>false, 'code'=>'INVALID_PROFILE', 'message'=>meteonexa_backend_text('api.backend.invalid_profile')], 422);
$json = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)||strlen($json) > 12000)respond(['ok'=>false, 'code'=>'PROFILE_TOO_LARGE', 'message'=>meteonexa_backend_text('api.backend.profile_too_large')], 413);
$st = $pdo->prepare('INSERT INTO alert_profiles(device_id,profile_json,updated_at) VALUES(:d,:j,:u) ON CONFLICT(device_id) DO UPDATE SET profile_json=excluded.profile_json,updated_at=excluded.updated_at');
$st->execute([':d'=>$device, ':j'=>$json, ':u'=>gmdate('c')]);
$a = meteonexa_account_sync_hash($config, $session);
$rev = meteonexa_account_sync_upsert($pdo, $a, 'alerts', 'profile', $profile, $device);
respond(['ok'=>true, 'message'=>meteonexa_backend_text('api.backend.profile_synced'), 'revision'=>$rev, 'syncScope'=>'authenticated-account']);
