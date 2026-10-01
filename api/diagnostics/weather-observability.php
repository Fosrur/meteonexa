<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/diagnostics_access.php';
require_once dirname(__DIR__).'/observability/weather_release_helpers.php';
require_method('GET');assert_same_origin();
try{
 $config=load_config();$pdo=meteonexa_db($config);$device=clean_device_id($_GET['deviceId']??'');$session=require_authenticated_device_session($pdo,$config,$device);
 if(!meteonexa_diagnostics_authorized($pdo,$config,$session))respond(['ok'=>false,'code'=>'FORBIDDEN','message'=>'api.error.forbidden'],403);
 respond(['ok'=>true,'providerDashboard'=>meteonexa_observability_provider_dashboard($pdo),'slo'=>meteonexa_observability_slo_dashboard($pdo,24),'modelDrift'=>meteonexa_model_drift_dashboard($pdo,14),'goldenLocations'=>['count'=>count(meteonexa_golden_locations()),'cases'=>array_values(array_unique(array_merge(...array_map(static fn($r)=>(array)($r['cases']??[]),meteonexa_golden_locations()))))],'canaryPolicy'=>['automaticPromotion'=>false,'comparison'=>'baseline-vs-candidate-verified-skill'],'generatedAt'=>gmdate('c')]);
}catch(Throwable $e){meteonexa_log_event('diagnostics_weather_observability_failed',$e);respond(['ok'=>false,'code'=>'WEATHER_OBSERVABILITY_FAILED','message'=>'diag.api.weather_observability_failed'],500);}
