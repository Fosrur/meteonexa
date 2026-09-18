<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php'; require_once dirname(__DIR__) . '/public_helpers.php';
assert_same_origin(); require_method('POST'); $config=load_config(); $pdo=meteonexa_db($config); $data=input_json(); $device=clean_device_id($data['deviceId']??'');require_authenticated_device_session($pdo, $config, $device);require_device_rate_limit($pdo,'push_unsubscribe_device',$device,60,3600);
$st=$pdo->prepare('UPDATE push_subscriptions SET active=0,updated_at=:now WHERE device_id=:device'); $st->execute([':now'=>gmdate('c'),':device'=>$device]); respond(['ok'=>true]);
