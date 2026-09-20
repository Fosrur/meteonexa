<?php
declare(strict_types=1);
function meteonexa_intel_time_utc(string $value): ?int { $ts=strtotime($value); return $ts===false?null:$ts; }
require_once dirname(__DIR__).'/api/intelligence/probabilistic_nowcast_helpers.php';
function ok(bool $value,string $label):void{echo ($value?'[ OK ] ':'[FAIL] ').$label."\n";if(!$value)$GLOBALS['failed']=true;}
$now=time();$timeline=[];foreach(range(0,90,5) as $m){$p=(int)max(5,min(88,20+($m<=30?$m*2.1:(90-$m)*.85)));$timeline[]=['minute'=>$m,'at'=>gmdate('c',$now+$m*60),'precipitationProbability'=>$p,'confidence'=>78-(int)round($m*.12),'uncertaintyPct'=>12,'nwpProbability'=>55];}
$v2=['available'=>true,'confidence'=>78,'etaMinutes'=>28,'etaRangeMinutes'=>[18,38],'timeline'=>$timeline,'radar3Mode'=>'shadow','radar3ProductionGate'=>['eligible'=>false,'reason'=>'probation_insufficient_history']];
$consensus=['hourly'=>[['time'=>gmdate('c',$now),'available'=>6]],'modelsAvailable'=>6];
$light=['available'=>true,'degraded'=>false,'recent30m'=>5,'nearestKm'=>22.0,'approaching'=>true];
$sat=['available'=>true,'cloudAttenuationPct'=>70];
$conv=['timeline'=>[['time'=>gmdate('c',$now+1800),'calibratedProbabilityPct'=>64]],'peak'=>['calibratedProbabilityPct'=>64],'hailPotential'=>['available'=>true,'score'=>58,'level'=>'moderate']];
$severe=['events'=>[['type'=>'wind','startsAt'=>gmdate('c',$now+1200),'endsAt'=>gmdate('c',$now+3600),'modelAgreementPct'=>67,'confidence'=>76]]];
$obs=['available'=>true,'sourceCount'=>2,'evidence'=>[['sourceType'=>'metar'],['sourceType'=>'netatmo']]];
$official=['relevant'=>[]];
$out=meteonexa_probabilistic_nowcast($v2,$consensus,$light,$sat,$conv,$severe,$obs,$official);
ok(($out['available']??false)===true,'Nowcast 4 is available');
ok(($out['method']??'')==='probabilistic-nowcast-v4','method contract');
ok(count($out['timeline']??[])===19,'0-90 minute timeline has 19 five-minute points');
$bounded=true;foreach($out['timeline'] as $r)foreach(['rainProbabilityPct','rainLowPct','rainHighPct','stormProbabilityPct','gustProbabilityPct','confidence'] as $k)if(($r[$k]??-1)<0||($r[$k]??101)>100)$bounded=false;
ok($bounded,'all probabilities/confidence are bounded 0-100');
$arr=$out['arrivalDistribution']??[];ok(!empty($arr['available']),'ETA distribution published for reliable ETA');
$prev=-1;$mono=true;$max=0;foreach($arr['byMinutes']??[] as $r){$v=(int)$r['probabilityPct'];if($v<$prev)$mono=false;$prev=$v;$max=max($max,$v);}ok($mono,'arrival CDF is monotonic');ok($max<=(int)($arr['eventProbabilityPct']??0),'arrival CDF is capped by event probability');
ok(($out['radarAuthority']??'')==='radar2-authoritative','Radar3 probation cannot become authoritative');
ok(($out['hailPotential']['probability']??null)===false,'hail potential is explicitly not a probability');
ok(($out['policy']['officialWarningsRemainSeparate']??false)===true,'official warnings remain separate');
$v2['radar3Mode']='active';$active=meteonexa_probabilistic_nowcast($v2,$consensus,$light,$sat,$conv,$severe,$obs,$official);ok(($active['radarAuthority']??'')==='radar3-verified','verified active Radar3 can be authoritative');
$v2['confidence']=40;$low=meteonexa_probabilistic_nowcast($v2,$consensus,$light,$sat,$conv,$severe,$obs,$official);ok(empty($low['arrivalDistribution']['available']),'low-confidence ETA distribution fails closed');
exit(!empty($GLOBALS['failed'])?1:0);
