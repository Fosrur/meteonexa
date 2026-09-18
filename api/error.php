<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$rawCode = $_GET['code'] ?? 404;
$requested = filter_var($rawCode, FILTER_VALIDATE_INT);
$status = is_int($requested) && in_array($requested,[400,401,403,404,405,408,413,415,422,429,500,502,503,504],true) ? $requested : 404;
$key = match($status) {
    400 => 'api.backend.bad_request',
    401 => 'api.security.auth_required',
    403 => 'api.security.device_access_denied',
    404 => 'api.backend.not_found',
    405 => 'api.backend.method_not_allowed',
    429 => 'api.security.rate_limit',
    default => 'api.backend.internal_error',
};
respond(['ok'=>false,'code'=>'HTTP_'.$status,'message'=>$key],$status);
