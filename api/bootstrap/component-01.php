<?php
declare(strict_types=1);
function meteonexa_request_id(): string
{
    static $id = null;
    if (is_string($id) && $id !== '') return $id;
    try { $id = bin2hex(random_bytes(12)); }
    catch (Throwable $ignored) { $id = substr(hash('sha256', microtime(true).'|'.getmypid()), 0, 24); }
    if (!headers_sent()) header('X-Request-ID: ' . $id);
    return $id;
}

function respond(array $payload, int $status = 200): never
{
    if (isset($payload['message']) && is_string($payload['message'])) {
        $payload['message'] = meteonexa_backend_text($payload['message']);
    }
    if (!array_key_exists('requestId', $payload)) $payload['requestId'] = meteonexa_request_id();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function storage_dir(): string
{
    try {
        $dir = meteonexa_storage_path();
        meteonexa_migrate_legacy_storage($dir);
    } catch (Throwable $error) {
        $code = $error->getMessage() === 'LEGACY_STORAGE_CONFLICT' ? 'STORAGE_MIGRATION_CONFLICT' : 'STORAGE_UNAVAILABLE';
        respond(['ok' => false, 'code' => $code, 'message' => 'api.bootstrap.storage_unavailable'], 500);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        respond(['ok' => false, 'code' => 'STORAGE_UNAVAILABLE', 'message' => 'api.bootstrap.storage_unavailable'], 500);
    }
    @chmod($dir, 0770);
    if (!is_writable($dir)) {
        respond(['ok' => false, 'code' => 'STORAGE_NOT_WRITABLE', 'message' => 'api.bootstrap.storage_not_writable'], 500);
    }
    return $dir;
}

function meteonexa_log_event(string $event, ?Throwable $error = null): void
{
    $event = preg_replace('/[^a-zA-Z0-9._-]/', '_', $event) ?: 'unknown';
    $suffix = $error ? ' exception=' . get_class($error) : '';
    error_log('[MeteoNexa] request_id=' . meteonexa_request_id() . ' event=' . $event . $suffix);
}

function meteonexa_atomic_write(string $path, string $contents, int $mode = 0660): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return false;
    try {
        $temp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    } catch (Throwable $error) {
        return false;
    }
    if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
        @unlink($temp);
        return false;
    }
    @chmod($temp, $mode);
    if (!@rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    @chmod($path, $mode);
    return true;
}

function meteonexa_acquire_lock(string $name, bool $blocking = false)
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $name) ?: 'job';
    $dir = storage_dir() . '/locks';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return null;
    $path = $dir . '/' . $safe . '.lock';
    $handle = @fopen($path, 'c');
    if (!is_resource($handle)) return null;
    $operation = LOCK_EX | ($blocking ? 0 : LOCK_NB);
    if (!@flock($handle, $operation)) {
        fclose($handle);
        return null;
    }
    @chmod($path, 0600);
    return $handle;
}

function meteonexa_release_lock($handle): void
{
    if (!is_resource($handle)) return;
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function meteonexa_secret_file(): string
{
    return meteonexa_storage_path() . '/.app-secret';
}

function meteonexa_resolve_app_secret(): string
{
    $environment = trim((string)(getenv('METEONEXA_APP_SECRET') ?: ''));
    if (strlen($environment) >= 32) return $environment;

    $path = meteonexa_secret_file();
    $dir = storage_dir();
    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        respond(['ok' => false, 'code' => 'APP_SECRET_MISSING', 'message' => 'api.backend.app_secret_missing'], 503);
    }
    try {
        if (is_file($path)) {
            $stored = trim((string)@file_get_contents($path));
            if (strlen($stored) >= 32) {
                @chmod($path, 0600);
                return $stored;
            }
        }

        
        
        
        
        
        $databasePath = $dir . '/meteonexa.sqlite';
        if (is_file($databasePath) && (int)@filesize($databasePath) > 0) {
            $unboundBaseline = false;
            try {
                $probe = new PDO('sqlite:' . $databasePath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 2,
                ]);
                $probe->exec('PRAGMA query_only = ON');
                $marker = (string)($probe->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='deployment_unbound'), '')")->fetchColumn() ?: '');
                $verifier = (string)($probe->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='secret_verifier'), '')")->fetchColumn() ?: '');
                $smtpProtected = (int)$probe->query("SELECT COUNT(*) FROM smtp_settings WHERE COALESCE(password_encrypted,'') <> ''")->fetchColumn();
                $netatmoProtected = (int)$probe->query("SELECT COUNT(*) FROM netatmo_accounts WHERE COALESCE(access_token_enc,'') <> '' OR COALESCE(refresh_token_enc,'') <> ''")->fetchColumn();
                $authProtected = (int)$probe->query('SELECT COUNT(*) FROM auth_sessions')->fetchColumn();
                $unboundBaseline = $marker === '1' && $verifier === '' && $smtpProtected === 0 && $netatmoProtected === 0 && $authProtected === 0;
                $probe = null;
            } catch (Throwable $ignored) {
                $unboundBaseline = false;
            }
            if (!$unboundBaseline) {
                respond(['ok' => false, 'code' => 'APP_SECRET_MISSING', 'message' => 'api.backend.app_secret_missing'], 503);
            }
        }

        
        $secret = bin2hex(random_bytes(32));
        $temp = $dir . '/.app-secret.' . bin2hex(random_bytes(5)) . '.tmp';
        if (@file_put_contents($temp, $secret . "\n", LOCK_EX) === false || !@rename($temp, $path)) {
            @unlink($temp);
            respond(['ok' => false, 'code' => 'APP_SECRET_MISSING', 'message' => 'api.backend.app_secret_missing'], 503);
        }
        @chmod($path, 0600);
        return $secret;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @chmod($lockPath, 0600);
    }
}

function load_config(): array
{
    static $loaded = null;
    if (is_array($loaded)) return $loaded;

    $customPath = trim((string)(getenv('METEONEXA_CONFIG_PATH') ?: ''));
    $path = $customPath !== '' ? $customPath : __DIR__ . '/config.php';
    if (!is_file($path)) {
        respond(['ok' => false, 'code' => 'CONFIG_MISSING', 'message' => 'api.bootstrap.config_missing'], 500);
    }
    $config = require_once $path;
    if (!is_array($config)) {
        respond(['ok' => false, 'code' => 'CONFIG_INVALID', 'message' => 'api.bootstrap.config_invalid'], 500);
    }

    $config['auth'] = (array)($config['auth'] ?? []);
    $config['auth']['app_secret'] = meteonexa_resolve_app_secret();

    try {
        require_once __DIR__ . '/database.php';
        $databaseSmtp = [];
        try {
            $databaseSmtp = meteonexa_load_smtp($config);
        } catch (Throwable $smtpLoadError) {
            
            
            
            meteonexa_log_event('smtp_load_recoverable', $smtpLoadError);
        }
        $databaseSmtpComplete = $databaseSmtp !== []
            && trim((string)($databaseSmtp['host'] ?? '')) !== ''
            && trim((string)($databaseSmtp['from_email'] ?? '')) !== ''
            && (trim((string)($databaseSmtp['username'] ?? '')) === '' || (string)($databaseSmtp['password'] ?? '') !== '');
        if (!$databaseSmtpComplete) {
            try {
                if (meteonexa_provision_smtp_if_missing($config)) {
                    $databaseSmtp = meteonexa_load_smtp($config);
                }
            } catch (Throwable $smtpProvisionError) {
                
                meteonexa_log_event('smtp_provision_failed', $smtpProvisionError);
            }
        }
        if ($databaseSmtp !== []) {
            $config['smtp'] = array_replace((array)($config['smtp'] ?? []), $databaseSmtp);
        }

        
        
        
        try {
            if (!meteonexa_ai_row_is_usable(meteonexa_db($config), $config)) {
                meteonexa_provision_ai_if_missing($config);
            } else {
                
                meteonexa_provision_ai_if_missing($config);
            }
            $databaseAi = meteonexa_load_ai($config);
            if ($databaseAi !== []) {
                $config['ai'] = array_replace((array)($config['ai'] ?? []), $databaseAi);
            }
        } catch (Throwable $aiLoadError) {
            meteonexa_log_event('ai_load_recoverable', $aiLoadError);
        }
        $config['database_available'] = true;
    } catch (Throwable $error) {
        meteonexa_log_event('database_bootstrap_failed', $error);
        $config['database_available'] = false;
    }
    $loaded = $config;
    return $config;
}

function input_json(): array
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        respond(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'api.backend.method_not_allowed'], 405);
    }

    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if ($contentType === '' || !str_starts_with($contentType, 'application/json')) {
        respond(['ok' => false, 'code' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'api.security.content_type'], 415);
    }

    $configuredMax = 262144;
    try {
        $runtimeConfig = load_config();
        $configuredMax = (int)($runtimeConfig['request']['max_json_bytes'] ?? $configuredMax);
    } catch (Throwable $ignored) {
        
    }
    $environmentMax = trim((string)(getenv('METEONEXA_MAX_JSON_BYTES') ?: ''));
    $maxBytes = $environmentMax !== '' ? (int)$environmentMax : $configuredMax;
    $maxBytes = max(16384, min(1048576, $maxBytes));
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength !== false && $contentLength !== null && $contentLength > $maxBytes) {
        respond(['ok' => false, 'code' => 'PAYLOAD_TOO_LARGE', 'message' => 'api.security.payload_too_large'], 413);
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maxBytes) {
        respond(['ok' => false, 'code' => 'PAYLOAD_TOO_LARGE', 'message' => 'api.security.payload_too_large'], 413);
    }
    $data = json_decode($raw, true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($data)) {
        respond(['ok' => false, 'code' => 'INVALID_JSON', 'message' => 'api.backend.invalid_json'], 400);
    }
    $GLOBALS['meteonexa_request_payload'] = $data;
    return $data;
}

function meteonexa_text_length(string $value): int
{
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    if (function_exists('iconv_strlen')) {
        $length = @iconv_strlen($value, 'UTF-8');
        if ($length !== false) return (int)$length;
    }
    return strlen($value);
}

function meteonexa_text_substr(string $value, int $offset, ?int $length = null): string
{
    if (function_exists('mb_substr')) return $length === null ? mb_substr($value, $offset, null, 'UTF-8') : mb_substr($value, $offset, $length, 'UTF-8');
    if (function_exists('iconv_substr')) {
        $part = $length === null ? @iconv_substr($value, $offset, 2147483647, 'UTF-8') : @iconv_substr($value, $offset, $length, 'UTF-8');
        if ($part !== false) return (string)$part;
    }
    return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
}

function normalize_email(mixed $value): string
{
    return strtolower(trim((string)$value));
}

function client_ip(): string
{
    
    
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
}

function meteonexa_request_origin(): array
{
    
    
    
    
    
    $hostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = '';
    $hostPort = null;
    if ($hostHeader !== '' && preg_match('/[\r\n]/', $hostHeader) !== 1) {
        $candidate = parse_url('http://' . $hostHeader);
        $candidateHost = is_array($candidate) ? (string)($candidate['host'] ?? '') : '';
        if ($candidateHost !== '' && (preg_match('/^[A-Za-z0-9.-]+$/', $candidateHost) === 1 || filter_var($candidateHost, FILTER_VALIDATE_IP) !== false)) {
            $host = strtolower(rtrim($candidateHost, '.'));
            if (isset($candidate['port'])) $hostPort = (int)$candidate['port'];
        }
    }
    if ($host === '') {
        $serverName = strtolower(rtrim(trim((string)($_SERVER['SERVER_NAME'] ?? '')), '.'));
        if ($serverName !== '' && (preg_match('/^[A-Za-z0-9.-]+$/', $serverName) === 1 || filter_var($serverName, FILTER_VALIDATE_IP) !== false))
            $host = $serverName;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    if (in_array($forwardedProto, ['http', 'https'], true)) $scheme = $forwardedProto;
    $forwardedPortRaw = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''))[0] ?? '');
    $forwardedPort = filter_var($forwardedPortRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
    $serverPort = filter_var($_SERVER['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
    $port = $hostPort ?? ($forwardedPort !== false ? (int)$forwardedPort : ($serverPort !== false ? (int)$serverPort : ($scheme === 'https' ? 443 : 80)));
    
    
    
    if ($hostPort === null && $scheme === 'https' && $port === 80) $port = 443;
    if ($hostPort === null && $scheme === 'http' && $port === 443) $port = 80;
    return [$scheme, $host, $port];
}

function meteonexa_expected_origin(): array
{
    $configured = trim((string)(getenv('METEONEXA_BASE_URL') ?: ''));
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) && !empty($parts['host'])) {
            $scheme = strtolower((string)$parts['scheme']);
            $host = strtolower(rtrim((string)$parts['host'], '.'));
            $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
            if (preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return [$scheme, $host, $port];
            }
        }
    }
    return meteonexa_request_origin();
}

function assert_same_origin(): void
{
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite === 'cross-site') {
        respond(['ok' => false, 'code' => 'ORIGIN_DENIED', 'message' => 'api.bootstrap.origin_denied'], 403);
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return;
    $originParts = parse_url($origin);
    if (!is_array($originParts)) respond(['ok' => false, 'code' => 'ORIGIN_DENIED', 'message' => 'api.bootstrap.origin_denied'], 403);
    $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
    $originHost = strtolower(rtrim((string)($originParts['host'] ?? ''), '.'));
    $originPort = isset($originParts['port']) ? (int)$originParts['port'] : ($originScheme === 'https' ? 443 : 80);
    [$requestScheme, $requestHost, $requestPort] = meteonexa_request_origin();
    [$expectedScheme, $expectedHost, $expectedPort] = $requestHost !== ''
        ? [$requestScheme, $requestHost, $requestPort]
        : meteonexa_expected_origin();
    if ($originHost === '' || $expectedHost === '' || !hash_equals($expectedHost, $originHost)
        || $originScheme !== $expectedScheme || $originPort !== $expectedPort) {
        meteonexa_log_event('origin_denied');
        respond(['ok' => false, 'code' => 'ORIGIN_DENIED', 'message' => 'api.bootstrap.origin_denied'], 403);
    }
}

function meteonexa_cookie_secure(array $config = []): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    
    
    
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    if ($forwardedProto === 'https') return true;
    $baseUrl = trim((string)($config['app']['base_url'] ?? ''));
    return $baseUrl !== '' && strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?? '')) === 'https';
}

function meteonexa_app_cookie_path(array $config = []): string
{
    $configuredBase = trim((string)($config['app']['base_url'] ?? ''));
    $baseUrl = $configuredBase !== '' ? $configuredBase : trim((string)(getenv('METEONEXA_BASE_URL') ?: ''));
    if ($baseUrl !== '') {
        $path = (string)(parse_url($baseUrl, PHP_URL_PATH) ?? '');
        if ($path !== '') {
            $path = '/' . trim($path, '/');
            return $path === '/' ? '/' : $path . '/';
        }
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $position = strpos($script, '/api/');
    if ($position !== false) {
        $path = substr($script, 0, $position + 1);
        return $path !== '' ? $path : '/';
    }
    return '/';
}
