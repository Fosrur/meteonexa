<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_i18n.php';
require_once __DIR__ . '/http_helpers.php';

function require_method(string ...$allowed): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $allowed);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'api.backend.method_not_allowed'], 405);
    }
}

function query_float(string $key, float $default = 0.0): float
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_FLOAT);
    return $value === false || $value === null ? $default : (float)$value;
}

function query_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function clean_device_id(mixed $value): string
{
    $deviceId = preg_replace('/[^a-zA-Z0-9._-]/', '', trim((string)$value)) ?: '';
    if (strlen($deviceId) < 12 || strlen($deviceId) > 96) {
        respond(['ok' => false, 'code' => 'INVALID_DEVICE', 'message' => 'api.backend.invalid_device'], 422);
    }
    return $deviceId;
}

function clean_text(mixed $value, int $maxLength, string $fallback = ''): string
{
    $text = trim((string)$value);
    // Remove C0/C1 controls while preserving normal whitespace and Unicode text.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $text) ?? '';
    if ($text === '') return $fallback;
    return meteonexa_text_substr($text, 0, max(1, $maxLength));
}

function meteonexa_device_key(): string
{
    $value = trim((string)($_SERVER['HTTP_X_METEONEXA_DEVICE_KEY'] ?? ''));
    if (strlen($value) < 32 || strlen($value) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        respond(['ok'=>false, 'code'=>'DEVICE_KEY_REQUIRED', 'message'=>'api.security.device_key_required'], 401);
    }
    return $value;
}

/**
 * Binds device-scoped server state to a browser-held random key.
 * Existing installations enroll the key on the first request after upgrade.
 */
function require_ip_rate_limit(PDO $pdo, string $scope, int $limit, int $windowSeconds): void
{
    $config = load_config();
    $result = meteonexa_rate_limit($pdo, $scope, client_ip(), auth_secret($config), $limit, $windowSeconds);
    if (!$result['allowed']) {
        $retryAfter = max(1, (int)$result['retryAfter']);
        header('Retry-After: ' . $retryAfter);
        respond(['ok'=>false,'code'=>'RATE_LIMITED','message'=>'api.security.rate_limit','retryAfter'=>$retryAfter],429);
    }
}

function require_global_rate_limit(PDO $pdo, string $scope, int $limit, int $windowSeconds): void
{
    $config = load_config();
    $result = meteonexa_rate_limit($pdo, $scope, 'deployment', auth_secret($config), $limit, $windowSeconds);
    if (!$result['allowed']) {
        $retryAfter = max(1, (int)$result['retryAfter']);
        header('Retry-After: ' . $retryAfter);
        respond(['ok'=>false,'code'=>'RATE_LIMITED','message'=>'api.security.rate_limit','retryAfter'=>$retryAfter],429);
    }
}

function require_device_rate_limit(PDO $pdo, string $scope, string $deviceId, int $limit, int $windowSeconds): void
{
    $config = load_config();
    $result = meteonexa_rate_limit($pdo, $scope, $deviceId, auth_secret($config), $limit, $windowSeconds);
    if (!$result['allowed']) {
        $retryAfter = max(1, (int)$result['retryAfter']);
        header('Retry-After: ' . $retryAfter);
        respond(['ok'=>false,'code'=>'RATE_LIMITED','message'=>'api.security.rate_limit','retryAfter'=>$retryAfter],429);
    }
}

/**
 * Non-enrolling device proof used by server-side auth sessions. Unlike
 * require_device_access(), this never creates a new credential and therefore
 * cannot silently rebind an existing email session.
 */
function meteonexa_verify_device_proof(PDO $pdo, string $expectedDeviceId): bool
{
    $requestId = trim((string)($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? ''));
    if ($requestId === '' || strlen($requestId) < 12 || strlen($requestId) > 96
        || preg_match('/^[a-zA-Z0-9._-]+$/', $requestId) !== 1
        || !hash_equals($expectedDeviceId, $requestId)) {
        return false;
    }
    $key = trim((string)($_SERVER['HTTP_X_METEONEXA_DEVICE_KEY'] ?? ''));
    if (strlen($key) < 32 || strlen($key) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
        return false;
    }
    $statement = $pdo->prepare('SELECT key_hash FROM device_credentials WHERE device_id=:device LIMIT 1');
    $statement->execute([':device'=>$requestId]);
    $stored = $statement->fetchColumn();
    return is_string($stored) && $stored !== '' && hash_equals($stored, hash('sha256', $key));
}


/**
 * Require a real server-side email session bound to the same browser device.
 *
 * This is deliberately non-enrolling: private endpoints must never turn an
 * unauthenticated request into a valid device credential before authentication.
 * A valid auth cookie alone is insufficient; the request must also prove the
 * browser-held device key that was enrolled after OTP verification.
 *
 * @return array<string,mixed> Authenticated session metadata.
 */
function require_authenticated_device_session(PDO $pdo, array $config, string $deviceId): array
{
    require_once __DIR__ . '/auth_session.php';

    $session = meteonexa_current_auth_session($pdo, $config);
    if (!is_array($session)) {
        respond(['ok'=>false, 'code'=>'AUTH_REQUIRED', 'message'=>'api.security.auth_required'], 401);
    }

    $sessionDeviceId = trim((string)($session['device_id'] ?? ''));
    if ($sessionDeviceId === '' || !meteonexa_verify_device_proof($pdo, $sessionDeviceId)) {
        // A copied/stale auth cookie is useless without the browser device proof.
        // Revoke it immediately instead of allowing repeated bearer-only attempts.
        meteonexa_revoke_current_auth_session($pdo, $config);
        respond(['ok'=>false, 'code'=>'AUTH_REQUIRED', 'message'=>'api.security.auth_required'], 401);
    }

    if (!hash_equals($sessionDeviceId, $deviceId)) {
        respond(['ok'=>false, 'code'=>'DEVICE_ACCESS_DENIED', 'message'=>'api.security.device_access_denied'], 403);
    }

    $pdo->prepare(
        'UPDATE device_credentials SET last_seen_at=:now WHERE device_id=:device AND last_seen_at<:cutoff'
    )->execute([
        ':now'=>gmdate('c'),
        ':cutoff'=>gmdate('c', time()-300),
        ':device'=>$sessionDeviceId,
    ]);

    return $session;
}

function require_device_access(PDO $pdo, string $deviceId): void
{
    $key = meteonexa_device_key();
    $keyHash = hash('sha256', $key);
    $statement = $pdo->prepare('SELECT key_hash FROM device_credentials WHERE device_id=:device LIMIT 1');
    $statement->execute([':device'=>$deviceId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        // First enrollment is bounded both per IP and globally. A device row is
        // intentionally never pruned independently from its data, because doing
        // so could let a new key re-enroll an old device id and inherit its state.
        $config = load_config();
        $deviceConfig = (array)($config['device'] ?? []);
        $secret = auth_secret($config);
        $perIp = max(5, min(200, (int)($deviceConfig['max_enrollments_per_ip_hour'] ?? 20)));
        $global = max($perIp, min(5000, (int)($deviceConfig['max_enrollments_global_hour'] ?? 500)));
        $capacity = max(100, min(100000, (int)($deviceConfig['max_credentials'] ?? 10000)));
        $ipRate = meteonexa_rate_limit($pdo, 'device_enroll_ip', client_ip(), $secret, $perIp, 3600);
        $globalRate = meteonexa_rate_limit($pdo, 'device_enroll_global', 'all', $secret, $global, 3600);
        if (!$ipRate['allowed'] || !$globalRate['allowed']) {
            $retryAfter = max(1, (int)$ipRate['retryAfter'], (int)$globalRate['retryAfter']);
            header('Retry-After: ' . $retryAfter);
            respond(['ok'=>false,'code'=>'DEVICE_ENROLLMENT_RATE_LIMIT','message'=>'api.security.rate_limit','retryAfter'=>$retryAfter],429);
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM device_credentials')->fetchColumn() >= $capacity) {
            header('Retry-After: 3600');
            respond(['ok'=>false,'code'=>'DEVICE_ENROLLMENT_CAPACITY','message'=>'api.security.rate_limit','retryAfter'=>3600],429);
        }
        try {
            $insert = $pdo->prepare('INSERT INTO device_credentials(device_id,key_hash,created_at,last_seen_at) VALUES(:device,:hash,:now,:now)');
            $insert->execute([':device'=>$deviceId, ':hash'=>$keyHash, ':now'=>gmdate('c')]);
            return;
        } catch (PDOException $error) {
            // A concurrent first request may have enrolled the device. Re-read below.
            $statement->execute([':device'=>$deviceId]);
            $row = $statement->fetch();
        }
    }
    if (!is_array($row) || !hash_equals((string)$row['key_hash'], $keyHash)) {
        respond(['ok'=>false, 'code'=>'DEVICE_ACCESS_DENIED', 'message'=>'api.security.device_access_denied'], 403);
    }
    $pdo->prepare('UPDATE device_credentials SET last_seen_at=:now WHERE device_id=:device AND last_seen_at<:cutoff')->execute([':now'=>gmdate('c'), ':cutoff'=>gmdate('c', time()-300), ':device'=>$deviceId]);
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371.0088;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

function meteonexa_is_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function meteonexa_validate_remote_url(string $url, array $allowedHosts = []): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ((filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[a-z0-9.-]+$/', $host) !== 1) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    if ($allowedHosts !== []) {
        $normalized = array_map(static fn($value): string => strtolower(rtrim(trim((string)$value), '.')), $allowedHosts);
        if (!in_array($host, $normalized, true)) {
            throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
        }
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false && !meteonexa_is_public_ip($host)) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    return $url;
}

function meteonexa_validate_push_endpoint(string $url, array $allowedPatterns = []): string
{
    $url = meteonexa_validate_remote_url($url);
    $host = strtolower(rtrim((string)parse_url($url, PHP_URL_HOST), '.'));

    if ($allowedPatterns !== []) {
        $allowed = false;
        foreach ($allowedPatterns as $pattern) {
            $pattern = strtolower(rtrim(trim((string)$pattern), '.'));
            if ($pattern === '') continue;
            if (str_starts_with($pattern, '.')) {
                // A leading dot means a true DNS suffix, never a substring.
                $suffix = ltrim($pattern, '.');
                if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                    $allowed = true;
                    break;
                }
            } elseif (hash_equals($pattern, $host)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }

    // Resolve hostname when possible and reject any private/reserved answer. Literal
    // public IPs were already validated above.
    if (filter_var($host, FILTER_VALIDATE_IP) === false && function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records) && $records !== []) {
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '' && !meteonexa_is_public_ip($ip)) {
                    throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
                }
            }
        }
    }
    return $url;
}


function meteonexa_sign_resource(array $config, string $purpose, string $value, int $expiresAt): string
{
    $secret = auth_secret($config);
    return hash_hmac('sha256', $purpose . '|' . $value . '|' . $expiresAt, $secret);
}

function meteonexa_verify_resource_signature(array $config, string $purpose, string $value, int $expiresAt, string $signature): bool
{
    if ($expiresAt < time() || $expiresAt > time() + 86400 || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) return false;
    return hash_equals(meteonexa_sign_resource($config, $purpose, $value, $expiresAt), $signature);
}

function http_json(string $url, int $timeoutSeconds = 12, array $headers = []): array
{
    meteonexa_validate_remote_url($url);
    return meteonexa_http_json($url, [
        'timeout'=>$timeoutSeconds,
        'headers'=>array_merge(['Accept: application/json'], $headers),
    ]);
}
