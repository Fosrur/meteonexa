<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/pipeline/helpers.php';
$config = load_config();
$secret = trim((string)($config['calibration']['cron_secret']??''));
$provided = trim((string)($_SERVER['HTTP_X_CRON_KEY']??''));
if (PHP_SAPI!=='cli'&&strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')respond(['ok'=>false, 'code'=>'METHOD_NOT_ALLOWED', 'message'=>'api.backend.method_not_allowed'], 405);
if (PHP_SAPI!=='cli'&&($secret===''||!hash_equals($secret, $provided)))respond(['ok'=>false, 'code'=>'FORBIDDEN', 'message'=>'api.error.forbidden'], 403);
$pdo = meteonexa_db($config);
$lock = meteonexa_acquire_lock('model-calibration-worker');
if (!$lock)respond(['ok'=>true, 'engine'=>'20.1', 'skipped'=>'overlap', 'locations'=>0, 'verified'=>0, 'queued'=>0]);
register_shutdown_function(static function()use($lock) : void {
    meteonexa_release_lock($lock);
});
$locations = meteonexa_calibration_locations($pdo, max(5, (int)($config['calibration']['max_locations_per_cycle']??60)));
$verified = 0;
$queued = 0;
$processed = 0;
$errors =[];
$obsLocations = 0;
$sources =[];
foreach ($locations as $loc) {
    $started = meteonexa_pipeline_ms();
    try {
        $result = meteonexa_calibration_process_location($pdo, $config, $loc);
        $obs = (array)$result['observations'];
        if (!empty($obs['available'])) {
            $obsLocations++;
            foreach ((array)$obs['sourceTypes'] as $src)$sources[$src] = true;
        }
        $verified+=(int)$result['verified'];
        $queued+=(int)$result['queued'];
        $processed++;
        meteonexa_pipeline_provider_result($pdo, 'verification-worker', true, meteonexa_pipeline_ms() - $started,['locationRef'=>substr(hash('sha256', (string)$loc['deviceId'] . '|' . $loc['locationKey']), 0, 12), 'verified'=>$result['verified'], 'queued'=>$result['queued']], 0);
    } catch (Throwable $e) {
        meteonexa_pipeline_provider_result($pdo, 'verification-worker', false, meteonexa_pipeline_ms() - $started,['locationRef'=>substr(hash('sha256', (string)$loc['deviceId'] . '|' . $loc['locationKey']), 0, 12), 'code'=>'CALIBRATION_LOCATION_FAILED']);
        $errors[] =['locationRef'=>substr(hash('sha256', (string)$loc['deviceId'] . '|' . $loc['locationKey']), 0, 12), 'code'=>'CALIBRATION_LOCATION_FAILED'];
    }
}
$retention = max(30, (int)($config['calibration']['retention_days']??180));
$cutoff = gmdate('Y-m-d\TH:00:00\Z', time() - $retention * 86400);
$pdo->prepare('DELETE FROM model_skill_samples WHERE target_time<:c')->execute([':c'=>$cutoff]);
if (meteonexa_db_table_exists($pdo, 'observation_evidence'))$pdo->prepare('DELETE FROM observation_evidence WHERE observed_at<:c')->execute([':c'=>$cutoff]);
if (meteonexa_db_table_exists($pdo, 'forecast_run_snapshots'))$pdo->prepare('DELETE FROM forecast_run_snapshots WHERE created_at<:c')->execute([':c'=>gmdate('c', time() - 90 * 86400)]);
if (meteonexa_db_table_exists($pdo, 'nowcast_fusion_snapshots'))$pdo->prepare('DELETE FROM nowcast_fusion_snapshots WHERE created_at<:c')->execute([':c'=>gmdate('c', time() - 7 * 86400)]);
respond(['ok'=>true, 'engine'=>'20.1', 'locations'=>$processed, 'verified'=>$verified, 'queued'=>$queued, 'horizons'=>[1, 3, 6, 24, 48, 72], 'observationLocations'=>$obsLocations, 'observationSources'=>array_keys($sources), 'truthSource'=>'independent-observations', 'errors'=>$errors]);
