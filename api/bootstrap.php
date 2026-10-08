<?php
declare(strict_types=1);



require_once __DIR__ . '/bootstrap/component-01.php';
require_once __DIR__ . '/bootstrap/component-02.php';
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
umask(0007);

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/backend_i18n.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}







meteonexa_request_id();
















set_exception_handler(static function (Throwable $error): void {
    meteonexa_log_event('unhandled_exception', $error);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    http_response_code(500);
    $message = meteonexa_backend_text('api.backend.internal_error');
    echo json_encode(
        ['ok' => false, 'code' => 'INTERNAL_ERROR', 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
});
