<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';

require_method('GET');
assert_same_origin();
$config=load_config();$pdo=meteonexa_db($config);
$deviceId=clean_device_id($_GET['deviceId']??'');
require_authenticated_device_session($pdo,$config,$deviceId);
require_ip_rate_limit($pdo,'alert_history_ip',240,3600);
require_device_rate_limit($pdo,'alert_history_device',$deviceId,180,3600);
$limit=max(1,min(50,(int)($_GET['limit']??20)));
$stmt=$pdo->prepare('SELECT event_key,event_type,severity,confidence,location_name,starts_at,ends_at,delivered_at,created_at FROM weather_alert_events WHERE device_id=:device ORDER BY created_at DESC LIMIT '.$limit);
$stmt->execute([':device'=>$deviceId]);
respond(['ok'=>true,'events'=>$stmt->fetchAll(),'generatedAt'=>gmdate('c')]);
