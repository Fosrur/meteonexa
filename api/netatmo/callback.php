<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/helpers.php';

require_method('GET');
$config=load_config();
netatmo_assert_configured($config);
$pdo=meteonexa_db($config);
require_ip_rate_limit($pdo,'netatmo_callback_ip',240,3600);
require_global_rate_limit($pdo,'netatmo_callback_global',(int)($config['abuse_limits']['netatmo_callback_global_hour']??2000),3600);
$state=trim((string)($_GET['state']??''));
$code=trim((string)($_GET['code']??''));
if(strlen($state)<32||strlen($state)>128||preg_match('/^[A-Za-z0-9_-]+$/',$state)!==1||$code===''||strlen($code)>2048){
    http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo meteonexa_backend_text('api.backend.netatmo_oauth_invalid');exit;
}
$hash=hash('sha256',$state);
$st=$pdo->prepare('SELECT * FROM oauth_states WHERE state_hash=:hash AND expires_at>=:now');
$st->execute([':hash'=>$hash,':now'=>time()]);
$row=$st->fetch();
if(!$row){http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo meteonexa_backend_text('api.backend.netatmo_oauth_invalid');exit;}
// State is strictly single use even if the provider token exchange fails.
$pdo->prepare('DELETE FROM oauth_states WHERE state_hash=:hash')->execute([':hash'=>$hash]);
try{
    $tokens=netatmo_token_request($config,['grant_type'=>'authorization_code','client_id'=>$config['netatmo']['client_id'],'client_secret'=>$config['netatmo']['client_secret'],'code'=>$code,'redirect_uri'=>netatmo_redirect_uri($config),'scope'=>$config['netatmo']['scope']??'read_station']);
    netatmo_store_tokens($pdo,$config,(string)$row['device_id'],$tokens);
    $return=netatmo_safe_return_path((string)$row['return_url'],$config);
    // Put the status before the fragment so it survives browser navigation.
    $fragment='';
    if(str_contains($return,'#')){[$return,$fragment]=explode('#',$return,2);$fragment='#'.$fragment;}
    $separator=str_contains($return,'?')?'&':'?';
    header('Location: '.$return.$separator.'netatmo=connected'.$fragment,true,302);exit;
}catch(Throwable $e){
    meteonexa_log_event('netatmo_callback_failed', $e);
    http_response_code(502);header('Content-Type: text/plain; charset=utf-8');
    echo htmlspecialchars(meteonexa_backend_text('api.backend.netatmo_connection_failed',[],$row['language']??null),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
