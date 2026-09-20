<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/backend_i18n.php';
function netatmo_origin_url(string $value) : string {
    $value = trim($value);
    $parts = parse_url($value);
    if (!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])) {
        return '';
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if (preg_match('/^[a-z0-9.-]+$/', $host)!==1&&filter_var($host, FILTER_VALIDATE_IP)===false)return '';
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = '/' . ltrim((string)($parts['path']??''), '/');
    return 'https://' . $host . $port . rtrim($path, '/');
}
function netatmo_request_host() : string {
    if (function_exists('meteonexa_expected_origin')) {
        [, $expectedHost] = meteonexa_expected_origin();
        if (is_string($expectedHost)&&$expectedHost!=='')return $expectedHost;
    }
    $raw = trim((string)($_SERVER['SERVER_NAME']??''));
    if ($raw===''||preg_match('/[\r\n]/', $raw)) {
        $raw = trim((string)($_SERVER['HTTP_HOST']??''));
    }
    if ($raw===''||preg_match('/[\r\n]/', $raw))return '';
    $host = parse_url('http://' . $raw, PHP_URL_HOST);
    if (!is_string($host)||$host==='')return '';
    $host = strtolower(rtrim($host, '.'));
    return preg_match('/^[a-z0-9.-]+$/', $host)===1||filter_var($host, FILTER_VALIDATE_IP)!==false ? $host : '';
}
function netatmo_base_url(array $config) : string {
    $configured = netatmo_origin_url((string)($config['app']['base_url']??''));
    if ($configured!=='')return $configured;
    $host = netatmo_request_host();
    if ($host==='')return '';
    $script = (string)($_SERVER['SCRIPT_NAME']??'/api/netatmo/start.php');
    $basePath = rtrim(dirname($script, 3), '/');
    return 'https://' . $host .($basePath!=='' ? $basePath : '');
}
function netatmo_redirect_uri(array $config) : string {
    $configured = netatmo_origin_url((string)($config['netatmo']['redirect_uri']??''));
    if ($configured!=='')return $configured;
    $base = netatmo_base_url($config);
    if ($base==='')respond(['ok'=>false, 'code'=>'NETATMO_URL_INVALID', 'message'=>'api.security.remote_url_invalid'], 503);
    return $base . '/api/netatmo/callback.php';
}
function netatmo_default_return_path(array $config) : string {
    $base = netatmo_base_url($config);
    $path = (string)(parse_url($base, PHP_URL_PATH)??'');
    $path = '/' . trim($path, '/');
    if ($path==='/')return '/#devices';
    return $path . '/#devices';
}
function netatmo_safe_return_path(string $value, array $config, string $fallback = '') : string {
    if ($fallback==='')$fallback = netatmo_default_return_path($config);
    $value = trim($value);
    if ($value===''||strlen($value) > 500||preg_match('/[\x00-\x1F\x7F]/', $value)===1||str_contains($value, chr(92))||preg_match('/%(?:0d|0a|00|5c)/i', $value)===1)return $fallback;
    $parts = parse_url($value);
    if (!is_array($parts)||isset($parts['user'])||isset($parts['pass']))return $fallback;
    if (isset($parts['scheme'])||isset($parts['host'])) {
        $scheme = strtolower((string)($parts['scheme']??''));
        $host = strtolower(rtrim((string)($parts['host']??''), '.'));
        $requestHost = netatmo_request_host();
        if ($scheme!=='https'||$host===''||$requestHost===''||!hash_equals($requestHost, $host))return $fallback;
    }
    $path = (string)($parts['path']??'/');
    if ($path==='')$path = '/';
    if (!str_starts_with($path, '/')||str_starts_with($path, '//')||str_contains($path, chr(92)))return $fallback;
    $appPath = (string)(parse_url(netatmo_base_url($config), PHP_URL_PATH)??'');
    $appPath = '/' . trim($appPath, '/');
    if ($appPath!=='/'&&$path!==$appPath&&!str_starts_with($path, $appPath . '/'))return $fallback;
    return $path .(isset($parts['query']) ? '?' . (string)$parts['query'] : '') .(isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '');
}
function netatmo_assert_configured(array $config) : void {
    if (trim((string)($config['netatmo']['client_id']??''))===''||trim((string)($config['netatmo']['client_secret']??''))==='')respond(['ok'=>false, 'code'=>'NETATMO_NOT_CONFIGURED', 'message'=>meteonexa_backend_text('api.backend.netatmo_not_configured'), 'configured'=>false], 503);
}
function netatmo_token_request(array $config, array $fields) : array {
    $body = http_build_query($fields);
    return meteonexa_http_json('https://api.netatmo.com/oauth2/token',['method'=>'POST', 'timeout'=>20, 'headers'=>['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], 'body'=>$body]);
}
function netatmo_store_tokens(PDO $pdo, array $config, string $device, array $tokens) : void {
    $now = gmdate('c');
    $expires = time() + max(120, (int)($tokens['expires_in']??10800));
    $access = meteonexa_encrypt_value((string)($tokens['access_token']??''), $config, 'netatmo');
    $refresh = meteonexa_encrypt_value((string)($tokens['refresh_token']??''), $config, 'netatmo');
    $st = $pdo->prepare("INSERT INTO netatmo_accounts(device_id,access_token_enc,refresh_token_enc,expires_at,scope,created_at,updated_at) VALUES(:device,:access,:refresh,:expires,:scope,:now,:now) ON CONFLICT(device_id) DO UPDATE SET access_token_enc=excluded.access_token_enc,refresh_token_enc=excluded.refresh_token_enc,expires_at=excluded.expires_at,scope=excluded.scope,updated_at=excluded.updated_at");
    $st->execute([':device'=>$device, ':access'=>$access, ':refresh'=>$refresh, ':expires'=>$expires, ':scope'=>(string)($tokens['scope']??'read_station'), ':now'=>$now]);
}
function netatmo_access_token(PDO $pdo, array $config, string $device) : string {
    $load = static function()use($pdo, $device) : ? array {
        $statement = $pdo->prepare('SELECT * FROM netatmo_accounts WHERE device_id=:device');
        $statement->execute([':device'=>$device]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    };
    $row = $load();
    if (!$row)throw new RuntimeException(meteonexa_backend_text('api.backend.netatmo_not_connected'));
    $access = meteonexa_decrypt_value((string)$row['access_token_enc'], $config, 'netatmo');
    if ((int)$row['expires_at'] > time() + 120&&$access!=='')return $access;
    // OAuth refresh tokens can rotate. Serialize refreshes per device so two
    // simultaneous station/status calls cannot race with the same old token.
    $lock = function_exists('meteonexa_acquire_lock') ? meteonexa_acquire_lock('netatmo-refresh-' . hash('sha256', $device), true) : null;
    if (!is_resource($lock))throw new RuntimeException('NETATMO_REFRESH_LOCK_UNAVAILABLE');
    try {
        $row = $load();
        if (!$row)throw new RuntimeException(meteonexa_backend_text('api.backend.netatmo_not_connected'));
        $access = meteonexa_decrypt_value((string)$row['access_token_enc'], $config, 'netatmo');
        if ((int)$row['expires_at'] > time() + 120&&$access!=='')return $access;
        $refresh = meteonexa_decrypt_value((string)$row['refresh_token_enc'], $config, 'netatmo');
        if ($refresh==='')throw new RuntimeException('NETATMO_REFRESH_TOKEN_MISSING');
        $tokens = netatmo_token_request($config,['grant_type'=>'refresh_token', 'refresh_token'=>$refresh, 'client_id'=>$config['netatmo']['client_id'], 'client_secret'=>$config['netatmo']['client_secret'],]);
        $newAccess = trim((string)($tokens['access_token']??''));
        if ($newAccess==='')throw new RuntimeException('NETATMO_TOKEN_RESPONSE_INVALID');
        if (empty($tokens['refresh_token']))$tokens['refresh_token'] = $refresh;
        netatmo_store_tokens($pdo, $config, $device, $tokens);
        return $newAccess;
    } finally {
        if (function_exists('meteonexa_release_lock'))meteonexa_release_lock($lock);
    }
}
