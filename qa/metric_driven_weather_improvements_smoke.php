<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/observability/weather_improvement_helpers.php';
$ok=function($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";};
$weights=['ifs'=>.4,'icon'=>.35,'gfs'=>.25];
$segments=[['locationKey'=>'area-a','model'=>'ifs','metric'=>'rain','horizonHours'=>24,'recentSamples'=>80,'priorSamples'=>80,'recentMae'=>2.2,'priorMae'=>1.0,'drift'=>'degraded']];
$c=meteonexa_weather_weight_candidate($weights,$segments,'area-a','rain',24);
$ok(!empty($c['changed'])&&$c['candidateWeights']['ifs']<$c['baseWeights']['ifs'],'P7 drift creates a lower-weight candidate for degraded model');
$ok(abs(array_sum($c['candidateWeights'])-1.0)<.00001,'candidate weights stay normalized');
$ok(empty($c['productionApplied'])&&!empty($c['requiresCanaryComparison']),'candidate never changes production automatically');
$weak=$segments;$weak[0]['recentSamples']=5;
$ok(empty(meteonexa_weather_weight_candidate($weights,$weak,'area-a','rain',24)['changed']),'low-sample drift cannot change candidate weights');
$plan=meteonexa_weather_improvement_plan(
 ['providers'=>[['id'=>'p1','status'=>'error','latencyMs'=>7000,'errorStreak'=>3]]],
 ['services'=>['nowcast'=>['samples'=>100,'meetingTarget'=>false,'availabilityPct'=>97,'p95LatencyMs'=>5000]]],
 ['segments'=>$segments]
);
$types=array_column($plan['items'],'type');
$ok(in_array('provider-reliability',$types,true)&&in_array('slo',$types,true)&&in_array('model-drift',$types,true),'P7 metrics produce ranked provider/SLO/model improvement backlog');
echo "Metric-driven weather improvements: PASS\n";
