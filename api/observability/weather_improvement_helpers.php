<?php
declare(strict_types=1);


function meteonexa_weather_improvement_policy(): array { return [
    'minimumDriftSamplesPerWindow'=>30,
    'relativeMaeDriftThresholdPct'=>15.0,
    'absoluteMaeDriftThreshold'=>0.5,
    'maximumCandidateWeightPenaltyPct'=>35.0,
    'automaticProductionChange'=>false,
    'requiresCanaryComparison'=>true,
]; }

function meteonexa_weather_normalize_weights(array $weights): array {
    $clean=[]; foreach($weights as $k=>$v) if(is_numeric($v)&&$v>=0)$clean[(string)$k]=(float)$v;
    $sum=array_sum($clean); if($sum<=0)return $clean;
    foreach($clean as $k=>$v)$clean[$k]=round($v/$sum,6);
    return $clean;
}

function meteonexa_weather_drift_penalty(array $segment): float {
    $p=meteonexa_weather_improvement_policy();
    $recent=(int)($segment['recentSamples']??0);$prior=(int)($segment['priorSamples']??0);
    $recentMae=$segment['recentMae']??null;$priorMae=$segment['priorMae']??null;
    if($recent<(int)$p['minimumDriftSamplesPerWindow']||$prior<(int)$p['minimumDriftSamplesPerWindow']||!is_numeric($recentMae)||!is_numeric($priorMae))return 0.0;
    $delta=(float)$recentMae-(float)$priorMae;$threshold=max((float)$p['absoluteMaeDriftThreshold'],(float)$priorMae*((float)$p['relativeMaeDriftThresholdPct']/100));
    if($delta<=$threshold)return 0.0;
    $excess=($delta-$threshold)/max(.01,$threshold);
    return round(min((float)$p['maximumCandidateWeightPenaltyPct']/100, .15 + .20*min(1.0,$excess)),4);
}

function meteonexa_weather_weight_candidate(array $weights,array $segments,string $locationKey,string $metric,int $horizonHours): array {
    $base=meteonexa_weather_normalize_weights($weights);$candidate=$base;$penalties=[];
    foreach($segments as $s){
        if((string)($s['locationKey']??'')!==$locationKey||(string)($s['metric']??'')!==$metric||(int)($s['horizonHours']??0)!==$horizonHours)continue;
        $model=(string)($s['model']??'');if($model===''||!array_key_exists($model,$candidate))continue;
        $penalty=meteonexa_weather_drift_penalty($s);if($penalty<=0)continue;
        $candidate[$model]*=(1.0-$penalty);$penalties[$model]=$penalty;
    }
    $candidate=meteonexa_weather_normalize_weights($candidate);
    return ['baseWeights'=>$base,'candidateWeights'=>$candidate,'penalties'=>$penalties,'changed'=>!empty($penalties),'productionApplied'=>false,'requiresCanaryComparison'=>true];
}

function meteonexa_weather_improvement_plan(array $providerDashboard,array $sloDashboard,array $driftDashboard): array {
    $items=[];
    foreach((array)($providerDashboard['providers']??[]) as $provider){
        $status=(string)($provider['status']??'');$lat=(int)($provider['latencyMs']??0);$errors=(int)($provider['errorStreak']??0);
        if(in_array($status,['ok','disabled','not_configured'],true)&&$errors===0)continue;
        $score=min(100,45+$errors*10+($lat>6000?20:0));
        $items[]=['type'=>'provider-reliability','key'=>(string)($provider['id']??'unknown'),'priorityScore'=>$score,'evidence'=>['status'=>$status,'latencyMs'=>$lat,'errorStreak'=>$errors],'action'=>'investigate-provider-latency-errors'];
    }
    foreach((array)($sloDashboard['services']??[]) as $name=>$service){
        if(!empty($service['meetingTarget']))continue;
        $samples=(int)($service['samples']??0);if($samples<10)continue;
        $items[]=['type'=>'slo','key'=>(string)$name,'priorityScore'=>70,'evidence'=>['samples'=>$samples,'availabilityPct'=>$service['availabilityPct']??null,'p95LatencyMs'=>$service['p95LatencyMs']??null],'action'=>'profile-and-reduce-critical-path'];
    }
    foreach((array)($driftDashboard['segments']??[]) as $segment){
        $penalty=meteonexa_weather_drift_penalty($segment);if($penalty<=0)continue;
        $items[]=['type'=>'model-drift','key'=>(string)($segment['locationKey']??'').'|'.(string)($segment['model']??'').'|'.(string)($segment['metric']??'').'|'.(int)($segment['horizonHours']??0),'priorityScore'=>(int)round(60+$penalty*100),'evidence'=>$segment,'action'=>'evaluate-drift-penalized-weight-candidate'];
    }
    usort($items,static fn($a,$b)=>(int)$b['priorityScore']<=>(int)$a['priorityScore']);
    return ['available'=>true,'items'=>array_slice($items,0,50),'topCount'=>min(50,count($items)),'automaticProductionChange'=>false,'requiresCanaryComparison'=>true,'generatedAt'=>gmdate('c')];
}
