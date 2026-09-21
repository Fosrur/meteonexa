<?php
declare(strict_types=1);

// CSP telemetry endpoint. Reports are intentionally not persisted with request
// query strings or client identifiers: only a compact, sanitized security signal
// is emitted to the server error log. Infrastructure logs may still apply their
// normal request metadata policy.
header('Cache-Control: no-store, max-age=0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 65536) {
    http_response_code(413);
    echo '{"ok":false}';
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($raw) || strlen($raw) > 65536) {
    http_response_code(413);
    echo '{"ok":false}';
    exit;
}

$payload = json_decode($raw, true);
$report = [];
if (is_array($payload)) {
    if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
        $report = $payload[0]['body'] ?? $payload[0];
    } else {
        $report = $payload['csp-report'] ?? $payload['body'] ?? $payload;
    }
}
if (is_array($report)) {
    $clean = static function (mixed $value, int $max = 180): string {
        $value = preg_replace('/[\\r\\n\\t]+/', ' ', (string)$value) ?? '';
        return substr(trim($value), 0, $max);
    };
    $urlPart = static function (mixed $value) use ($clean): string {
        $url = $clean($value, 512);
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($scheme === '' && $host === '') return $clean($path, 180);
        return $clean(($scheme ? $scheme . '://' : '') . $host . $path, 180);
    };
    $signal = [
        'directive' => $clean($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? ''),
        'blocked' => $urlPart($report['blocked-uri'] ?? $report['blockedURL'] ?? ''),
        'document' => $urlPart($report['document-uri'] ?? $report['documentURL'] ?? ''),
        'disposition' => $clean($report['disposition'] ?? ''),
    ];
    if ($signal['directive'] !== '') {
        error_log('METEONEXA_CSP_REPORT ' . json_encode($signal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

http_response_code(204);
