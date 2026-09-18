<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/web_push.php';
require_once dirname(__DIR__) . '/i18n.php';
assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$data = input_json();
$device = clean_device_id($data['deviceId']??'');
require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo, 'push_test_ip', 60, 3600);
require_device_rate_limit($pdo, 'push_test_device', $device, 20, 3600);
$st = $pdo->prepare('SELECT endpoint,profile_json FROM push_subscriptions WHERE device_id=:device AND active=1 ORDER BY id DESC LIMIT 1');
$st->execute([':device'=>$device]);
$row = $st->fetch();
$profile = $row ? json_decode((string)$row['profile_json'], true) :[];
$language = meteonexa_language(is_array($profile) ?($profile['_language']??'it') : 'it');
$tr = static fn(string $key, array $params =[]) : string=>meteonexa_text($pdo, $language, $key, $params);
if (!$row)respond(['ok'=>false, 'code'=>'NOT_SUBSCRIBED', 'message'=>$tr('push.error.not_subscribed')], 404);
$key = 'test:' . bin2hex(random_bytes(5));
$pdo->prepare('INSERT INTO push_notifications(device_id,notice_key,title,body,target_url,tag,created_at,expires_at) VALUES(:device,:key,:title,:body,:url,:tag,:now,:expires)')->execute([':device'=>$device, ':key'=>$key, ':title'=>$tr('push.test.title'), ':body'=>$tr('push.test.body'), ':url'=>'./#notifications', ':tag'=>'meteonexa-suite-test', ':now'=>gmdate('c'), ':expires'=>gmdate('c', time() + 3600)]);
meteonexa_prune_rows_to_limit($pdo, 'push_notifications', (int)($config['storage_limits']['push_notifications']??50000));
try {
    $result = meteonexa_send_empty_push((string)$row['endpoint'], $config, 120);
    if (!$result['ok'])throw new RuntimeException($tr('push.error.http',['status'=>$result['status']]));
    respond(['ok'=>true, 'status'=>$result['status']]);
} catch (Throwable $e) {
    $pdo->prepare('DELETE FROM push_notifications WHERE device_id=:device AND notice_key=:key')->execute([':device'=>$device, ':key'=>$key]);
    respond(['ok'=>false, 'code'=>'PUSH_FAILED', 'message'=>meteonexa_backend_text('api.backend.push_failed')], 502);
}
