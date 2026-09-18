<?php
declare(strict_types=1);

function meteonexa_decision_profiles(): array
{
    return [
        'run'=>['rain'=>.50,'storm'=>1.00,'wind'=>.28,'tempMin'=>2,'tempMax'=>30,'tempWeight'=>4.0,'custom'=>'run'],
        'bike'=>['rain'=>.66,'storm'=>1.00,'wind'=>.50,'tempMin'=>4,'tempMax'=>32,'tempWeight'=>3.2,'custom'=>'bike'],
        'motorcycle'=>['rain'=>.70,'storm'=>1.05,'wind'=>.52,'tempMin'=>5,'tempMax'=>34,'tempWeight'=>3.0,'custom'=>'motorcycle'],
        'sea'=>['rain'=>.30,'storm'=>1.20,'wind'=>.72,'tempMin'=>18,'tempMax'=>36,'tempWeight'=>7.0,'custom'=>'sea'],
        'trekking'=>['rain'=>.48,'storm'=>1.05,'wind'=>.34,'tempMin'=>0,'tempMax'=>30,'tempWeight'=>3.5,'custom'=>'trekking'],
        'kids'=>['rain'=>.58,'storm'=>1.10,'wind'=>.30,'tempMin'=>5,'tempMax'=>30,'tempWeight'=>6.0,'custom'=>'kids'],
        'pets'=>['rain'=>.48,'storm'=>1.00,'wind'=>.30,'tempMin'=>0,'tempMax'=>31,'tempWeight'=>4.0,'custom'=>'pets'],
        'worksite'=>['rain'=>.58,'storm'=>1.10,'wind'=>.48,'tempMin'=>-5,'tempMax'=>34,'tempWeight'=>4.8,'custom'=>'worksite'],
        'commute'=>['rain'=>.34,'storm'=>.78,'wind'=>.18,'tempMin'=>-10,'tempMax'=>40,'tempWeight'=>1.3,'custom'=>'commute'],
        'event'=>['rain'=>.62,'storm'=>1.05,'wind'=>.38,'tempMin'=>5,'tempMax'=>32,'tempWeight'=>3.5,'custom'=>'event'],
        'photography'=>['rain'=>.42,'storm'=>.95,'wind'=>.26,'tempMin'=>-5,'tempMax'=>36,'tempWeight'=>1.5,'custom'=>'photography'],
    ];
}

function meteonexa_decision_hour_score(array $row,array $profile,?array $custom,array $nowcastV2): array
{
    $available=max(1,(int)($row['available']??0));
    $rain=is_numeric($row['rainWeightedPct']??null)?(float)$row['rainWeightedPct']:(100*(int)($row['rainVotes']??0)/$available);
    $storm=is_numeric($row['stormWeightedPct']??null)?(float)$row['stormWeightedPct']:(100*(int)($row['stormVotes']??0)/$available);
    $gust=(float)($row['windGustMax']??0);$temp=is_numeric($row['temperatureMean']??null)?(float)$row['temperatureMean']:null;
    if($custom){
        $penalty=max(0,$rain-(float)($custom['rainMax']??35))*1.45+$storm*(float)$profile['storm']+max(0,$gust-(float)($custom['gustMax']??45))*2.2;
        $minT=(float)($custom['tempMin']??$profile['tempMin']);$maxT=(float)($custom['tempMax']??$profile['tempMax']);
    }else{
        $penalty=$rain*(float)$profile['rain']+$storm*(float)$profile['storm']+max(0,$gust-20)*(float)$profile['wind'];
        $minT=(float)$profile['tempMin'];$maxT=(float)$profile['tempMax'];
    }
    if($temp!==null){$delta=$temp<$minT?$minT-$temp:($temp>$maxT?$temp-$maxT:0);$penalty+=$delta*(float)$profile['tempWeight'];}
    $rowTs=meteonexa_intel_time_utc((string)($row['time']??''));$lead=$rowTs===null?9999:($rowTs-time())/60;
    $impact=(int)($nowcastV2['impactProbability']??0);
    if($lead>=-30&&$lead<=90&&$impact>=50)$penalty+=($impact-45)*.55;
    $score=(int)round(meteonexa_intel_clamp(100-$penalty/3,0,100));
    $status=$score>=75?'good':($score>=50?'caution':'avoid');
    $risk='none';$riskValue=0.0;
    foreach(['storm'=>$storm,'rain'=>$rain,'wind'=>max(0,$gust-20)*2] as $id=>$value)if($value>$riskValue){$risk=$id;$riskValue=$value;}
    if($temp!==null&&($temp<$minT||$temp>$maxT)&&abs($temp-($temp<$minT?$minT:$maxT))*8>$riskValue)$risk='temperature';
    if($lead>=-30&&$lead<=90&&$impact>=50&&$impact>$riskValue)$risk='nowcast';
    return ['time'=>$row['time']??null,'score'=>$score,'status'=>$status,'risk'=>$risk,'rainPct'=>(int)round($rain),'stormPct'=>(int)round($storm),'gustKmh'=>(int)round($gust),'temperature'=>$temp===null?null:round($temp,1),'modelsAvailable'=>$available];
}

function meteonexa_decision_best_ranges(array $timeline): array
{
    $candidates=[];$count=count($timeline);
    for($i=0;$i<$count;$i++){
        $slice=array_slice($timeline,$i,min(3,$count-$i));if(count($slice)<2)continue;
        $avg=(int)round(array_sum(array_column($slice,'score'))/count($slice));
        $start=meteonexa_intel_time_utc((string)($slice[0]['time']??''));$last=end($slice);$end=meteonexa_intel_time_utc((string)($last['time']??''));
        $candidates[]=['score'=>$avg,'startsAt'=>$start?gmdate('c',$start):($slice[0]['time']??null),'endsAt'=>$end?gmdate('c',$end+3600):($last['time']??null),'startIndex'=>$i,'endIndex'=>$i+count($slice)-1];
    }
    usort($candidates,static fn($a,$b)=>$b['score']<=>$a['score']);$best=$candidates[0]??null;$alternative=null;
    if($best)foreach($candidates as $candidate){if($candidate['endIndex']<$best['startIndex']-1||$candidate['startIndex']>$best['endIndex']+1){$alternative=$candidate;break;}}
    foreach([$best,$alternative] as &$range)if(is_array($range)){unset($range['startIndex'],$range['endIndex']);}
    return ['best'=>$best,'alternative'=>$alternative];
}

function meteonexa_decision_timeline(array $consensus,array $activityProfiles,array $nowcastV2,array $confidenceV2,array $decisionWindows=[]): array
{
    $hourly=array_slice((array)($consensus['hourly']??[]),0,24);if(!$hourly)return ['available'=>false,'method'=>'decision-timeline-v1','activities'=>[]];
    $profiles=meteonexa_decision_profiles();$windows=[];foreach($decisionWindows as $row)$windows[(string)($row['activity']??'')]=$row;
    $confidenceRows=(array)($confidenceV2['timeline']??[]);$activities=[];
    foreach($profiles as $activity=>$profile){$customKey=(string)$profile['custom'];$custom=is_array($activityProfiles[$customKey]??null)?$activityProfiles[$customKey]:null;$timeline=[];
        foreach($hourly as $index=>$row){$point=meteonexa_decision_hour_score($row,$profile,$custom,$nowcastV2);$point['confidence']=(int)($confidenceRows[$index]['score']??$confidenceV2['score']??0);$timeline[]=$point;}
        $ranges=meteonexa_decision_best_ranges($timeline);$legacy=$windows[$activity]??null;if(is_array($legacy)&&!empty($legacy['startsAt']))$ranges['best']=['score'=>(int)($legacy['score']??$ranges['best']['score']??0),'startsAt'=>$legacy['startsAt'],'endsAt'=>$legacy['endsAt']??null];
        $activities[$activity]=['activity'=>$activity,'timeline'=>$timeline,'bestWindow'=>$ranges['best'],'alternativeWindow'=>$ranges['alternative'],'basis'=>'weather-only'];
    }
    return ['available'=>$activities!==[],'method'=>'decision-timeline-v1','horizonHours'=>24,'activities'=>$activities,'defaultActivity'=>'run','generatedAt'=>gmdate('c')];
}

function meteonexa_forecast_change_entry(array $comparison,string $at): ?array
{
    if(empty($comparison['available']))return null;
    $shift=(int)($comparison['timeShiftMinutes']??0);$rain=(int)($comparison['rainProbabilityDelta']??0);$agreement=(int)($comparison['agreementDelta']??0);$typeChanged=!empty($comparison['typeChanged']);
    $reasons=[];if($typeChanged)$reasons[]='type';if(abs($shift)>=30)$reasons[]='timing';if(abs($rain)>=15)$reasons[]='rain_probability';if(abs($agreement)>=15)$reasons[]='model_agreement';
    if(!$reasons)return null;
    $impact=($typeChanged?35:0)+min(30,abs($shift)/3)+min(25,abs($rain))+min(20,abs($agreement));
    return ['at'=>$at,'kind'=>'forecast','reasons'=>$reasons,'impactScore'=>(int)round(min(100,$impact)),'timeShiftMinutes'=>$shift,'rainProbabilityDelta'=>$rain,'agreementDelta'=>$agreement,'typeChanged'=>$typeChanged,'fromType'=>$comparison['previous']['type']??null,'toType'=>$comparison['current']['type']??null,'startsAt'=>$comparison['current']['startsAt']??null];
}

function meteonexa_forecast_change_timeline(PDO $pdo,string $deviceId,string $locationKey,array $currentChange,array $nowcastV2): array
{
    if(!meteonexa_db_table_exists($pdo,'forecast_run_snapshots'))return ['available'=>false,'method'=>'forecast-change-v2','events'=>[]];
    $rows=[];try{$st=$pdo->prepare('SELECT snapshot_json,created_at FROM forecast_run_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC LIMIT 36');$st->execute([':device'=>$deviceId,':location'=>$locationKey]);$rows=array_reverse($st->fetchAll());}catch(Throwable $ignored){}
    $events=[];$previous=null;
    foreach($rows as $row){$snapshot=json_decode((string)($row['snapshot_json']??''),true);if(!is_array($snapshot))continue;if($previous){$comparison=meteonexa_intelq_compare_snapshots($previous,$snapshot);$entry=meteonexa_forecast_change_entry($comparison,(string)($row['created_at']??''));if($entry)$events[]=$entry;}$previous=$snapshot;}
    if($rows===[]&& !empty($currentChange['available'])){$entry=meteonexa_forecast_change_entry($currentChange,gmdate('c'));if($entry)$events[]=$entry;}
    $impact=(int)($nowcastV2['impactProbability']??0);$eta=$nowcastV2['etaMinutes']??null;$confidence=(int)($nowcastV2['confidence']??0);
    if(!empty($nowcastV2['available'])&&$impact>=60&&$confidence>=55){$events[]=['at'=>$nowcastV2['generatedAt']??gmdate('c'),'kind'=>'nowcast_confirmation','reasons'=>['radar_confirmation'],'impactScore'=>$impact,'etaMinutes'=>$eta,'etaRangeMinutes'=>$nowcastV2['etaRangeMinutes']??null,'confidence'=>$confidence];}
    usort($events,static fn($a,$b)=>(strtotime((string)($b['at']??''))?:0)<=>(strtotime((string)($a['at']??''))?:0));$events=array_slice($events,0,12);
    $latest=$events[0]??null;$notify=false;if($latest){$age=time()-(strtotime((string)($latest['at']??''))?:0);$notify=$age<=3600&&(int)($latest['impactScore']??0)>=40;}
    return ['available'=>$events!==[],'method'=>'forecast-change-v2','events'=>$events,'notifyRecommended'=>$notify,'noiseFilter'=>['timeShiftMinutes'=>30,'rainProbabilityDelta'=>15,'agreementDelta'=>15],'generatedAt'=>gmdate('c')];
}
