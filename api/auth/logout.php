<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/auth_session.php';
assert_same_origin();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    respond(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'api.backend.method_not_allowed'],405);
}
$config=load_config();
$pdo=meteonexa_db($config);
$data = input_json();
meteonexa_revoke_current_auth_session($pdo,$config);
if (($data['revokeTrustedDevice'] ?? false) === true) {
    meteonexa_revoke_current_trusted_device($pdo,$config);
}
respond(['ok'=>true,'trustedDeviceRevoked'=>(($data['revokeTrustedDevice'] ?? false) === true)]);
