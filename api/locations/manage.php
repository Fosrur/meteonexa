<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/account/account_helpers.php';
assert_same_origin();
require_method('GET', 'POST');
$config = load_config();
$pdo = meteonexa_db($config);
function meteonexa_location_key(string $label) : string {
    return hash('sha256', function_exists('mb_strtolower') ? mb_strtolower(trim($label), 'UTF-8') : strtolower(trim($label)));
}
function meteonexa_location_payload(array $r) : ? array {
    $label = clean_text($r['label']??'', 80, '');
    $name = clean_text($r['name']??$r['location_name']??'', 191, '');
    $lat = $r['latitude']??null;
    $lon = $r['longitude']??null;
    if ($label===''||$name===''||!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat) > 90||abs((float)$lon) > 180)return null;
    $role = strtolower(clean_text($r['role']??'custom', 24, 'custom'));
    if (!in_array($role,['home', 'work', 'family', 'second_home', 'custom'], true))$role = 'custom';
    return['role'=>$role, 'label'=>$label, 'name'=>$name, 'admin1'=>clean_text($r['admin1']??'', 191, ''), 'latitude'=>round((float)$lat, 5), 'longitude'=>round((float)$lon, 5), 'timezone'=>clean_text($r['timezone']??'auto', 80, 'auto')];
}
function meteonexa_location_mirror(PDO $pdo, string $device, array $rows) : void {
    $pdo->prepare('UPDATE saved_locations SET active=0,updated_at=:u WHERE device_id=:d')->execute([':u'=>gmdate('c'), ':d'=>$device]);
    foreach ($rows as $entry) {
        $p = meteonexa_location_payload((array)($entry['payload']??[]));
        if (!$p)continue;
        $now = gmdate('c');
        $st = $pdo->prepare('INSERT INTO saved_locations(device_id,role,label,location_name,admin1,latitude,longitude,timezone,active,created_at,updated_at) VALUES(:d,:r,:l,:n,:a,:lat,:lon,:tz,1,:u,:u) ON CONFLICT(device_id,label) DO UPDATE SET role=excluded.role,location_name=excluded.location_name,admin1=excluded.admin1,latitude=excluded.latitude,longitude=excluded.longitude,timezone=excluded.timezone,active=1,updated_at=excluded.updated_at');
        $st->execute([':d'=>$device, ':r'=>$p['role'], ':l'=>$p['label'], ':n'=>$p['name'], ':a'=>$p['admin1'], ':lat'=>$p['latitude'], ':lon'=>$p['longitude'], ':tz'=>$p['timezone'], ':u'=>$now]);
    }
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    $device = clean_device_id($_GET['deviceId']??'');
    $session = require_authenticated_device_session($pdo, $config, $device);
    require_device_rate_limit($pdo, 'saved_locations_read', $device, 180, 3600);
    $a = meteonexa_account_sync_hash($config, $session);
    $rows = meteonexa_account_sync_list($pdo, $a, 'location');
    if (!$rows) {
        $legacy = $pdo->prepare('SELECT role,label,location_name,admin1,latitude,longitude,timezone FROM saved_locations WHERE device_id=:d AND active=1 ORDER BY updated_at DESC');
        $legacy->execute([':d'=>$device]);
        foreach ($legacy->fetchAll() as $r) {
            $p = meteonexa_location_payload($r);
            if ($p)meteonexa_account_sync_upsert($pdo, $a, 'location', meteonexa_location_key($p['label']), $p, $device);
        }
        $rows = meteonexa_account_sync_list($pdo, $a, 'location');
    }
    meteonexa_location_mirror($pdo, $device, $rows);
    $st = $pdo->prepare("SELECT id,role,label,location_name,admin1,latitude,longitude,timezone,active,updated_at FROM saved_locations WHERE device_id=:d AND active=1 ORDER BY CASE role WHEN 'home' THEN 1 WHEN 'work' THEN 2 WHEN 'family' THEN 3 WHEN 'second_home' THEN 4 ELSE 5 END,updated_at DESC");
    $st->execute([':d'=>$device]);
    respond(['ok'=>true, 'rows'=>$st->fetchAll(), 'maxLocations'=>8, 'syncScope'=>'authenticated-account']);
}
$data = input_json();
$device = clean_device_id($data['deviceId']??'');
$session = require_authenticated_device_session($pdo, $config, $device);
require_device_rate_limit($pdo, 'saved_locations_write', $device, 60, 3600);
$a = meteonexa_account_sync_hash($config, $session);
$action = strtolower(clean_text($data['action']??'save', 16, 'save'));
if ($action==='delete') {
    $id = (int)($data['id']??0);
    $st = $pdo->prepare('SELECT label FROM saved_locations WHERE id=:id AND device_id=:d');
    $st->execute([':id'=>$id, ':d'=>$device]);
    $label = (string)($st->fetchColumn() ? : '');
    if ($label!=='')meteonexa_account_sync_upsert($pdo, $a, 'location', meteonexa_location_key($label),['label'=>$label], $device, true);
    $pdo->prepare('DELETE FROM saved_locations WHERE id=:id AND device_id=:d')->execute([':id'=>$id, ':d'=>$device]);
    respond(['ok'=>true, 'deleted'=>$label!=='']);
}
$p = meteonexa_location_payload($data);
if (!$p)respond(['ok'=>false, 'code'=>'LOCATION_INVALID', 'message'=>'api.locations.invalid'], 422);
$rows = meteonexa_account_sync_list($pdo, $a, 'location');
$key = meteonexa_location_key($p['label']);
$exists = false;
foreach ($rows as $r) if (($r['itemKey']??'')===$key)$exists = true;
if (!$exists&&count($rows)>=8)respond(['ok'=>false, 'code'=>'LOCATION_LIMIT', 'message'=>'api.locations.limit'], 422);
$rev = meteonexa_account_sync_upsert($pdo, $a, 'location', $key, $p, $device);
meteonexa_location_mirror($pdo, $device, meteonexa_account_sync_list($pdo, $a, 'location'));
respond(['ok'=>true, 'revision'=>$rev, 'syncScope'=>'authenticated-account']);
