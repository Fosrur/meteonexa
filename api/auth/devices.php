<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/auth_session.php';

assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$deviceId = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
$session = require_authenticated_device_session($pdo, $config, $deviceId);
require_device_rate_limit($pdo, 'auth_devices_device', $deviceId, $method === 'GET' ? 180 : 40, 3600);
require_ip_rate_limit($pdo, 'auth_devices_ip', $method === 'GET' ? 300 : 80, 3600);

$email = strtolower(trim((string)($session['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['ok'=>false,'code'=>'AUTH_REQUIRED','message'=>'api.security.auth_required'],401);
}
$secret = auth_secret($config);
$emailHash = meteonexa_hmac_identifier('session-email:' . $email, $secret);
$trustedEmailHash = meteonexa_hmac_identifier('trusted-email:' . $email, $secret);
$currentSessionHash = (string)($session['session_hash'] ?? '');
$now = time();

$presentRow = static function(array $row) use ($pdo,$config,$currentSessionHash): array {
    $ip = '';
    try { $ip = meteonexa_decrypt_value((string)($row['ip_encrypted'] ?? ''), $config, 'auth-access-ip'); }
    catch (Throwable $ignored) { $ip = ''; }
    $endedAt = (int)($row['ended_at'] ?? 0);
    $sessionHash = (string)($row['session_hash'] ?? '');
    $active = false;
    if ($endedAt === 0 && $sessionHash !== '') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM auth_sessions WHERE session_hash=:session');
        $st->execute([':session'=>$sessionHash]);
        $active = (int)$st->fetchColumn() > 0;
    }
    return [
        'id'=>(int)($row['id'] ?? 0),
        'deviceId'=>(string)($row['device_id'] ?? ''),
        'deviceType'=>(string)($row['device_type'] ?? 'Web'),
        'platform'=>(string)($row['platform'] ?? ''),
        'browser'=>(string)($row['browser'] ?? ''),
        'clientMode'=>(string)($row['client_mode'] ?? 'web'),
        'timezone'=>(string)($row['timezone'] ?? ''),
        'ip'=>$ip,
        'location'=>(string)($row['location_label'] ?? ''),
        'createdAt'=>(int)($row['created_at'] ?? 0),
        'lastSeenAt'=>(int)($row['last_seen_at'] ?? 0),
        'endedAt'=>$endedAt,
        'endReason'=>(string)($row['end_reason'] ?? ''),
        'active'=>$active,
        'current'=>$sessionHash !== '' && hash_equals($currentSessionHash, $sessionHash),
    ];
};

if ($method === 'GET') {
    // Refresh the current device's approximate location when the browser has
    // granted geolocation permission. The label is already coarse and contains
    // no precise coordinate; retaining it follows the same 30-day access history.
    $freshLocation = meteonexa_auth_access_location_label();
    if ($freshLocation !== '' && $currentSessionHash !== '') {
        $pdo->prepare('UPDATE auth_access_history SET location_label=:location WHERE session_hash=:session AND email_hash=:email')
            ->execute([':location'=>$freshLocation,':session'=>$currentSessionHash,':email'=>$emailHash]);
    }
    // Backfill sessions that were already active before schema 19. The current
    // browser can be described from this request; older remote sessions remain
    // intentionally generic because MeteoNexa did not previously retain UA/IP data.
    $activeRows = $pdo->prepare('SELECT session_hash,device_id,created_at,last_seen_at FROM auth_sessions WHERE email_hash=:email');
    $activeRows->execute([':email'=>$emailHash]);
    foreach ($activeRows->fetchAll() ?: [] as $activeRow) {
        if (!is_array($activeRow)) continue;
        $hash=(string)($activeRow['session_hash'] ?? '');
        if ($hash==='') continue;
        $exists=$pdo->prepare('SELECT COUNT(*) FROM auth_access_history WHERE session_hash=:session');
        $exists->execute([':session'=>$hash]);
        if ((int)$exists->fetchColumn()>0) continue;
        if (hash_equals($currentSessionHash,$hash)) {
            meteonexa_auth_access_record($pdo,$config,$hash,$emailHash,(string)$activeRow['device_id'],(int)$activeRow['created_at']);
            $pdo->prepare('UPDATE auth_access_history SET last_seen_at=:seen WHERE session_hash=:session')->execute([':seen'=>(int)$activeRow['last_seen_at'],':session'=>$hash]);
        } else {
            $pdo->prepare("INSERT INTO auth_access_history(session_hash,email_hash,device_id,device_type,platform,browser,client_mode,timezone,ip_encrypted,location_label,created_at,last_seen_at,ended_at,end_reason) VALUES(:session,:email,:device,'Web','','','web','','','',:created,:seen,0,'')")
                ->execute([':session'=>$hash,':email'=>$emailHash,':device'=>(string)$activeRow['device_id'],':created'=>(int)$activeRow['created_at'],':seen'=>(int)$activeRow['last_seen_at']]);
        }
    }
    // Retain a bounded successful-login history. Exact IP is encrypted at rest and
    // returned only to the same authenticated identity.
    $cutoff = $now - 30 * 86400;
    $pdo->prepare('DELETE FROM auth_access_history WHERE email_hash=:email AND ended_at>0 AND last_seen_at<:cutoff')
        ->execute([':email'=>$emailHash,':cutoff'=>$cutoff]);
    $st = $pdo->prepare('SELECT id,session_hash,device_id,device_type,platform,browser,client_mode,timezone,ip_encrypted,location_label,created_at,last_seen_at,ended_at,end_reason FROM auth_access_history WHERE email_hash=:email ORDER BY last_seen_at DESC, id DESC');
    $st->execute([':email'=>$emailHash]);
    $rows = array_slice($st->fetchAll() ?: [], 0, 108);
    $presented = array_map($presentRow, $rows);
    $activeDevices = array_values(array_filter($presented, static fn(array $row): bool => ($row['active'] ?? false) === true));
    $history = array_values(array_filter($presented, static fn(array $row): bool => ($row['active'] ?? false) !== true));
    $history = array_slice($history, 0, 50);

    // "Disconnect other devices" must also cover still-valid trusted-device
    // credentials that currently have no auth_session. Previous builds counted
    // only active sessions, so the control could stay disabled even though a
    // remote browser was still trusted and could sign in again without OTP.
    $revocableDeviceIds = [];
    foreach ($activeDevices as $row) {
        $candidate = (string)($row['deviceId'] ?? '');
        if ($candidate !== '' && $candidate !== $deviceId) $revocableDeviceIds[$candidate] = true;
    }
    $trusted = $pdo->prepare('SELECT DISTINCT device_id FROM trusted_devices WHERE email_hash=:email AND device_id<>:current AND expires_at>:now');
    $trusted->execute([':email'=>$trustedEmailHash,':current'=>$deviceId,':now'=>$now]);
    foreach ($trusted->fetchAll(PDO::FETCH_COLUMN) ?: [] as $candidate) {
        $candidate = (string)$candidate;
        if ($candidate !== '') $revocableDeviceIds[$candidate] = true;
    }
    $revocableOthersCount = count($revocableDeviceIds);
    respond([
        'ok'=>true,
        'devices'=>array_merge($activeDevices, $history), // compatibility with Verified Trust clients
        'activeDevices'=>$activeDevices,
        'history'=>$history,
        'retentionDays'=>30,
        'revocableOthersCount'=>$revocableOthersCount,
        'locationSource'=>'server-geo-headers-or-browser-rounded'
    ]);
}

require_method('POST');
$data = input_json();
$action = (string)($data['action'] ?? '');
if (!in_array($action, ['revoke','revokeOthers','clearHistory','deleteHistory'], true)) {
    respond(['ok'=>false,'code'=>'INVALID_ACTION','message'=>'api.backend.invalid_request'],422);
}

if ($action === 'deleteHistory') {
    $accessId=(int)($data['accessId']??0);
    if($accessId<1)respond(['ok'=>false,'code'=>'INVALID_ACCESS','message'=>'api.backend.invalid_request'],422);
    $lookup=$pdo->prepare('SELECT session_hash FROM auth_access_history WHERE id=:id AND email_hash=:email LIMIT 1');
    $lookup->execute([':id'=>$accessId,':email'=>$emailHash]);$historyHash=(string)($lookup->fetchColumn()?:'');
    if($historyHash==='')respond(['ok'=>false,'code'=>'ACCESS_NOT_FOUND','message'=>'api.backend.not_found'],404);
    $activeCheck=$pdo->prepare('SELECT COUNT(*) FROM auth_sessions WHERE session_hash=:session AND email_hash=:email');
    $activeCheck->execute([':session'=>$historyHash,':email'=>$emailHash]);
    if((int)$activeCheck->fetchColumn()>0)respond(['ok'=>false,'code'=>'ACCESS_ACTIVE','message'=>'api.backend.invalid_request'],409);
    $delete=$pdo->prepare('DELETE FROM auth_access_history WHERE id=:id AND email_hash=:email');
    $delete->execute([':id'=>$accessId,':email'=>$emailHash]);
    respond(['ok'=>true,'deleted'=>$delete->rowCount(),'retentionDays'=>30]);
}

if ($action === 'clearHistory') {
    // Delete only ended/no-longer-active audit rows for this identity. Active
    // sessions remain untouched even if their audit row is old. The portable
    // NOT IN form works on both SQLite and MySQL/MariaDB.
    $active = $pdo->prepare('SELECT session_hash FROM auth_sessions WHERE email_hash=:email');
    $active->execute([':email'=>$emailHash]);
    $activeHashes = array_values(array_filter(array_map('strval', $active->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    $params = [':email'=>$emailHash];
    if ($activeHashes) {
        $placeholders = [];
        foreach ($activeHashes as $i => $hash) {
            $key = ':active' . $i;
            $placeholders[] = $key;
            $params[$key] = $hash;
        }
        $sql = 'DELETE FROM auth_access_history WHERE email_hash=:email AND session_hash NOT IN (' . implode(',', $placeholders) . ')';
    } else {
        $sql = 'DELETE FROM auth_access_history WHERE email_hash=:email';
    }
    $delete = $pdo->prepare($sql);
    $delete->execute($params);
    respond(['ok'=>true,'deleted'=>$delete->rowCount(),'retentionDays'=>30]);
}

if ($action === 'revokeOthers') {
    $st = $pdo->prepare('SELECT session_hash,device_id FROM auth_sessions WHERE email_hash=:email AND session_hash<>:current');
    $st->execute([':email'=>$emailHash,':current'=>$currentSessionHash]);
    $rows = $st->fetchAll() ?: [];
    $revokedDeviceIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $hash = (string)($row['session_hash'] ?? '');
        $targetDevice = (string)($row['device_id'] ?? '');
        if ($targetDevice !== '' && $targetDevice !== $deviceId) $revokedDeviceIds[$targetDevice] = true;
        if ($hash !== '') meteonexa_auth_access_mark_ended($pdo, $hash, 'remote_revoked');
        if ($hash !== '') $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$hash]);
    }

    // Revoke dormant trusted-device credentials as well. Keep credentials for
    // the current physical device so "disconnect others" never signs out the
    // browser that issued the command.
    $trustedRows = $pdo->prepare('SELECT DISTINCT device_id FROM trusted_devices WHERE email_hash=:email AND device_id<>:current AND expires_at>:now');
    $trustedRows->execute([':email'=>$trustedEmailHash,':current'=>$deviceId,':now'=>$now]);
    foreach ($trustedRows->fetchAll(PDO::FETCH_COLUMN) ?: [] as $trustedDevice) {
        $trustedDevice=(string)$trustedDevice;
        if($trustedDevice!=='')$revokedDeviceIds[$trustedDevice]=true;
    }
    $trustedDelete = $pdo->prepare('DELETE FROM trusted_devices WHERE email_hash=:email AND device_id<>:current');
    $trustedDelete->execute([':email'=>$trustedEmailHash,':current'=>$deviceId]);

    respond([
        'ok'=>true,
        'revoked'=>count($revokedDeviceIds),
        'revokedSessions'=>count($rows),
        'revokedTrustedCredentials'=>$trustedDelete->rowCount(),
        'currentRevoked'=>false
    ]);
}

$accessId = (int)($data['accessId'] ?? 0);
if ($accessId < 1) respond(['ok'=>false,'code'=>'INVALID_ACCESS','message'=>'api.backend.invalid_request'],422);
$st = $pdo->prepare('SELECT id,session_hash,device_id,ended_at FROM auth_access_history WHERE id=:id AND email_hash=:email LIMIT 1');
$st->execute([':id'=>$accessId,':email'=>$emailHash]);
$row = $st->fetch();
if (!is_array($row)) respond(['ok'=>false,'code'=>'ACCESS_NOT_FOUND','message'=>'api.backend.not_found'],404);
$targetSession = (string)($row['session_hash'] ?? '');
$targetDevice = (string)($row['device_id'] ?? '');
$currentRevoked = $targetSession !== '' && hash_equals($currentSessionHash, $targetSession);
if ($targetSession !== '') {
    meteonexa_auth_access_mark_ended($pdo, $targetSession, $currentRevoked ? 'self_revoked' : 'remote_revoked');
    $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$targetSession]);
}
if ($targetDevice !== '') {
    $pdo->prepare('DELETE FROM trusted_devices WHERE email_hash=:email AND device_id=:device')->execute([':email'=>$trustedEmailHash,':device'=>$targetDevice]);
}
if ($currentRevoked) {
    meteonexa_clear_auth_cookie($config);
    meteonexa_clear_trusted_device_cookie($config);
}
respond(['ok'=>true,'revoked'=>1,'currentRevoked'=>$currentRevoked]);
