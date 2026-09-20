<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/i18n.php';

assert_same_origin();
require_method('POST');
$config=load_config();
$pdo=meteonexa_db($config);
$data=input_json();
$language=meteonexa_language($data['language']??'it');
$tr=static fn(string $key,array $params=[]):string=>meteonexa_text($pdo,$language,$key,$params);
$device=clean_device_id($data['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo,'push_subscribe_ip',120,3600);
require_device_rate_limit($pdo,'push_subscribe_device',$device,60,3600);
$sub=$data['subscription']??null;
if(!is_array($sub)||empty($sub['endpoint'])||!is_array($sub['keys']??null)||empty($sub['keys']['p256dh'])||empty($sub['keys']['auth'])) {
    respond(['ok'=>false,'code'=>'INVALID_SUBSCRIPTION','message'=>$tr('push.error.invalid_subscription')],422);
}
try {
    $endpoint=meteonexa_validate_push_endpoint((string)$sub['endpoint'], (array)($config['push']['allowed_endpoint_hosts'] ?? []));
} catch(Throwable $error) {
    respond(['ok'=>false,'code'=>'INVALID_ENDPOINT','message'=>$tr('push.error.invalid_endpoint')],422);
}
if(strlen($endpoint)>2048) respond(['ok'=>false,'code'=>'INVALID_ENDPOINT','message'=>$tr('push.error.invalid_endpoint')],422);
$p256dh=clean_text($sub['keys']['p256dh'],200);
$auth=clean_text($sub['keys']['auth'],100);
if(preg_match('/^[A-Za-z0-9_-]{80,120}$/',$p256dh)!==1||preg_match('/^[A-Za-z0-9_-]{16,64}$/',$auth)!==1) {
    respond(['ok'=>false,'code'=>'INVALID_SUBSCRIPTION','message'=>$tr('push.error.invalid_subscription')],422);
}
$owner=$pdo->prepare('SELECT device_id FROM push_subscriptions WHERE endpoint=:endpoint LIMIT 1');
$owner->execute([':endpoint'=>$endpoint]);
$existingOwner=$owner->fetchColumn();
if(($existingOwner===false||$existingOwner===null||$existingOwner==='')){
    $maxSubscriptions=max(1,min(32,(int)($config['push']['max_subscriptions_per_device']??8)));
    $count=$pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE device_id=:device AND active=1');
    $count->execute([':device'=>$device]);
    if((int)$count->fetchColumn()>=$maxSubscriptions) respond(['ok'=>false,'code'=>'PUSH_QUOTA','message'=>'api.security.rate_limit'],429);
}
if(is_string($existingOwner)&&$existingOwner!==''&&!hash_equals($existingOwner,$device)) {
    respond(['ok'=>false,'code'=>'ENDPOINT_IN_USE','message'=>'api.security.device_access_denied'],409);
}
$location=is_array($data['location']??null)?$data['location']:[];
$lat=isset($location['latitude'])?filter_var($location['latitude'],FILTER_VALIDATE_FLOAT):null;
$lon=isset($location['longitude'])?filter_var($location['longitude'],FILTER_VALIDATE_FLOAT):null;
if($lat===false||($lat!==null&&($lat < -90||$lat>90)))$lat=null;
if($lon===false||($lon!==null&&($lon < -180||$lon>180)))$lon=null;
$profile=is_array($data['profile']??null)?$data['profile']:[];
$profile['_language']=$language;
$profileJson=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if(!is_string($profileJson)||strlen($profileJson)>12000) respond(['ok'=>false,'code'=>'PROFILE_TOO_LARGE','message'=>'api.backend.profile_too_large'],413);
$name=clean_text($location['name']??'',120,'');
if($name==='')$name=$tr('location.selected.generic');
$timezone=clean_text($data['timezone']??'auto',80,'auto');
$now=gmdate('c');
$st=$pdo->prepare("INSERT INTO push_subscriptions(device_id,endpoint,p256dh,auth,location_name,latitude,longitude,timezone,profile_json,active,created_at,updated_at)
VALUES(:device,:endpoint,:p256dh,:auth,:name,:lat,:lon,:tz,:profile,1,:now,:now)
ON CONFLICT(endpoint) DO UPDATE SET p256dh=excluded.p256dh,auth=excluded.auth,location_name=excluded.location_name,latitude=excluded.latitude,longitude=excluded.longitude,timezone=excluded.timezone,profile_json=excluded.profile_json,active=1,updated_at=excluded.updated_at
WHERE push_subscriptions.device_id=excluded.device_id");
$st->execute([':device'=>$device,':endpoint'=>$endpoint,':p256dh'=>$p256dh,':auth'=>$auth,':name'=>$name,':lat'=>$lat,':lon'=>$lon,':tz'=>$timezone,':profile'=>$profileJson,':now'=>$now]);
respond(['ok'=>true,'message'=>$tr('push.subscribe.success')]);
