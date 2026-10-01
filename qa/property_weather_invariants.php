<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/intelligence/quality_helpers.php';
require_once dirname(__DIR__).'/api/intelligence/reliability_helpers.php';
require_once dirname(__DIR__).'/api/official/hub_helpers.php';
require_once dirname(__DIR__).'/api/ai/meteorologist_v2.php';
require_once dirname(__DIR__).'/api/intelligence/radar4_probabilistic_helpers.php';
$helper=getenv('METEONEXA_WEATHER_IMPROVEMENT_HELPER')?:dirname(__DIR__).'/api/observability/weather_improvement_helpers.php';
require_once $helper;
function prop(bool $v,string $m):void{if(!$v){fwrite(STDERR,"PROPERTY FAIL: $m\n");exit(1);}}
mt_srand(2601001);
for($i=0;$i<250;$i++){
  $p=mt_rand(-1000,2000)/1000;$n=mt_rand(1,500);[$lo,$hi]=meteonexa_wilson_interval($p,$n);prop($lo>=0&&$hi<=1&&$lo<=$hi,'Wilson interval bounds/order');
  $weights=['a'=>mt_rand(0,1000)/100,'b'=>mt_rand(0,1000)/100,'c'=>mt_rand(0,1000)/100];$norm=meteonexa_weather_normalize_weights($weights);$sum=array_sum($norm);prop($sum===0.0||abs($sum-1.0)<.00001,'normalized weather weights sum to one');
  $mean=mt_rand(-30,180);$sigma=mt_rand(1,60);$prob=mt_rand(0,100);$d=meteonexa_radar4_time_distribution((float)$mean,(float)$sigma,(float)$prob,120,5);prop(($d['eventProbabilityPct']??-1)>=0&&($d['eventProbabilityPct']??101)<=100,'Radar4 probability bounded');
  if(!empty($d['available'])){prop(($d['p10Minutes']??-1)<=($d['p50Minutes']??-2)&&($d['p50Minutes']??-1)<=($d['p90Minutes']??-2),'Radar4 P10/P50/P90 ordered');prop(($d['p10Minutes']??-1)>=0&&($d['p90Minutes']??121)<=120,'Radar4 timing inside horizon');}
}
$poly=['type'=>'Polygon','coordinates'=>[[[8,44],[10,44],[10,46],[8,46],[8,44]]]];
for($i=0;$i<100;$i++){ $lat=44.01+mt_rand(0,1900)/1000;$lon=8.01+mt_rand(0,1900)/1000;prop(meteonexa_official_hub_geometry_contains($poly,$lat,$lon),'points inside official polygon remain inside'); }
$decision=['status'=>'avoid','confidence'=>82,'dominantRisk'=>'storm'];$id1=meteonexa_ai_v2_decision_id($decision,['b'=>2,'a'=>1]);$id2=meteonexa_ai_v2_decision_id(['dominantRisk'=>'storm','confidence'=>82,'status'=>'avoid'],['a'=>1,'b'=>2]);prop($id1===$id2,'AI deterministic decision ID is order-independent');
$policy=meteonexa_weather_improvement_policy();prop(empty($policy['automaticProductionChange']),'metric-driven tuning cannot auto-change production');prop(!empty($policy['requiresCanaryComparison']),'metric-driven tuning requires canary comparison');
$segment=['recentSamples'=>60,'priorSamples'=>60,'recentMae'=>1.7,'priorMae'=>1.0];prop(meteonexa_weather_drift_penalty($segment)>0,'material well-sampled drift is penalized');$segment['recentMae']=1.05;prop(meteonexa_weather_drift_penalty($segment)===0.0,'small drift is not penalized');$segment=['recentSamples'=>5,'priorSamples'=>60,'recentMae'=>3.0,'priorMae'=>1.0];prop(meteonexa_weather_drift_penalty($segment)===0.0,'low-sample drift cannot be penalized');
echo "Property weather invariants: PASS (randomized deterministic seed)\n";
