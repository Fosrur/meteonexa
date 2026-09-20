<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/public_helpers.php';
require_once dirname(__DIR__).'/account/account_helpers.php';
require_once __DIR__.'/watch_helpers.php';
assert_same_origin();require_method('GET','POST');$config=load_config();$pdo=meteonexa_db($config);$isGet=($_SERVER['REQUEST_METHOD']??'GET')==='GET';$data=$isGet?[]:input_json();
$device=clean_device_id($isGet?($_GET['deviceId']??''):($data['deviceId']??''));
$session=require_authenticated_device_session($pdo,$config,$device);$account=meteonexa_account_sync_hash($config,$session);
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    require_device_rate_limit($pdo,'watch_plan_read',$device,180,3600);$state=meteonexa_watch_plan_list($pdo,$account);
    respond(['ok'=>true]+$state+['maxPlans'=>8,'syncScope'=>'authenticated-account','engine'=>'20.1']);
}
require_device_rate_limit($pdo,'watch_plan_write',$device,60,3600);$action=strtolower(clean_text($data['action']??'save',16,'save'));
if($action==='delete'){
    $id=clean_text($data['id']??'',64,'');if(!preg_match('/^[a-f0-9]{24}$/',$id))respond(['ok'=>false,'code'=>'PLAN_INVALID','message'=>'watch.error.invalid'],422);
    meteonexa_account_sync_upsert($pdo,$account,'watch_plan',$id,['id'=>$id],$device,true);respond(['ok'=>true,'deleted'=>true,'id'=>$id]);
}
if($action!=='save')respond(['ok'=>false,'code'=>'PLAN_ACTION_INVALID','message'=>'watch.error.invalid'],422);
$locations=meteonexa_watch_plan_location_map($pdo,$account);$id=clean_text($data['id']??'',64,'');$existing=null;
if($id!==''){$row=meteonexa_account_sync_read($pdo,$account,'watch_plan',$id);$existing=is_array($row)?(array)($row['payload']??[]):null;}
if($existing===null){$active=meteonexa_account_sync_list($pdo,$account,'watch_plan');if(count($active)>=8)respond(['ok'=>false,'code'=>'PLAN_LIMIT','message'=>'watch.error.limit'],422);}
try{$plan=meteonexa_watch_plan_sanitize($data,$locations,$existing);}catch(InvalidArgumentException $e){respond(['ok'=>false,'code'=>$e->getMessage(),'message'=>'watch.error.invalid'],422);}
$revision=meteonexa_account_sync_upsert($pdo,$account,'watch_plan',$plan['id'],$plan,$device);$plan['revision']=$revision;$plan['location']=meteonexa_watch_plan_public_location($locations[$plan['locationKey']]);
respond(['ok'=>true,'plan'=>$plan,'syncScope'=>'authenticated-account','engine'=>'20.1']);
