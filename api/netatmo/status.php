<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
$device = clean_device_id($_GET['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'netatmo_status_device', $device, 300, 3600);
$configured = trim((string)($config['netatmo']['client_id']??''))!==''&&trim((string)($config['netatmo']['client_secret']??''))!=='';
$st = $pdo->prepare('SELECT expires_at,scope,updated_at FROM netatmo_accounts WHERE device_id=:device');
$st->execute([':device'=>$device]);
$row = $st->fetch();
respond(['ok'=>true, 'configured'=>$configured, 'connected'=>(bool)$row, 'expiresAt'=>$row ? (int)$row['expires_at'] : null, 'scope'=>$row['scope']??null, 'updatedAt'=>$row['updated_at']??null]);
