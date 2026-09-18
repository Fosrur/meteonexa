<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/diagnostics_access.php';
require_once dirname(__DIR__).'/intelligence/verification_helpers.php';
require_method('GET');
assert_same_origin();
try {
    $config=load_config();
    $pdo=meteonexa_db($config);
    $device=clean_device_id($_GET['deviceId']??'');
    $session=require_authenticated_device_session($pdo,$config,$device);
    if(!meteonexa_diagnostics_authorized($pdo,$config,$session))respond(['ok'=>false,'code'=>'FORBIDDEN','message'=>'api.error.forbidden'],403);
    $location=clean_text($_GET['locationKey']??'',191,'');
    respond(['ok'=>true,'scoreboard'=>meteonexa_trust_scoreboard($pdo,$device,$location!==''?$location:null),'engine'=>'20.1']);
} catch (Throwable $error) {
    meteonexa_log_event('diagnostics_trust_scoreboard_failed',$error);
    respond(['ok'=>false,'code'=>'TRUST_SCOREBOARD_FAILED','message'=>'diag.api.trust_failed'],500);
}
