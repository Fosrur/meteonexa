<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/api/intelligence/reliability_helpers.php';

$levels=[0=>'initial',9=>'initial',10=>'preliminary',29=>'preliminary',30=>'building',99=>'building',100=>'consolidated'];
foreach($levels as $n=>$expected){$got=meteonexa_reliability_sample_level($n);if(($got['id']??'')!==$expected)throw new RuntimeException("level $n");}
if(!empty(meteonexa_reliability_sample_level(29)['publishable'])||empty(meteonexa_reliability_sample_level(30)['publishable']))throw new RuntimeException('publishability threshold');
$ci=meteonexa_wilson_interval(.70,40);if(count($ci)!==2||$ci[0]>=.70||$ci[1]<=.70||$ci[0]<0||$ci[1]>1)throw new RuntimeException('Wilson interval');

$seasonCases=[
    '2026-01-15T12:00:00Z'=>'winter',
    '2026-04-15T12:00:00Z'=>'spring',
    '2026-07-15T12:00:00Z'=>'summer',
    '2026-10-15T12:00:00Z'=>'autumn',
];
foreach($seasonCases as $date=>$expected){if(meteonexa_reliability_season($date)!==$expected)throw new RuntimeException("season $date");}
$reference=strtotime('2026-09-24T12:00:00Z');
$w0=meteonexa_reliability_decay_weight('2026-09-24T12:00:00Z',30,$reference);
$w30=meteonexa_reliability_decay_weight('2026-08-25T12:00:00Z',30,$reference);
$w60=meteonexa_reliability_decay_weight('2026-07-26T12:00:00Z',30,$reference);
if(abs($w0-1.0)>.001||abs($w30-.5)>.015||abs($w60-.25)>.015)throw new RuntimeException('temporal decay half-life');

if(abs(meteonexa_reliability_weight_value(['ecmwf'=>.2,'gfs'=>.4],'icon')-.3)>.0001)throw new RuntimeException('neutral missing-model weight fallback');

// P1.2 shadow tournament is tested without a database as well, so the core
// promotion guardrails run on every CI/runtime even when PDO SQLite is absent.
$shadowRows=[];
$addShadowGroup=static function(array &$rows,int $ts,string $winner):void{
    $observed=(((int)gmdate('H',$ts)/6+(int)gmdate('j',$ts))%2)?1.0:0.0;
    $predictions=$winner==='gfs'
        ?['ecmwf'=>1-$observed,'gfs'=>$observed,'icon'=>$observed]
        :['ecmwf'=>$observed,'gfs'=>1-$observed,'icon'=>1-$observed];
    foreach($predictions as $model=>$prediction)$rows[]=[
        'model_name'=>$model,'metric'=>'rain','horizon_hours'=>24,'target_time'=>gmdate('c',$ts),
        'predicted_value'=>$prediction,'observed_value'=>$observed,'error_value'=>null,
        'brier_score'=>($prediction-$observed)**2,'verified_at'=>gmdate('c',$ts+3600),
    ];
};
$start=strtotime('2026-08-20T00:00:00Z');for($i=0;$i<35;$i++)$addShadowGroup($shadowRows,$start+$i*8*3600,'gfs');
$start=strtotime('2026-09-01T00:00:00Z');for($i=0;$i<30;$i++)$addShadowGroup($shadowRows,$start+$i*8*3600,'ecmwf');
$start=strtotime('2026-09-12T00:00:00Z');for($i=0;$i<25;$i++)$addShadowGroup($shadowRows,$start+$i*12*3600,'ecmwf');
$shadow=meteonexa_reliability_shadow_bucket($shadowRows,'rain',24,$reference);
if(empty($shadow['promotionEligible'])||($shadow['reason']??'')!=='eligible')throw new RuntimeException('challenger promotion guard did not accept sustained holdout improvement');
if((int)($shadow['stableWindowsWon']??0)<2||(float)($shadow['absoluteBrierImprovement']??0)<.01)throw new RuntimeException('challenger shadow evidence incomplete');
$flatRows=[];
$start=strtotime('2026-08-20T00:00:00Z');
for($i=0;$i<90;$i++){
    $ts=$start+$i*8*3600;$observed=$i%2?1.0:0.0;
    foreach(['ecmwf','gfs','icon'] as $model)$flatRows[]=['model_name'=>$model,'metric'=>'rain','horizon_hours'=>24,'target_time'=>gmdate('c',$ts),'predicted_value'=>$observed,'observed_value'=>$observed,'error_value'=>null,'brier_score'=>0.0,'verified_at'=>gmdate('c',$ts+3600)];
}
$flat=meteonexa_reliability_shadow_bucket($flatRows,'rain',24,$reference);
if(!empty($flat['promotionEligible'])||!str_starts_with((string)($flat['reason']??''),'guardrail_'))throw new RuntimeException('challenger promoted without measurable holdout gain');

if(in_array('sqlite',PDO::getAvailableDrivers(),true)){
    if(!function_exists('meteonexa_db_table_exists')){
        function meteonexa_db_table_exists(PDO $pdo,string $table):bool{
            $st=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n LIMIT 1");
            $st->execute([':n'=>$table]);
            return (bool)$st->fetchColumn();
        }
    }
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE model_skill_samples(model_name TEXT,metric TEXT,horizon_hours INTEGER,target_time TEXT,predicted_value REAL,observed_value REAL,error_value REAL,brier_score REAL,verified_at TEXT,device_id TEXT,location_key TEXT)");
    $pdo->exec("CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)");
    $ins=$pdo->prepare('INSERT INTO model_skill_samples(model_name,metric,horizon_hours,target_time,predicted_value,observed_value,error_value,brier_score,verified_at,device_id,location_key) VALUES(:model,:metric,24,:target,:pred,:obs,:err,:brier,:verified,\'device-test\',\'44.4000:8.9000\')');
    for($i=0;$i<40;$i++){
        $verified=gmdate('c',$reference-$i*12*3600);
        $target=gmdate('c',$reference-$i*12*3600);
        $ins->execute([':model'=>'ecmwf',':metric'=>'rain',':target'=>$target,':pred'=>.85,':obs'=>1,':err'=>null,':brier'=>.02,':verified'=>$verified]);
        $ins->execute([':model'=>'gfs',':metric'=>'rain',':target'=>$target,':pred'=>.15,':obs'=>1,':err'=>null,':brier'=>.72,':verified'=>$verified]);
    }
    // Old opposing evidence should be discounted and should not override current-season skill.
    for($i=0;$i<20;$i++){
        $verified=gmdate('c',$reference-(100+$i)*86400);
        $target=gmdate('c',strtotime('2026-07-15T12:00:00Z')-$i*3600);
        $ins->execute([':model'=>'ecmwf',':metric'=>'rain',':target'=>$target,':pred'=>.2,':obs'=>1,':err'=>null,':brier'=>.64,':verified'=>$verified]);
        $ins->execute([':model'=>'gfs',':metric'=>'rain',':target'=>$target,':pred'=>.8,':obs'=>1,':err'=>null,':brier'=>.04,':verified'=>$verified]);
    }
    for($i=0;$i<20;$i++){
        $p=$i<10?.20:.80;
        $o=$i<10?0.0:1.0;
        $verified=gmdate('c',$reference-$i*6*3600);
        $target=gmdate('c',$reference-$i*6*3600);
        $ins->execute([':model'=>'consensus',':metric'=>'rain',':target'=>$target,':pred'=>$p,':obs'=>$o,':err'=>null,':brier'=>($p-$o)**2,':verified'=>$verified]);
    }
    $weights=meteonexa_reliability_skill_weights($pdo,'device-test','44.4000:8.9000',$reference);
    $ecmwf=(float)($weights['rain'][24]['ecmwf']??0);
    $gfs=(float)($weights['rain'][24]['gfs']??0);
    if($ecmwf<=$gfs||abs(($ecmwf+$gfs)-1.0)>.01)throw new RuntimeException('seasonal decayed skill weights');
    $diagram=meteonexa_reliability_diagram($pdo,'device-test','44.4000:8.9000','rain',24,5,$reference);
    if(empty($diagram['available'])||count($diagram['bins']??[])<2||(int)($diagram['samples']??0)!==20)throw new RuntimeException('reliability diagram bins');
    if(!is_numeric($diagram['brier']??null)||(float)$diagram['brier']>.06)throw new RuntimeException('reliability diagram brier');
    $shadowIns=$pdo->prepare("INSERT INTO model_skill_samples(model_name,metric,horizon_hours,target_time,predicted_value,observed_value,error_value,brier_score,verified_at,device_id,location_key) VALUES(:model,'rain',24,:target,:pred,:obs,NULL,:brier,:verified,'device-shadow','45.0000:9.0000')");
    foreach($shadowRows as $row)$shadowIns->execute([':model'=>$row['model_name'],':target'=>$row['target_time'],':pred'=>$row['predicted_value'],':obs'=>$row['observed_value'],':brier'=>$row['brier_score'],':verified'=>$row['verified_at']]);
    $championRows=$pdo->query("SELECT * FROM model_skill_samples WHERE device_id='device-shadow' AND location_key='45.0000:9.0000'")->fetchAll();
    $championWeights=meteonexa_reliability_champion_weights_from_rows($championRows,$reference);
    $tournament=meteonexa_reliability_weight_tournament($pdo,'device-shadow','45.0000:9.0000',$championWeights,$reference,true);
    if(($tournament['mode']??'')!=='guarded-challenger'||(int)($tournament['promotedBuckets']??0)<1)throw new RuntimeException('champion/challenger tournament promotion');
    if(empty($tournament['activeWeights']['rain'][24])||empty($tournament['challengerWeights']['rain'][24]))throw new RuntimeException('promoted challenger weights missing');
    $cachedTournament=meteonexa_reliability_weight_tournament($pdo,'device-shadow','45.0000:9.0000',$championWeights,$reference,false);
    if(empty($cachedTournament['cacheHit'])||(int)($cachedTournament['promotedBuckets']??0)!==(int)$tournament['promotedBuckets'])throw new RuntimeException('champion/challenger cache');
}

$src=file_get_contents($root.'/api/calibration/worker.php').file_get_contents($root.'/api/calibration/helpers.php');
if(str_contains($src,'meteonexa_calibration_observation($weather)')||!str_contains($src,'meteonexa_observations_collect'))throw new RuntimeException('worker truth source is not independent');
$summarySrc=file_get_contents($root.'/api/intelligence/summary.php');
$calibrationSrc=file_get_contents($root.'/api/calibration/helpers.php');
$aiSrc=file_get_contents($root.'/api/ai/orchestrator.php');
foreach(['summary'=>$summarySrc,'calibration'=>$calibrationSrc,'ai'=>$aiSrc] as $surface=>$surfaceSrc){
    if(!str_contains($surfaceSrc,'meteonexa_reliability_weight_tournament(')||!str_contains($surfaceSrc,'meteonexa_reliability_tournament_weighting_meta('))throw new RuntimeException("champion/challenger not wired into $surface");
}
echo "Forecast Reliability smoke PASS\n";
