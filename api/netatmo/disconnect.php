<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$data = input_json();
$device = clean_device_id($data['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'netatmo_disconnect_device', $device, 30, 3600);
$pdo->prepare('DELETE FROM netatmo_accounts WHERE device_id=:device')->execute([':device'=>$device]);
respond(['ok'=>true]);
