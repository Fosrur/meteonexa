<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/helpers.php';

assert_same_origin();
require_method('POST');
$config=load_config();
netatmo_assert_configured($config);
$pdo=meteonexa_db($config);
$data=input_json();
$device=clean_device_id($data['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo,'netatmo_start_ip',60,3600);
require_device_rate_limit($pdo,'netatmo_start_device',$device,30,3600);
$language=meteonexa_backend_language($data['language']??null);

$return=netatmo_safe_return_path(clean_text($data['return']??netatmo_default_return_path($config),500,netatmo_default_return_path($config)),$config);
$state=meteonexa_base64url_encode(random_bytes(32));
$hash=hash('sha256',$state);
$pdo->prepare('DELETE FROM oauth_states WHERE expires_at<:now')->execute([':now'=>time()]);
$pdo->prepare('DELETE FROM oauth_states WHERE state_hash IN (SELECT state_hash FROM oauth_states WHERE device_id=:device ORDER BY created_at DESC LIMIT -1 OFFSET 5)')->execute([':device'=>$device]);
$pdo->prepare('INSERT INTO oauth_states(state_hash,device_id,return_url,language,expires_at,created_at) VALUES(:hash,:device,:return,:language,:expires,:created)')
    ->execute([':hash'=>$hash,':device'=>$device,':return'=>$return,':language'=>$language,':expires'=>time()+900,':created'=>gmdate('c')]);
meteonexa_prune_rows_to_limit($pdo,'oauth_states',(int)($config['storage_limits']['oauth_states']??5000));
$query=http_build_query(['client_id'=>$config['netatmo']['client_id'],'redirect_uri'=>netatmo_redirect_uri($config),'scope'=>$config['netatmo']['scope']??'read_station','state'=>$state]);
respond(['ok'=>true,'authorizeUrl'=>'https://api.netatmo.com/oauth2/authorize?'.$query]);
