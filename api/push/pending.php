<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
assert_same_origin();
require_method('POST');
$config=load_config();
$pdo=meteonexa_db($config);
$data=input_json();
$device=clean_device_id($data['deviceId']??'');
require_authenticated_device_session($pdo,$config,$device);
require_device_rate_limit($pdo,'push_pending_device',$device,360,3600);
$now=gmdate('c');
$pdo->prepare("DELETE FROM push_notifications WHERE expires_at<>'' AND expires_at<=:now")->execute([':now'=>$now]);
$st=$pdo->prepare("SELECT * FROM push_notifications WHERE device_id=:device AND delivered_at='' AND dismissed_at='' AND (expires_at='' OR expires_at>:now) ORDER BY id DESC LIMIT 1");
$st->execute([':device'=>$device,':now'=>$now]);
$row=$st->fetch();
if(!$row) respond(['ok'=>true,'notification'=>null]);
$pdo->prepare("UPDATE push_notifications SET delivered_at=:at WHERE id=:id AND device_id=:device AND delivered_at='' AND dismissed_at='' ")
    ->execute([':at'=>$now,':id'=>$row['id'],':device'=>$device]);
respond(['ok'=>true,'notification'=>['title'=>$row['title'],'body'=>$row['body'],'url'=>$row['target_url'],'tag'=>$row['tag']]]);
