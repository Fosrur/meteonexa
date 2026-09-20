<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_i18n.php';

function meteonexa_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function meteonexa_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if (!is_string($decoded)) throw new RuntimeException(meteonexa_backend_text('api.backend.base64url_invalid'));
    return $decoded;
}

function meteonexa_http_public_addresses(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
        }
        return [$host];
    }

    $addresses = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') $addresses[] = $ip;
            }
        }
    }
    if ($addresses === [] && function_exists('gethostbynamel')) {
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) $addresses = array_merge($addresses, $ipv4);
    }
    $addresses = array_values(array_unique(array_filter(
        $addresses,
        static fn($ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
    )));
    if ($addresses === []) throw new RuntimeException('REMOTE_DNS_UNAVAILABLE');
    foreach ($addresses as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
        }
    }
    return $addresses;
}

function meteonexa_http_request(string $url, array $options = []): array
{
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1)) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    if ($port !== 443) throw new InvalidArgumentException(meteonexa_backend_text('api.security.remote_url_invalid'));

    $method = strtoupper((string)($options['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) throw new InvalidArgumentException('HTTP_METHOD_INVALID');
    $timeout = max(3, min(60, (int)($options['timeout'] ?? 20)));
    $headers = array_values(array_filter((array)($options['headers'] ?? []), 'is_string'));
    foreach ($headers as $header) {
        if ($header === '' || preg_match('/[\r\n\x00]/', $header) === 1) throw new InvalidArgumentException('HTTP_HEADER_INVALID');
    }
    $body = $options['body'] ?? null;
    $maxBytes = max(65536, min(16777216, (int)($options['max_bytes'] ?? 8388608)));
    $pinDns = !empty($options['pin_dns']);
    $resolvedAddresses = $pinDns ? meteonexa_http_public_addresses($host) : [];

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('HTTP_INIT_FAILED');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'MeteoNexa/20.1',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($handle, CURLOPT_NOPROGRESS, false);
            curl_setopt($handle, CURLOPT_XFERINFOFUNCTION, static function($resource, float $downloadTotal, float $downloadNow) use ($maxBytes): int {
                return $downloadNow > $maxBytes ? 1 : 0;
            });
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        if ($pinDns) {
            if (!defined('CURLOPT_RESOLVE')) {
                curl_close($handle);
                throw new RuntimeException('HTTP_DNS_PINNING_UNAVAILABLE');
            }
            $address = (string)$resolvedAddresses[0];
            if (str_contains($address, ':')) $address = '[' . trim($address, '[]') . ']';
            curl_setopt($handle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $address]);
        }
        if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            // Do not propagate cURL diagnostics: they can contain provider hostnames,
            // proxy details or other deployment information. Callers expose only a
            // stable application error code/message.
            curl_close($handle);
            throw new RuntimeException('HTTP_REQUEST_FAILED');
        }
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $contentType = (string)(curl_getinfo($handle, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream');
        if (max(0, strlen($raw) - $headerSize) > $maxBytes) {
            curl_close($handle);
            throw new RuntimeException('REMOTE_RESPONSE_TOO_LARGE');
        }
        curl_close($handle);
        return [
            'status' => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
            'content_type' => $contentType,
        ];
    }

    if ($pinDns) throw new RuntimeException('HTTP_DNS_PINNING_REQUIRES_CURL');
    $contextHeaders = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $contextHeaders,
            'content' => $body === null ? '' : (string)$body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'user_agent' => 'MeteoNexa/20.1',
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);
    $result = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
    if (!is_string($result)) throw new RuntimeException('REMOTE_SOURCE_UNREACHABLE');
    if (strlen($result) > $maxBytes) throw new RuntimeException('REMOTE_RESPONSE_TOO_LARGE');
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)) $status = (int)$match[1];
    $contentType = 'application/octet-stream';
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Content-Type:') === 0) $contentType = trim(substr($header, 13));
    }
    return ['status' => $status, 'headers' => implode("\r\n", $responseHeaders), 'body' => $result, 'content_type' => $contentType];
}

function meteonexa_http_json(string $url, array $options = []): array
{
    $response = meteonexa_http_request($url, $options);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('REMOTE_SOURCE_HTTP_' . $response['status']);
    }
    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) throw new RuntimeException(meteonexa_backend_text('api.backend.external_data_invalid'));
    return $data;
}

function meteonexa_validate_coordinates(mixed $lat, mixed $lon): array
{
    $latitude = filter_var($lat, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($lon, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        throw new InvalidArgumentException(meteonexa_backend_text('api.backend.invalid_coordinates'));
    }
    return [(float)$latitude, (float)$longitude];
}

function meteonexa_tile_coordinates(float $latitude, float $longitude, int $zoom): array
{
    $latitude = max(-85.05112878, min(85.05112878, $latitude));
    $scale = 2 ** $zoom;
    $x = (int)floor(($longitude + 180.0) / 360.0 * $scale);
    $latRad = deg2rad($latitude);
    $y = (int)floor((1.0 - asinh(tan($latRad)) / M_PI) / 2.0 * $scale);
    return [max(0, min($scale - 1, $x)), max(0, min($scale - 1, $y))];
}
