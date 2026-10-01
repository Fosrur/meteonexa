<?php
declare(strict_types=1);

function meteonexa_observability_provider_dashboard(PDO $pdo):array{
 if(!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo,'weather_provider_health'))return['available'=>false,'providers'=>[]];
 $rows=$pdo->query('SELECT provider_id,status,last_attempt_at,last_success_at,last_failure_at,latency_ms,consecutive_failures,freshness_seconds,updated_at FROM weather_provider_health ORDER BY provider_id')->fetchAll();$providers=[];$degraded=0;
 foreach($rows as $r){$status=(string)$r['status'];if(!in_array($status,['ok','disabled','not_configured'],true))$degraded++;$providers[]=['id'=>$r['provider_id'],'status'=>$status,'latencyMs'=>(int)$r['latency_ms'],'errorStreak'=>(int)$r['consecutive_failures'],'cacheAgeSeconds'=>is_numeric($r['freshness_seconds']??null)?(int)$r['freshness_seconds']:null,'lastSuccessAt'=>$r['last_success_at'],'updatedAt'=>$r['updated_at']];}
 return['available'=>true,'status'=>$degraded?'degraded':'ok','providerCount'=>count($providers),'degradedProviders'=>$degraded,'providers'=>$providers,'generatedAt'=>gmdate('c')];
}

function meteonexa_observability_slo_definitions():array{return[
 'home-forecast'=>['availabilityTargetPct'=>99.5,'p95LatencyMs'=>6000],
 'nowcast'=>['availabilityTargetPct'=>99.0,'p95LatencyMs'=>3500],
 'official-warning'=>['availabilityTargetPct'=>99.5,'p95LatencyMs'=>3000],
 'ai-explanation'=>['availabilityTargetPct'=>98.0,'p95LatencyMs'=>35000],
];}
function meteonexa_observability_percentile(array $v,float $p):?float{if(!$v)return null;sort($v,SORT_NUMERIC);$i=(int)floor((count($v)-1)*$p);return round((float)$v[max(0,min(count($v)-1,$i))],1);}
function meteonexa_observability_slo_dashboard(PDO $pdo,int $hours=24):array{
 $defs=meteonexa_observability_slo_definitions();$out=[];$cut=gmdate('c',time()-max(1,min(168,$hours))*3600);
 foreach($defs as $name=>$def){$rows=[];if(function_exists('meteonexa_db_table_exists')&&meteonexa_db_table_exists($pdo,'runtime_metrics')){$st=$pdo->prepare("SELECT status,duration_ms,created_at FROM runtime_metrics WHERE category='slo' AND metric_name=:name AND created_at>=:cut ORDER BY id DESC LIMIT 10000");$st->execute([':name'=>$name,':cut'=>$cut]);$rows=$st->fetchAll();}$ok=0;$lat=[];foreach($rows as $r){if(in_array((string)$r['status'],['ok','fallback'],true))$ok++;if(is_numeric($r['duration_ms']??null))$lat[]=(float)$r['duration_ms'];}$availability=count($rows)?round($ok/count($rows)*100,3):null;$p95=meteonexa_observability_percentile($lat,.95);$out[$name]=['samples'=>count($rows),'availabilityPct'=>$availability,'p95LatencyMs'=>$p95,'target'=>$def,'meetingTarget'=>$availability!==null&&$availability>=$def['availabilityTargetPct']&&($p95===null||$p95<=$def['p95LatencyMs'])];}
 return['available'=>true,'windowHours'=>$hours,'services'=>$out,'generatedAt'=>gmdate('c')];
}

function meteonexa_model_drift_dashboard(PDO $pdo,int $days=14):array{
 if(!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo,'model_skill_samples'))return['available'=>false,'segments'=>[]];
 $now=time();$recent=gmdate('c',$now-$days*86400);$prior=gmdate('c',$now-2*$days*86400);$st=$pdo->prepare("SELECT location_key,model_name,metric,horizon_hours,error_value,brier_score,verified_at FROM model_skill_samples WHERE verified_at<>'' AND verified_at>=:prior ORDER BY id DESC LIMIT 30000");$st->execute([':prior'=>$prior]);$groups=[];
 foreach($st->fetchAll() as $r){$period=((string)$r['verified_at']>=$recent)?'recent':'prior';$k=(string)$r['location_key'].'|'.(string)$r['model_name'].'|'.(string)$r['metric'].'|'.(int)$r['horizon_hours'];$g=&$groups[$k];if(!$g)$g=['locationKey'=>(string)$r['location_key'],'model'=>(string)$r['model_name'],'metric'=>(string)$r['metric'],'horizonHours'=>(int)$r['horizon_hours'],'recent'=>[],'prior'=>[]];if(is_numeric($r['error_value']??null))$g[$period][] = abs((float)$r['error_value']);}
 $segments=[];foreach($groups as $g){$ra=$g['recent']?array_sum($g['recent'])/count($g['recent']):null;$pa=$g['prior']?array_sum($g['prior'])/count($g['prior']):null;if($ra===null&&$pa===null)continue;$delta=($ra!==null&&$pa!==null)?$ra-$pa:null;$segments[]=['locationKey'=>$g['locationKey'],'model'=>$g['model'],'metric'=>$g['metric'],'horizonHours'=>$g['horizonHours'],'recentSamples'=>count($g['recent']),'priorSamples'=>count($g['prior']),'recentMae'=>$ra===null?null:round($ra,3),'priorMae'=>$pa===null?null:round($pa,3),'deltaMae'=>$delta===null?null:round($delta,3),'drift'=>($delta!==null&&$delta>max(.5,($pa??0)*.15))?'degraded':'stable'];}
 usort($segments,static fn($a,$b)=>(($b['deltaMae']??-999)<=>($a['deltaMae']??-999)));return['available'=>true,'windowDays'=>$days,'segments'=>array_slice($segments,0,200),'generatedAt'=>gmdate('c')];
}

function meteonexa_canary_metrics(PDO $pdo,string $start,string $end):array{
 if(!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo,'model_skill_samples'))return['samples'=>0,'mae'=>null,'brier'=>null];
 $st=$pdo->prepare("SELECT error_value,brier_score FROM model_skill_samples WHERE verified_at<>'' AND verified_at>=:s AND verified_at<:e LIMIT 50000");$st->execute([':s'=>$start,':e'=>$end]);$err=[];$brier=[];foreach($st->fetchAll() as $r){if(is_numeric($r['error_value']??null))$err[]=abs((float)$r['error_value']);if(is_numeric($r['brier_score']??null))$brier[]=(float)$r['brier_score'];}
 return['samples'=>count($err),'mae'=>$err?round(array_sum($err)/count($err),4):null,'brier'=>$brier?round(array_sum($brier)/count($brier),4):null];
}
function meteonexa_canary_compare(array $baseline,array $candidate):array{
 $reasons=[];$eligible=true;if((int)($baseline['samples']??0)<30||(int)($candidate['samples']??0)<30){$eligible=false;$reasons[]='insufficient-samples';}
 if(is_numeric($baseline['mae']??null)&&is_numeric($candidate['mae']??null)&&(float)$candidate['mae']>(float)$baseline['mae']*1.05){$eligible=false;$reasons[]='mae-regression';}
 if(is_numeric($baseline['brier']??null)&&is_numeric($candidate['brier']??null)&&(float)$candidate['brier']>(float)$baseline['brier']+.03){$eligible=false;$reasons[]='brier-regression';}
 return['manualPromotionReviewEligible'=>$eligible,'automaticPromotion'=>false,'reasons'=>$reasons?:['no-material-regression'],'guardrails'=>['minSamplesPerWindow'=>30,'maxMaeRegressionPct'=>5,'maxBrierRegression'=>.03]];
}
function meteonexa_canary_snapshot(PDO $pdo,string $sha,string $phase,string $start,string $end):array{
 $m=meteonexa_canary_metrics($pdo,$start,$end);if(function_exists('meteonexa_db_table_exists')&&meteonexa_db_table_exists($pdo,'release_canary_snapshots')){$st=$pdo->prepare('INSERT INTO release_canary_snapshots(release_sha,phase,window_start,window_end,sample_count,mae,brier,metrics_json,created_at) VALUES(:sha,:phase,:s,:e,:n,:mae,:brier,:j,:c)');$st->execute([':sha'=>substr($sha,0,64),':phase'=>substr($phase,0,24),':s'=>$start,':e'=>$end,':n'=>(int)$m['samples'],':mae'=>$m['mae'],':brier'=>$m['brier'],':j'=>json_encode($m,JSON_UNESCAPED_SLASHES)?:'{}',':c'=>gmdate('c')]);}return $m;
}
function meteonexa_golden_locations():array{$p=dirname(__DIR__,2).'/config/golden-locations-europe.json';$v=is_file($p)?json_decode((string)file_get_contents($p),true):null;return is_array($v)?$v:[];}
