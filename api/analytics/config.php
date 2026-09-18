<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    respond(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'api.backend.method_not_allowed'],405);
}
$enabledRaw = getenv('METEONEXA_PLAUSIBLE_ENABLED');
$enabled = filter_var($enabledRaw === false ? '1' : $enabledRaw, FILTER_VALIDATE_BOOLEAN);
$domain = strtolower(trim((string)(getenv('METEONEXA_PLAUSIBLE_DOMAIN') ?: 'meteonexa.com')));
if (!preg_match('/^[a-z0-9.-]+$/', $domain)) $domain = 'meteonexa.com';
header('Cache-Control: public, max-age=300, stale-while-revalidate=300');
respond([
    'ok'=>true,
    'enabled'=>$enabled,
    'provider'=>'plausible',
    'endpoint'=>'https://plausible.io/api/event',
    'domain'=>$domain,
    'mode'=>'cookieless-pageviews',
    'customEvents'=>false,
    'policyVersion'=>'20.1',
]);
