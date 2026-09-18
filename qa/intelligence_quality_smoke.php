<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/intelligence/quality_helpers.php';

$failures=[];
function quality_check(bool $condition,string $label):void{global $failures;if($condition)echo "[ OK ] {$label}\n";else{echo "[FAIL] {$label}\n";$failures[]=$label;}}
$base=(int)(floor(time()/3600)*3600);$models=[];
foreach(meteonexa_intelq_model_definitions() as $id=>$definition){$rows=[];for($i=0;$i<12;$i++)$rows[]=['time'=>gmdate('c',$base+$i*3600),'timestamp'=>$base+$i*3600,'temperature'=>20+$i*.1,'precipitation'=>($i>=2&&$i<=5)?.8:0.0,'weatherCode'=>($i>=2&&$i<=5)?95:1,'windGust'=>24+$i];$models[$id]=['id'=>$id,'label'=>$definition['label'],'available'=>true,'rows'=>$rows,'retrievedAt'=>gmdate('c'),'ageMinutes'=>0,'cadenceMinutes'=>$definition['cadenceMinutes']];}
foreach($models['ukmo']['rows'] as &$row){$row['precipitation']=0.0;$row['weatherCode']=1;}unset($row);
$consensus=meteonexa_intelq_consensus($models);$primary=(array)($consensus['primary']??[]);
$expectedModels=count(meteonexa_intelq_model_definitions());
quality_check(($consensus['modelsExpected']??0)===$expectedModels,'consensus expected source count follows provider definitions');
quality_check(($consensus['modelsAvailable']??0)===$expectedModels,'consensus sees all synthetic provider sources');
quality_check(($primary['votes']??0)===5,'consensus records explicit 5/6 storm vote');
quality_check(($primary['agreementPct']??0)===83,'5/6 vote renders rounded 83% agreement');
quality_check(!empty($primary['startsAt'])&&!empty($primary['endsAt']),'consensus event includes time window');
quality_check(in_array('ukmo',(array)($primary['outliers']??[]),true),'outlier model is explicit');
quality_check(count(array_filter((array)($primary['modelVotes']??[])))===(int)($primary['votes']??0),'headline vote count matches rendered model chips');
quality_check((int)($primary['agreementPct']??0)===(int)round(100*(int)($primary['votes']??0)/max(1,(int)($primary['available']??0))),'headline agreement is the exact representative vote ratio');
quality_check(abs(meteonexa_intelq_brier(.8,1)-.04)<.0001,'Brier score calculation');
quality_check(meteonexa_intelq_nearest_horizon(gmdate('c',time()+23*3600))===24,'calibration maps event to nearest lead-time bucket');
$polygon=['type'=>'Polygon','coordinates'=>[[[8.8,44.0],[9.8,44.0],[9.8,45.0],[8.8,45.0],[8.8,44.0]]]];
quality_check(meteonexa_intelq_geometry_contains($polygon,44.5,9.2),'GeoJSON point-in-polygon accepts inside point');
quality_check(!meteonexa_intelq_geometry_contains($polygon,43.5,9.2),'GeoJSON point-in-polygon rejects outside point');
$decisions=meteonexa_intelq_decision_windows($models);
quality_check(count($decisions)>=9,'Decision Engine returns activity windows');
$ski=array_values(array_filter($decisions,static fn($row)=>($row['activity']??'')==='ski'));
quality_check($ski!==[] && (int)$ski[0]['score']<30,'Decision Engine does not rate skiing highly without snow/cold support');
$frames=[];for($f=0;$f<4;$f++){$frames[]=['time'=>$base+$f*300,'grid'=>['size'=>32],'components'=>[['mass'=>4+$f*.2,'pixels'=>8,'cx'=>5+$f*2,'cy'=>10+$f,'peak'=>.8],['mass'=>8,'pixels'=>10,'cx'=>28,'cy'=>28,'peak'=>.9]]];}
$track=meteonexa_intelq_track_cell_sequence($frames);
quality_check(count($track)===4,'cell association follows one centroid sequence across frames');
quality_check(isset($track[count($track)-1]['associationMeanJump']),'cell association exposes quality metric');

if($failures){fwrite(STDERR,"\nIntelligence Verified Trust smoke FAILED: ".count($failures)."\n");exit(1);}echo "\nIntelligence Verified Trust smoke PASS\n";
