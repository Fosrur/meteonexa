<?php
declare(strict_types=1);

/**
 * MeteoNexa Severe Outlook 2.0.
 *
 * Deterministic predictive risk layer. It is deliberately separate from
 * official warnings: a consensus signal can suggest thunderstorms/strong wind
 * but can never be labelled as a MeteoAlarm warning.
 */
require_once __DIR__.'/engine_helpers.php';
require_once __DIR__.'/quality_helpers.php';

function meteonexa_severe_bound(float $value,float $min,float $max): float{return max($min,min($max,$value));}
function meteonexa_severe_fresh_model_ids(array $models): array
{
    $ids=[];
    foreach($models as $id=>$model){
        if(empty($model['available'])||!empty($model['stale']))continue;
        $age=$model['ageMinutes']??0;$cadence=max(60,(int)($model['cadenceMinutes']??360));
        if(is_numeric($age)&&(float)$age>max(180,$cadence*2))continue;
        $ids[(string)$id]=true;
    }
    return $ids;
}
function meteonexa_severe_filtered_vote(array $map,array $freshIds): array
{
    $available=0;$votes=0;$agree=[];$out=[];
    foreach($freshIds as $id=>$_){if(!array_key_exists($id,$map))continue;$available++;if(!empty($map[$id])){$votes++;$agree[]=$id;}else$out[]=$id;}
    return ['available'=>$available,'votes'=>$votes,'agreementPct'=>$available?(int)round(100*$votes/$available):0,'agreeingModels'=>$agree,'outliers'=>$out];
}
function meteonexa_severe_wind_vote(array $map,array $freshIds,float $threshold): array
{
    $available=0;$votes=0;$values=[];$agree=[];$out=[];
    foreach($freshIds as $id=>$_){$value=$map[$id]??null;if(!is_numeric($value))continue;$available++;$value=(float)$value;$values[]=$value;if($value>=$threshold){$votes++;$agree[]=$id;}else$out[]=$id;}
    sort($values,SORT_NUMERIC);$median=null;$n=count($values);if($n){$m=intdiv($n,2);$median=$n%2?$values[$m]:($values[$m-1]+$values[$m])/2;}
    return ['available'=>$available,'votes'=>$votes,'agreementPct'=>$available?(int)round(100*$votes/$available):0,'agreeingModels'=>$agree,'outliers'=>$out,'max'=>$values?max($values):null,'mean'=>$values?array_sum($values)/count($values):null,'median'=>$median];
}
function meteonexa_severe_window_rows(array $rows,string $kind): array
{
    $windows=[];$current=null;
    foreach($rows as $row){
        if(empty($row['hit'])){if($current!==null){$windows[]=$current;$current=null;}continue;}
        if($current===null)$current=['kind'=>$kind,'rows'=>[]];$current['rows'][]=$row;
    }
    if($current!==null)$windows[]=$current;
    foreach($windows as &$window){
        $first=$window['rows'][0];$last=$window['rows'][count($window['rows'])-1];
        $start=meteonexa_intel_time_utc((string)($first['time']??''));$end=meteonexa_intel_time_utc((string)($last['time']??''));
        $best=$first;foreach($window['rows'] as $r)if((int)($r['agreementPct']??0)>(int)($best['agreementPct']??0))$best=$r;
        $window['startsAt']=$start?gmdate('c',$start):($first['time']??null);$window['endsAt']=$end?gmdate('c',$end+3600):($last['time']??null);
        $window['agreementPct']=(int)($best['agreementPct']??0);$window['votes']=(int)($best['votes']??0);$window['available']=(int)($best['available']??0);
        $window['agreeingModels']=$best['agreeingModels']??[];$window['outliers']=$best['outliers']??[];
        $window['peak']=$kind==='wind'?max(array_map(static fn($r)=>(float)($r['max']??0),$window['rows'])):null;
        $window['median']=$kind==='wind'?max(array_map(static fn($r)=>(float)($r['median']??0),$window['rows'])):null;
        unset($window['rows']);
    }
    unset($window);return $windows;
}
function meteonexa_severe_outlook(array $analysis,array $consensus,array $models,array $lightning=[],int $hours=72,float $windThreshold=55.0): array
{
    $fresh=meteonexa_severe_fresh_model_ids($models);$freshCount=count($fresh);$rows=[];$now=time();
    foreach(array_slice((array)($consensus['hourly']??[]),0,max(24,min(96,$hours))) as $row){
        $ts=meteonexa_intel_time_utc((string)($row['time']??''));if($ts!==null&&$ts<$now-1800)continue;
        $storm=meteonexa_severe_filtered_vote((array)($row['stormModels']??[]),$fresh);
        $wind=meteonexa_severe_wind_vote((array)($row['windGustModels']??[]),$fresh,$windThreshold);
        $stormNeed=max(2,(int)ceil(max(1,$storm['available'])*.50));$windNeed=max(2,(int)ceil(max(1,$wind['available'])*.50));
        $rows[]=['time'=>$row['time']??null,
            'storm'=>['hit'=>$storm['available']>=3&&$storm['votes']>=$stormNeed]+$storm,
            'wind'=>['hit'=>$wind['available']>=3&&$wind['votes']>=$windNeed]+$wind,
        ];
    }
    $stormRows=[];$windRows=[];foreach($rows as $r){$stormRows[]=['time'=>$r['time']]+$r['storm'];$windRows[]=['time'=>$r['time']]+$r['wind'];}
    $stormWindows=meteonexa_severe_window_rows($stormRows,'storm');$windWindows=meteonexa_severe_window_rows($windRows,'wind');$events=[];
    $modelsExpected=max(1,count(meteonexa_intelq_model_definitions()));
    $coverage=min(100,(int)round(100*$freshCount/$modelsExpected));
    if($stormWindows){
        $w=$stormWindows[0];$agreement=(int)$w['agreementPct'];$confidence=(int)round(meteonexa_severe_bound($agreement*.78+$coverage*.22,45,98));
        $nearest=is_numeric($lightning['nearestKm']??null)?(float)$lightning['nearestKm']:null;$recent=(int)($lightning['recent30m']??0);
        $startTs=meteonexa_intel_time_utc((string)($w['startsAt']??''));$hoursAway=$startTs===null?99:max(0,($startTs-$now)/3600);
        if($recent>0&&$nearest!==null&&$nearest<=40&&$hoursAway<=3)$confidence=min(99,$confidence+8);
        $severity=($agreement>=75&&($recent>0&&$nearest!==null&&$nearest<=40))?'orange':($agreement>=84?'orange':'yellow');
        $events[]=['type'=>'storm','severity'=>$severity,'confidence'=>$confidence,
            'title'=>meteonexa_backend_text('severe.outlook.storm.title'),
            'body'=>meteonexa_backend_text('severe.outlook.storm.body',['votes'=>$w['votes'],'available'=>$w['available'],'agreement'=>$agreement]),
            'horizon'=>'forecast','startsAt'=>$w['startsAt'],'endsAt'=>$w['endsAt'],'modelAgreementPct'=>$agreement,'modelVotes'=>$w['votes'],'modelsAvailable'=>$w['available'],
            'agreeingModels'=>$w['agreeingModels'],'outliers'=>$w['outliers'],'source'=>'MeteoNexa Severe Outlook 2.0','official'=>false,'predictive'=>true];
    }
    if($windWindows){
        $w=$windWindows[0];$agreement=(int)$w['agreementPct'];$peak=(float)($w['peak']??0);$median=(float)($w['median']??0);$confidence=(int)round(meteonexa_severe_bound($agreement*.78+$coverage*.22,45,98));
        $severity=$peak>=100&&$agreement>=67?'red':($peak>=75&&$agreement>=60?'orange':'yellow');
        $events[]=['type'=>'wind','severity'=>$severity,'confidence'=>$confidence,
            'title'=>meteonexa_backend_text('severe.outlook.wind.title'),
            'body'=>meteonexa_backend_text('severe.outlook.wind.body',['value'=>round($peak),'median'=>round($median),'votes'=>$w['votes'],'available'=>$w['available'],'agreement'=>$agreement]),
            'horizon'=>'forecast','startsAt'=>$w['startsAt'],'endsAt'=>$w['endsAt'],'maxWind'=>round($peak),'medianWind'=>round($median),'modelAgreementPct'=>$agreement,'modelVotes'=>$w['votes'],'modelsAvailable'=>$w['available'],
            'agreeingModels'=>$w['agreeingModels'],'outliers'=>$w['outliers'],'source'=>'MeteoNexa Severe Outlook 2.0','official'=>false,'predictive'=>true];
    }
    usort($events,static function($a,$b){$rank=['red'=>3,'orange'=>2,'yellow'=>1];$sa=$rank[$a['severity']??'yellow']??1;$sb=$rank[$b['severity']??'yellow']??1;return $sb<=>$sa ?: (($b['confidence']??0)<=>($a['confidence']??0));});
    return ['available'=>$freshCount>=3,'events'=>$events,'stormWindows'=>$stormWindows,'windWindows'=>$windWindows,'freshModels'=>$freshCount,'modelsExpected'=>$modelsExpected,'windThresholdKmh'=>$windThreshold,'method'=>'multi-model-consensus-severe-outlook-v2','officialWarning'=>false,'generatedAt'=>gmdate('c')];
}
function meteonexa_apply_severe_outlook(array $analysis,array $outlook): array
{
    $kept=[];
    foreach((array)($analysis['events']??[]) as $event){
        if(!is_array($event))continue;$type=(string)($event['type']??'');
        // Preserve immediate storm evidence when lightning/nowcast says it is
        // already nearby. Forecast-only storm/wind must pass model consensus.
        if($type==='storm'&&(string)($event['horizon']??'')==='nowcast'){$kept[]=$event;continue;}
        if(in_array($type,['storm','wind'],true))continue;$kept[]=$event;
    }
    foreach((array)($outlook['events']??[]) as $event)$kept[]=$event;
    usort($kept,static function($a,$b){$rank=['red'=>3,'orange'=>2,'yellow'=>1];$sa=$rank[$a['severity']??'green']??0;$sb=$rank[$b['severity']??'green']??0;return $sb<=>$sa ?: (($b['confidence']??0)<=>($a['confidence']??0));});
    $analysis['events']=$kept;$analysis['severeOutlook']=$outlook;$analysis['severity']=$kept[0]['severity']??'green';
    if($kept){$analysis['confidence']=(int)round(array_sum(array_map(static fn($e)=>(int)($e['confidence']??0),$kept))/count($kept));$analysis['summary']=(string)($kept[0]['title']??'').': '.(string)($kept[0]['body']??'');}
    return $analysis;
}
