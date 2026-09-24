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
}

$src=file_get_contents($root.'/api/calibration/worker.php').file_get_contents($root.'/api/calibration/helpers.php');
if(str_contains($src,'meteonexa_calibration_observation($weather)')||!str_contains($src,'meteonexa_observations_collect'))throw new RuntimeException('worker truth source is not independent');
echo "Forecast Reliability smoke PASS\n";
