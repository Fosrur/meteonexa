<?php
declare(strict_types=1);

function meteonexa_personal_station_quality(array $hyperlocal,array $reference=[]):array{
 $ob=is_array($hyperlocal['observation']??null)?$hyperlocal['observation']:[];
 if(empty($hyperlocal['available'])||!$ob)return['available'=>false,'accepted'=>false,'score'=>0,'outlier'=>false,'reasons'=>['station-unavailable']];
 $score=100;$reasons=[];$outlier=false;$now=time();$ts=(int)($ob['observedAt']??0);$age=$ts>0?max(0,$now-$ts):999999;
 if($age>1800){$score-=35;$reasons[]='stale';}elseif($age>900){$score-=15;$reasons[]='aging';}
 $distance=(float)($hyperlocal['distanceKm']??999);if($distance>25){$score-=30;$reasons[]='far';}elseif($distance>10){$score-=15;$reasons[]='distance';}
 $present=0;foreach(['temperature','humidity','pressure','rain','wind','gust'] as $k)if(is_numeric($ob[$k]??null))$present++;$score-=max(0,4-$present)*8;
 $ranges=['temperature'=>[-60,60],'humidity'=>[0,100],'pressure'=>[850,1100],'rain'=>[0,300],'wind'=>[0,220],'gust'=>[0,300]];
 foreach($ranges as $k=>[$min,$max])if(is_numeric($ob[$k]??null)&&((float)$ob[$k]<$min||(float)$ob[$k]>$max)){$outlier=true;$score-=60;$reasons[]='physical-'.$k;}
 if(is_numeric($ob['temperature']??null)&&is_numeric($reference['temperature']??null)&&abs((float)$ob['temperature']-(float)$reference['temperature'])>12){$score-=30;$reasons[]='temperature-disagreement';}
 if(is_numeric($ob['gust']??null)&&is_numeric($reference['windGust']??$reference['gust']??null)&&abs((float)$ob['gust']-(float)($reference['windGust']??$reference['gust']))>70){$score-=20;$reasons[]='gust-disagreement';}
 $score=max(0,min(100,$score));return['available'=>true,'accepted'=>$score>=55&&!$outlier,'score'=>$score,'outlier'=>$outlier,'ageSeconds'=>$age,'distanceKm'=>$distance,'reasons'=>$reasons];
}

function meteonexa_personal_twin_bias_correction(array $hyperlocal,array $reference,array $context=[],int $sampleCount=0):array{
 $quality=meteonexa_personal_station_quality($hyperlocal,$reference);$ob=(array)($hyperlocal['observation']??[]);
 if(empty($quality['accepted']))return['available'=>false,'applied'=>false,'weight'=>0.0,'quality'=>$quality,'reason'=>'quality-gate'];
 $q=((int)$quality['score'])/100;$evidence=min(1.0,$sampleCount/30);$weight=min(.35,max(.05,$q*(.08+.27*$evidence)));
 $components=[];$corrected=[];
 if(is_numeric($ob['temperature']??null)&&is_numeric($reference['temperature']??null)){
   $bias=max(-3.0,min(3.0,(float)$ob['temperature']-(float)$reference['temperature']));
   $alt=0.0;if(is_numeric($context['targetElevationM']??null)&&is_numeric($context['stationElevationM']??null))$alt=max(-1.5,min(1.5,((float)$context['stationElevationM']-(float)$context['targetElevationM'])*.0065));
   $uhi=max(-1.0,min(1.0,(float)($context['urbanHeatIndex']??0)));$coast=max(-.6,min(.6,(float)($context['coastAdjustmentC']??0)));$exposure=max(-.5,min(.5,(float)($context['exposureAdjustmentC']??0)));
   $delta=max(-3.0,min(3.0,($bias+$alt-$uhi+$coast+$exposure)*$weight));$corrected['temperature']=round((float)$reference['temperature']+$delta,1);$components['temperatureDeltaC']=round($delta,2);
 }
 foreach([['gust','windGust',15.0],['wind','windSpeed',10.0]] as [$s,$r,$cap])if(is_numeric($ob[$s]??null)&&is_numeric($reference[$r]??null)){$delta=max(-$cap,min($cap,((float)$ob[$s]-(float)$reference[$r])*$weight));$corrected[$r]=round((float)$reference[$r]+$delta,1);$components[$r.'Delta']=round($delta,2);}
 if(is_numeric($ob['rain']??null)&&is_numeric($reference['rain']??$reference['precipitation']??null)){$base=(float)($reference['rain']??$reference['precipitation']);$delta=max(-10,min(10,((float)$ob['rain']-$base)*$weight));$corrected['rain']=round(max(0,$base+$delta),2);$components['rainDelta']=round($delta,2);}
 return['available'=>true,'applied'=>true,'weight'=>round($weight,3),'sampleCount'=>$sampleCount,'quality'=>$quality,'corrected'=>$corrected,'components'=>$components,'policy'=>['modelRemainsBase'=>true,'maxStationWeight'=>.35,'fewSamplesShrunk'=>true]];
}

function meteonexa_personal_station_assimilate(PDO $pdo,array $config,string $deviceId,string $locationKey,array $hyperlocal,array $reference,array $context=[]):array{
 $quality=meteonexa_personal_station_quality($hyperlocal,$reference);if(empty($quality['available']))return['available'=>false,'quality'=>$quality];
 $samples=0;if(function_exists('meteonexa_db_table_exists')&&meteonexa_db_table_exists($pdo,'personal_station_samples')){$st=$pdo->prepare('SELECT COUNT(*) FROM personal_station_samples WHERE device_id=:d AND location_key=:l AND outlier=0');$st->execute([':d'=>$deviceId,':l'=>$locationKey]);$samples=(int)$st->fetchColumn();}
 $correction=meteonexa_personal_twin_bias_correction($hyperlocal,$reference,$context,$samples);
 $ret=max(1,min(90,(int)($config['personal_twin']['retention_days']??30)));$ob=(array)($hyperlocal['observation']??[]);
 if(function_exists('meteonexa_db_table_exists')&&meteonexa_db_table_exists($pdo,'personal_station_samples')&&!empty($quality['available'])){
   $stationRef=substr(hash('sha256',(string)($hyperlocal['station']??'personal-station')),0,32);$at=(int)($ob['observedAt']??0);$observed=$at>0?gmdate('c',$at):gmdate('c');
   $payload=json_encode(['quality'=>$quality,'correction'=>$correction],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
   $params=[':d'=>$deviceId,':l'=>$locationKey,':s'=>$stationRef,':o'=>$observed,':q'=>(int)$quality['score'],':x'=>!empty($quality['outlier'])?1:0,':t'=>is_numeric($ob['temperature']??null)?(float)$ob['temperature']:null,':h'=>is_numeric($ob['humidity']??null)?(float)$ob['humidity']:null,':p'=>is_numeric($ob['pressure']??null)?(float)$ob['pressure']:null,':r'=>is_numeric($ob['rain']??null)?(float)$ob['rain']:null,':w'=>is_numeric($ob['wind']??null)?(float)$ob['wind']:null,':g'=>is_numeric($ob['gust']??null)?(float)$ob['gust']:null,':j'=>$payload,':e'=>gmdate('c',time()+$ret*86400),':c'=>gmdate('c')];
   $driver=function_exists('meteonexa_pdo_driver')?meteonexa_pdo_driver($pdo):'sqlite';
   $sql=$driver==='mysql'?'INSERT IGNORE INTO personal_station_samples(device_id,location_key,station_ref,observed_at,quality_score,outlier,temperature,humidity,pressure,rain,wind,gust,correction_json,expires_at,created_at) VALUES(:d,:l,:s,:o,:q,:x,:t,:h,:p,:r,:w,:g,:j,:e,:c)':'INSERT OR IGNORE INTO personal_station_samples(device_id,location_key,station_ref,observed_at,quality_score,outlier,temperature,humidity,pressure,rain,wind,gust,correction_json,expires_at,created_at) VALUES(:d,:l,:s,:o,:q,:x,:t,:h,:p,:r,:w,:g,:j,:e,:c)';
   $pdo->prepare($sql)->execute($params);$pdo->prepare('DELETE FROM personal_station_samples WHERE expires_at<:now')->execute([':now'=>gmdate('c')]);
 }
 return['available'=>true,'quality'=>$quality,'correction'=>$correction,'retentionDays'=>$ret,'privacy'=>['preciseCoordinatesPersisted'=>false,'stationNamePersisted'=>false,'stationReferenceHashed'=>true,'aggregateBeforeAccuracyPublication'=>true]];
}

function meteonexa_material_decision_change(PDO $pdo,string $deviceId,string $locationKey,string $activity,array $decision,bool $allowInitial=false):array{
 $status=(string)($decision['status']??'learning');$score=max(0,min(100,(int)($decision['score']??0)));$confidence=max(0,min(100,(int)($decision['confidence']??0)));$starts=(string)($decision['startsAt']??'');$ends=(string)($decision['endsAt']??'');
 $hash=hash('sha256',json_encode([$status,$score,$confidence,$starts,$ends],JSON_UNESCAPED_SLASHES)?:'[]');
 if(!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo,'material_decision_state'))return['available'=>false,'material'=>false,'notifyRecommended'=>false];
 $st=$pdo->prepare('SELECT * FROM material_decision_state WHERE device_id=:d AND location_key=:l AND activity=:a LIMIT 1');$st->execute([':d'=>$deviceId,':l'=>$locationKey,':a'=>$activity]);$prev=$st->fetch();$reasons=[];$material=false;
 if(!is_array($prev)){$material=$allowInitial;$reasons[]='baseline';}else{
   if((string)$prev['status']!==$status){$material=true;$reasons[]='status';}
   if(abs((int)$prev['score']-$score)>=15){$material=true;$reasons[]='score';}
   if(abs((int)$prev['confidence']-$confidence)>=20){$material=true;$reasons[]='confidence';}
   foreach([['starts_at',$starts],['ends_at',$ends]] as [$key,$cur]){if($cur!==''&&(string)$prev[$key]!==''){$a=strtotime((string)$prev[$key]);$b=strtotime($cur);if($a&&$b&&abs($a-$b)>=1800){$material=true;$reasons[]='timing';break;}}}
 }
 $now=gmdate('c');$changed=$material?$now:(is_array($prev)?(string)$prev['changed_at']:$now);$notified=is_array($prev)?(string)$prev['notified_at']:'';
 $driver=function_exists('meteonexa_pdo_driver')?meteonexa_pdo_driver($pdo):'sqlite';
 $params=[':d'=>$deviceId,':l'=>$locationKey,':a'=>$activity,':h'=>$hash,':s'=>$status,':score'=>$score,':confidence'=>$confidence,':start'=>$starts,':end'=>$ends,':changed'=>$changed,':notified'=>$notified,':updated'=>$now];
 $sql=$driver==='mysql'?'INSERT INTO material_decision_state(device_id,location_key,activity,decision_hash,status,score,confidence,starts_at,ends_at,changed_at,notified_at,updated_at) VALUES(:d,:l,:a,:h,:s,:score,:confidence,:start,:end,:changed,:notified,:updated) ON DUPLICATE KEY UPDATE decision_hash=VALUES(decision_hash),status=VALUES(status),score=VALUES(score),confidence=VALUES(confidence),starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),changed_at=VALUES(changed_at),updated_at=VALUES(updated_at)':'INSERT INTO material_decision_state(device_id,location_key,activity,decision_hash,status,score,confidence,starts_at,ends_at,changed_at,notified_at,updated_at) VALUES(:d,:l,:a,:h,:s,:score,:confidence,:start,:end,:changed,:notified,:updated) ON CONFLICT(device_id,location_key,activity) DO UPDATE SET decision_hash=excluded.decision_hash,status=excluded.status,score=excluded.score,confidence=excluded.confidence,starts_at=excluded.starts_at,ends_at=excluded.ends_at,changed_at=excluded.changed_at,updated_at=excluded.updated_at';
 $pdo->prepare($sql)->execute($params);
 return['available'=>true,'material'=>$material,'notifyRecommended'=>$material,'reasons'=>array_values(array_unique($reasons)),'previousStatus'=>is_array($prev)?(string)$prev['status']:null,'status'=>$status,'policy'=>['scoreDeltaMin'=>15,'confidenceDeltaMin'=>20,'timingShiftMinutesMin'=>30]];
}
