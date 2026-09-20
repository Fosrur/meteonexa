<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/database.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    respond(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'api.backend.method_not_allowed'],405);
}

// Public read-only UI configuration. These flags only control presentation;
// authorization remains enforced independently by protected API endpoints.
try {
    $config = load_config();
    $pdo = meteonexa_db($config);
    $rows = $pdo->query('SELECT feature_key,guest_visible,authenticated_visible,updated_at FROM ui_visibility ORDER BY feature_key')->fetchAll();
    $features = [];
    $updatedAt = '';
    foreach ($rows as $row) {
        $key = trim((string)($row['feature_key'] ?? ''));
        if ($key === '') continue;
        $features[$key] = [
            'guest' => ((int)($row['guest_visible'] ?? 0)) === 1,
            'authenticated' => ((int)($row['authenticated_visible'] ?? 0)) === 1,
        ];
        $rowUpdated = (string)($row['updated_at'] ?? '');
        if ($rowUpdated > $updatedAt) $updatedAt = $rowUpdated;
    }
    // Runtime credentials are DB-backed. UI capability flags must reflect the
    // effective DB state, not only optional one-time environment provisioning.
    $smtp = (array)($config['smtp'] ?? []);
    try {
        $dbSmtp = meteonexa_load_smtp($config);
        if ($dbSmtp !== []) $smtp = array_replace($smtp, $dbSmtp);
    } catch (Throwable $ignored) {}
    $ai = (array)($config['ai'] ?? []);
    try {
        $dbAi = meteonexa_load_ai($config);
        if ($dbAi !== []) $ai = array_replace($ai, $dbAi);
    } catch (Throwable $ignored) {}
    $aiProvider = strtolower(trim((string)($ai['provider'] ?? 'openrouter')));
    $aiKey = $aiProvider === 'groq'
        ? trim((string)($ai['groq_api_key'] ?? $ai['api_key'] ?? ''))
        : trim((string)($ai['openrouter_api_key'] ?? $ai['api_key'] ?? ''));
    $nativeMail = (bool)($config['smtp']['native_mail_fallback'] ?? false) && function_exists('mail');
    $smtpReady = trim((string)($smtp['host'] ?? '')) !== ''
        && filter_var(trim((string)($smtp['from_email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
    $services = [
        // Public booleans only: never expose provider credentials or secret values.
        'email' => $smtpReady || $nativeMail,
        'ai.external' => $aiKey !== '',
        'lightning' => trim((string)($config['lightning']['client_id'] ?? '')) !== ''
            && trim((string)($config['lightning']['client_secret'] ?? '')) !== '',
        'netatmo' => trim((string)($config['netatmo']['client_id'] ?? '')) !== ''
            && trim((string)($config['netatmo']['client_secret'] ?? '')) !== '',
        'push' => function_exists('openssl_pkey_new'),
        'smart.alerts' => function_exists('openssl_pkey_new'),
        'official.alerts' => true,
        'hyperlocal' => trim((string)($config['netatmo']['client_id'] ?? '')) !== ''
            && trim((string)($config['netatmo']['client_secret'] ?? '')) !== '',
    ];

    $legal = (array)($config['legal'] ?? []);
    $controllerName = trim((string)($legal['controller_name'] ?? ''));
    $controllerAddress = trim((string)($legal['controller_address'] ?? ''));
    if (mb_strlen($controllerName) > 200) $controllerName = mb_substr($controllerName, 0, 200);
    if (mb_strlen($controllerAddress) > 500) $controllerAddress = mb_substr($controllerAddress, 0, 500);
    $privacyEmail = trim((string)($legal['privacy_contact_email'] ?? ''));
    if (filter_var($privacyEmail, FILTER_VALIDATE_EMAIL) === false) {
        $privacyEmail = trim((string)($smtp['from_email'] ?? ''));
    }
    if (filter_var($privacyEmail, FILTER_VALIDATE_EMAIL) === false) $privacyEmail = '';
    $dpoEmail = trim((string)($legal['dpo_email'] ?? ''));
    if (filter_var($dpoEmail, FILTER_VALIDATE_EMAIL) === false) $dpoEmail = '';
    $siteUrl = trim((string)($legal['site_url'] ?? 'https://www.meteonexa.com/'));
    if (filter_var($siteUrl, FILTER_VALIDATE_URL) === false || strtolower((string)parse_url($siteUrl, PHP_URL_SCHEME)) !== 'https') {
        $siteUrl = 'https://www.meteonexa.com/';
    }
    $safeAiProvider = $aiKey === '' ? '' : (in_array($aiProvider, ['openrouter','groq'], true) ? $aiProvider : '');
    $legalConfigured = $controllerName !== '' && $controllerAddress !== '' && $privacyEmail !== '';

    header('Cache-Control: no-store, max-age=0');
    respond([
        'ok'=>true,
        'features'=>$features,
        'services'=>$services,
        'contact'=>[
            'controllerName'=>$controllerName,
            'controllerAddress'=>$controllerAddress,
            'privacyEmail'=>$privacyEmail,
            'dpoEmail'=>$dpoEmail,
            'siteUrl'=>$siteUrl,
            'legalConfigured'=>$legalConfigured,
            'aiProvider'=>$safeAiProvider,
        ],
        'updatedAt'=>$updatedAt,
        'version'=>(string)($config['app']['version'] ?? 'unknown'),
    ]);
} catch (Throwable $error) {
    // The client ships conservative defaults. A configuration read failure must
    // not expose internals or make protected guest features visible.
    respond(['ok'=>false,'code'=>'UI_CONFIG_UNAVAILABLE','message'=>'api.error.preferences'],503);
}
