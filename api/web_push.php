<?php
declare(strict_types=1);

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/backend_i18n.php';

require_once __DIR__ . '/http_helpers.php';
require_once __DIR__ . '/public_helpers.php';

function meteonexa_vapid_file(): string
{
    return meteonexa_storage_path() . '/vapid.json';
}

function meteonexa_vapid_keys(): array
{
    $path = meteonexa_vapid_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('VAPID_STORAGE_UNAVAILABLE');
    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('VAPID_LOCK_FAILED');
    }
    try {
        if (is_file($path)) {
            $data = json_decode((string)@file_get_contents($path), true);
            if (is_array($data) && !empty($data['private_pem']) && !empty($data['public_key'])) return $data;
        }
        if (!function_exists('openssl_pkey_new')) throw new RuntimeException('OPENSSL_REQUIRED');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) throw new RuntimeException('VAPID_KEY_GENERATION_FAILED');
        $privatePem = '';
        if (!openssl_pkey_export($key, $privatePem)) throw new RuntimeException('VAPID_KEY_EXPORT_FAILED');
        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!is_string($x) || !is_string($y)) throw new RuntimeException(meteonexa_backend_text('api.backend.vapid_ec_invalid'));
        $data = [
            'private_pem' => $privatePem,
            'public_key' => meteonexa_base64url_encode("\x04" . $x . $y),
            'created_at' => gmdate('c'),
        ];
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new RuntimeException('VAPID_KEY_STORAGE_FAILED');
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $payload, LOCK_EX) === false || !@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('VAPID_KEY_STORAGE_FAILED');
        }
        @chmod($path, 0600);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @chmod($lockPath, 0600);
    }
}

function meteonexa_der_to_jose(string $der, int $partLength = 32): string
{
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) throw new RuntimeException(meteonexa_backend_text('api.backend.ecdsa_der_invalid'));
    $length = ord($der[$offset++]);
    if ($length & 0x80) {
        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) $length = ($length << 8) | ord($der[$offset++]);
    }
    if (ord($der[$offset++]) !== 0x02) throw new RuntimeException(meteonexa_backend_text('api.backend.ecdsa_r_invalid'));
    $rLength = ord($der[$offset++]);
    $r = substr($der, $offset, $rLength); $offset += $rLength;
    if (ord($der[$offset++]) !== 0x02) throw new RuntimeException(meteonexa_backend_text('api.backend.ecdsa_s_invalid'));
    $sLength = ord($der[$offset++]);
    $s = substr($der, $offset, $sLength);
    $r = str_pad(ltrim($r, "\x00"), $partLength, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), $partLength, "\x00", STR_PAD_LEFT);
    return substr($r, -$partLength) . substr($s, -$partLength);
}

function meteonexa_vapid_jwt(string $endpoint, array $config): array
{
    $keys = meteonexa_vapid_keys();
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) throw new RuntimeException(meteonexa_backend_text('api.backend.push_endpoint_invalid'));
    $audience = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
    $subject = trim((string)($config['push']['subject'] ?? ''));
    if ($subject === '') {
        $base = trim((string)($config['app']['base_url'] ?? ''));
        $subject = str_starts_with(strtolower($base), 'https://') ? $base : 'mailto:meteonexa@localhost.invalid';
    }
    $header = meteonexa_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $payload = meteonexa_base64url_encode(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    $input = $header . '.' . $payload;
    $signature = '';
    if (!openssl_sign($input, $signature, $keys['private_pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException(meteonexa_backend_text('api.backend.vapid_sign_failed'));
    return ['token' => $input . '.' . meteonexa_base64url_encode(meteonexa_der_to_jose($signature)), 'public_key' => $keys['public_key']];
}

function meteonexa_send_empty_push(string $endpoint, array $config, int $ttl = 300): array
{
    $endpoint = meteonexa_validate_push_endpoint($endpoint, (array)($config['push']['allowed_endpoint_hosts'] ?? []));
    $vapid = meteonexa_vapid_jwt($endpoint, $config);
    $response = meteonexa_http_request($endpoint, [
        'method' => 'POST',
        'timeout' => 20,
        'headers' => [
            'Authorization: vapid t=' . $vapid['token'] . ', k=' . $vapid['public_key'],
            'Crypto-Key: p256ecdsa=' . $vapid['public_key'],
            'TTL: ' . max(0, $ttl),
            'Urgency: high',
            'Content-Length: 0',
        ],
        'body' => '',
        'max_bytes' => 262144,
        'pin_dns' => true,
    ]);
    return ['ok' => in_array((int)$response['status'], [200, 201, 202, 204], true), 'status' => (int)$response['status']];
}
