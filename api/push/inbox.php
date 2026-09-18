<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';

assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$data = input_json();
$device = clean_device_id($data['deviceId'] ?? '');
require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'push_inbox_device', $device, 360, 3600);
$action = strtolower(clean_text($data['action'] ?? 'list', 20, 'list'));
$now = gmdate('c');

// Retention is deliberately short after a notification has been consumed.
// Expired event notifications disappear immediately; dismissed rows are kept
// only for a day, read rows for 3 days, and never-read rows for at most 14 days.
$pdo->prepare("DELETE FROM push_notifications WHERE expires_at<>'' AND expires_at<=:now")->execute([':now'=>$now]);
$pdo->prepare("DELETE FROM push_notifications WHERE dismissed_at<>'' AND dismissed_at<:cutoff")->execute([':cutoff'=>gmdate('c', time()-86400)]);
$pdo->prepare("DELETE FROM push_notifications WHERE read_at<>'' AND read_at<:cutoff")->execute([':cutoff'=>gmdate('c', time()-3*86400)]);
$pdo->prepare("DELETE FROM push_notifications WHERE read_at='' AND created_at<:cutoff")->execute([':cutoff'=>gmdate('c', time()-14*86400)]);

if ($action === 'read' || $action === 'dismiss') {
    $id = max(0, (int)($data['id'] ?? 0));
    if ($id < 1) respond(['ok'=>false,'code'=>'NOTIFICATION_INVALID','message'=>'notifications.error.copy'],422);
    if ($action === 'read') {
        $st = $pdo->prepare("UPDATE push_notifications SET read_at=:now, delivered_at=CASE WHEN delivered_at='' THEN :now ELSE delivered_at END WHERE id=:id AND device_id=:device AND dismissed_at=''");
    } else {
        $st = $pdo->prepare("UPDATE push_notifications SET dismissed_at=:now, read_at=CASE WHEN read_at='' THEN :now ELSE read_at END, delivered_at=CASE WHEN delivered_at='' THEN :now ELSE delivered_at END WHERE id=:id AND device_id=:device");
    }
    $st->execute([':now'=>$now, ':id'=>$id, ':device'=>$device]);
    respond(['ok'=>true,'updated'=>$st->rowCount()>0]);
}
if ($action === 'read-all') {
    $st = $pdo->prepare("UPDATE push_notifications SET read_at=:now, delivered_at=CASE WHEN delivered_at='' THEN :now ELSE delivered_at END WHERE device_id=:device AND dismissed_at='' AND read_at=''");
    $st->execute([':now'=>$now, ':device'=>$device]);
    respond(['ok'=>true,'updated'=>$st->rowCount()]);
}
if ($action !== 'list') respond(['ok'=>false,'code'=>'NOTIFICATION_ACTION_INVALID','message'=>'notifications.error.copy'],422);

$st = $pdo->prepare("SELECT id,title,body,target_url,tag,created_at,delivered_at,read_at,expires_at
    FROM push_notifications
    WHERE device_id=:device AND dismissed_at='' AND (expires_at='' OR expires_at>:now)
    ORDER BY id DESC LIMIT 40");
$st->execute([':device'=>$device, ':now'=>$now]);
$rows = [];
$unread = 0;
foreach ($st->fetchAll() as $row) {
    $read = trim((string)($row['read_at'] ?? '')) !== '';
    if (!$read) $unread++;
    $url = trim((string)($row['target_url'] ?? './#notifications'));
    if (!str_starts_with($url, './#') && !str_starts_with($url, '/#')) $url = './#notifications';
    $rows[] = [
        'id'=>(int)$row['id'], 'title'=>(string)$row['title'], 'body'=>(string)$row['body'],
        'url'=>$url, 'tag'=>(string)$row['tag'], 'createdAt'=>(string)$row['created_at'],
        'deliveredAt'=>(string)$row['delivered_at'], 'readAt'=>(string)$row['read_at'],
        'expiresAt'=>(string)$row['expires_at'], 'read'=>$read,
    ];
}
respond(['ok'=>true,'rows'=>$rows,'unreadCount'=>$unread,'retention'=>['readDays'=>3,'unreadDays'=>14]]);
