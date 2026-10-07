<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');

try {
    $config = load_config();
    $pdo = meteonexa_db($config);
    $schema = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'),'')")->fetchColumn() ?: '');
    $version = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='app_version'),'')")->fetchColumn() ?: ($config['app']['version'] ?? ''));
    $schemaOk = $schema === (string)meteonexa_current_schema_version();
    $releaseSha = strtolower(trim((string)(getenv('METEONEXA_RELEASE_SHA') ?: '')));
    if (preg_match('/^[0-9a-f]{40}$/', $releaseSha) !== 1) {
        $releaseSha = '';
    }
    respond([
        'ok'=>$schemaOk,
        'service'=>'meteonexa',
        'version'=>$version,
        'schema'=>$schema,
        'database'=>'ok',
        'releaseSha'=>$releaseSha,
    ], $schemaOk ? 200 : 503);
} catch (Throwable $error) {
    meteonexa_log_event('health_check_failed', $error);
    respond(['ok'=>false,'code'=>'DATABASE_UNAVAILABLE','message'=>'api.backend.database_unavailable','service'=>'meteonexa','database'=>'unavailable'],503);
}
