<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/public_helpers.php';
assert_same_origin();
function pref_language(mixed $value) : string {
    $raw = strtolower(trim((string)$value));
    $code = preg_split('/[-_]/', $raw, 2)[0]??'it';
    return in_array($code,['it', 'en', 'fr', 'es', 'de'], true) ? $code : 'it';
}
function pref_theme(mixed $value, bool $allowSystem = true) : string {
    $theme = strtolower(trim((string)$value));
    $allowed = $allowSystem ?['system', 'dark', 'light'] :['dark', 'light'];
    return in_array($theme, $allowed, true) ? $theme :($allowSystem ? 'system' : 'dark');
}
function pref_client_id() : string {
    $name = 'meteonexa_client';
    $existing = trim((string)($_COOKIE[$name]??''));
    if (preg_match('/^[a-f0-9]{32}$/', $existing)===1)return $existing;
    $id = bin2hex(random_bytes(16));
    $secure = meteonexa_cookie_secure(load_config());
    setcookie($name, $id,['expires'=>time() + 63072000, 'path'=>meteonexa_app_cookie_path(load_config()), 'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Strict']);
    $_COOKIE[$name] = $id;
    return $id;
}
function pref_catalog(PDO $pdo, string $language) : array {
    $statement = $pdo->prepare('SELECT text_key, translation FROM translations WHERE locale = :locale ORDER BY text_key');
    $statement->execute([':locale'=>pref_language($language)]);
    $catalog =[];
    foreach ($statement->fetchAll() as $row) {
        $key = (string)($row['text_key']??'');
        if ($key!=='')$catalog[$key] = (string)($row['translation']??'');
    }
    return $catalog;
}
function pref_merged_catalog(PDO $pdo, string $language) : array {
    $runtime = pref_catalog($pdo, $language);
    $path = meteonexa_baseline_sqlite_path();
    if (!is_file($path)||(int)@filesize($path)<=0)return $runtime;
    try {
        $baseline = new PDO('sqlite:' . $path, null, null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $baseline->exec('PRAGMA query_only = ON');
        return array_replace(pref_catalog($baseline, $language), $runtime);
    } catch (Throwable $error) {
        return $runtime;
    }
}
function pref_supported_languages(PDO $pdo, string $language) : array {
    $keys =['language.it', 'language.en', 'language.fr', 'language.es', 'language.de'];
    $statement = $pdo->prepare("SELECT text_key,translation FROM translations WHERE locale=:locale AND text_key IN ('language.it','language.en','language.fr','language.es','language.de')");
    $statement->execute([':locale'=>pref_language($language)]);
    $labels =[];
    foreach ($statement->fetchAll() as $row)$labels[(string)$row['text_key']] = (string)$row['translation'];
    return array_map(static fn(string $code) : array=>['code'=>$code, 'label'=>(string)($labels['language.' . $code]??strtoupper($code))],['it', 'en', 'fr', 'es', 'de']);
}
try {
    $config = load_config();
    $pdo = meteonexa_db($config);
    $clientId = pref_client_id();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $requestData = $method==='POST' ? input_json() : $_GET;
    $now = gmdate('c');
    $browserLanguage = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)($requestData['browserLanguage']??'it-IT'))) ? : 'it-IT';
    $browserLanguage = substr($browserLanguage, 0, 32);
    $browserTheme = pref_theme($requestData['browserTheme']??'dark', false);
    if ($method==='POST') {
        require_ip_rate_limit($pdo, 'preferences_write_ip', 180, 3600);
        $globalPreferenceRate = meteonexa_rate_limit($pdo, 'preferences_write_global', 'all', auth_secret($config), 2000, 3600);
        if (!$globalPreferenceRate['allowed']) {
            header('Retry-After: ' . max(1, (int)$globalPreferenceRate['retryAfter']));
            respond(['ok'=>false, 'code'=>'RATE_LIMITED', 'message'=>'api.security.rate_limit', 'retryAfter'=>max(1, (int)$globalPreferenceRate['retryAfter'])], 429);
        }
        $language = pref_language($requestData['language']??$browserLanguage);
        $theme = pref_theme($requestData['theme']??'system');
        $statement = $pdo->prepare('INSERT INTO app_preferences(client_id,language,theme,browser_language,browser_theme,created_at,updated_at) VALUES(:client_id,:language,:theme,:browser_language,:browser_theme,:created_at,:updated_at) ON CONFLICT(client_id) DO UPDATE SET language=excluded.language,theme=excluded.theme,browser_language=excluded.browser_language,browser_theme=excluded.browser_theme,updated_at=excluded.updated_at');
        $statement->execute([':client_id'=>$clientId, ':language'=>$language, ':theme'=>$theme, ':browser_language'=>$browserLanguage, ':browser_theme'=>$browserTheme, ':created_at'=>$now, ':updated_at'=>$now]);
        if (random_int(1, 50)===1) {
            $pdo->prepare('DELETE FROM app_preferences WHERE updated_at < :cutoff')->execute([':cutoff'=>gmdate('c', time() - 400 * 86400)]);
        }
        meteonexa_prune_rows_to_limit($pdo, 'app_preferences', (int)($config['storage_limits']['app_preferences']??50000));
    } elseif ($method==='GET') {
        $language = pref_language($browserLanguage);
        $theme = 'system';
        $lookup = $pdo->prepare('SELECT language,theme FROM app_preferences WHERE client_id = :client_id');
        $lookup->execute([':client_id'=>$clientId]);
        $existing = $lookup->fetch();
        if ($existing) {
            $language = pref_language($existing['language']??$browserLanguage);
            $theme = pref_theme($existing['theme']??'system');
        }
    } else {
        respond(['ok'=>false, 'code'=>'METHOD_NOT_ALLOWED', 'message'=>'api.error.method'], 405);
    }
    $translationVersion = hash('sha256', meteonexa_translation_version($pdo, $language) . '|' . (string)($config['app']['version']??'unknown'));
    $knownVersion = trim((string)($_GET['translationsUpdatedAt']??''));
    $includeCatalog = $method==='POST'||$knownVersion===''||!hash_equals($translationVersion, $knownVersion);
    $catalog = $includeCatalog ? pref_merged_catalog($pdo, $language) :[];
    if ($includeCatalog&&$catalog===[])respond(['ok'=>false, 'code'=>'CATALOG_EMPTY', 'message'=>'api.error.catalog'], 503);
    respond(['ok'=>true, 'language'=>$language, 'theme'=>$theme, 'resolvedTheme'=>$theme==='system' ? $browserTheme : $theme, 'browserLanguage'=>$browserLanguage, 'browserTheme'=>$browserTheme, 'supportedLanguages'=>pref_supported_languages($pdo, $language), 'translations'=>$includeCatalog ? $catalog : null, 'preferenceUpdatedAt'=>$now, 'translationsUpdatedAt'=>$translationVersion, 'storage'=>meteonexa_pdo_driver($pdo), 'version'=>(string)($config['app']['version']??'unknown'), 'serverTime'=>$now]);
} catch (Throwable $error) {
    respond(['ok'=>false, 'code'=>'PREFERENCES_ERROR', 'message'=>'api.error.preferences'], 503);
}
