<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';

assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$data = input_json();
$device = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? ($data['deviceId'] ?? ''));
require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo, 'ai_rotate_ip', 10, 3600);
require_device_rate_limit($pdo, 'ai_rotate_device', $device, 5, 3600);

$currentKey = trim((string)($data['currentKey'] ?? ''));
$newKey = trim((string)($data['newKey'] ?? ''));
if ($currentKey === '' || $newKey === '' || strlen($currentKey) > 1024 || strlen($newKey) > 1024
    || preg_match('/\s/', $newKey) === 1 || preg_match('/[\x00-\x1F\x7F]/', $newKey) === 1) {
    respond(['ok'=>false, 'code'=>'AI_ROTATION_INVALID'], 422);
}

$row = meteonexa_ai_settings_row($pdo);
if ($row === []) respond(['ok'=>false, 'code'=>'AI_NOT_CONFIGURED'], 404);
try {
    $storedKey = meteonexa_decrypt_value((string)($row['api_key_encrypted'] ?? ''), $config, 'ai-provider');
} catch (Throwable $error) {
    respond(['ok'=>false, 'code'=>'AI_ROTATION_DENIED'], 403);
}
if ($storedKey === '' || !hash_equals($storedKey, $currentKey)) {
    respond(['ok'=>false, 'code'=>'AI_ROTATION_DENIED'], 403);
}

meteonexa_ai_upsert_encrypted($pdo, $config, [
    'provider'=>(string)($row['provider'] ?? 'openrouter'),
    'model'=>(string)($row['model'] ?? 'nvidia/nemotron-3-super-120b-a12b:free'),
    'site_url'=>(string)($row['site_url'] ?? ''),
    'site_name'=>(string)($row['site_name'] ?? 'MeteoNexa'),
], $newKey);

respond(['ok'=>true, 'rotated'=>true]);
