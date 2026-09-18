<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/intelligence/engine_helpers.php';
require_once dirname(__DIR__).'/api/intelligence/convective_v4.php';
$fixture=json_decode((string)file_get_contents(__DIR__.'/fixtures/weather-replay.json'),true,512,JSON_THROW_ON_ERROR);
function replay_grid(int $size,array $blocks): array {
    $grid=array_fill(0,$size,array_fill(0,$size,0.0));$sum=0.0;$sx=0.0;$sy=0.0;
    foreach($blocks as $b){[$x,$y,$v]=$b;foreach([[0,0],[1,0],[0,1],[1,1]] as [$dx,$dy]){$xx=$x+$dx;$yy=$y+$dy;if($xx<0||$yy<0||$xx>=$size||$yy>=$size)continue;$grid[$yy][$xx]=(float)$v;$sum+=(float)$v;$sx+=$xx*(float)$v;$sy+=$yy*(float)$v;}}
    return ['size'=>$size,'grid'=>$grid,'sum'=>$sum,'cx'=>$sum>0?$sx/$sum:null,'cy'=>$sum>0?$sy/$sum:null];
}
function replay_frames(array $case): array { $out=[]; foreach($case['frames'] as $f)$out[]=['time'=>(int)$f['time'],'grid'=>replay_grid((int)$case['size'],$f['blocks'])]; return $out; }
$moving=meteonexa_radar_object_tracks_v3(replay_frames($fixture['radar']['moving_cell']));
$two=meteonexa_radar_object_tracks_v3(replay_frames($fixture['radar']['two_cells']));
$binary=meteonexa_binary_metrics($fixture['binary']);
$ground=meteonexa_radar_independent_arrival([],['observedAt'=>gmdate('c'),'archiveDistanceKm'=>2.0,'centerSignal'=>0.42],[]);
$cal=meteonexa_convective_calibrate_score(74.0,[7=>['n'=>40,'hits'=>30,'observedRatePct'=>72.7,'publishable'=>true]]);
$checks=[
    'moving radar3 available'=>!empty($moving['available']),
    'moving radar3 has trajectory'=>count((array)($moving['cells'][0]['trajectoryCone']??[]))===5,
    'two cells stay separate'=>!empty($two['available']) && (int)($two['cellCount']??0)>=2,
    'binary confusion complete'=>(int)$binary['tp']===2 && (int)$binary['fp']===1 && (int)$binary['fn']===1 && (int)$binary['tn']===2,
    'binary precision'=>abs((float)$binary['precisionPct']-66.7)<0.11,
    'independent radar ground truth'=>!empty($ground['available']) && !empty($ground['arrived']) && ($ground['source']??'')==='radar-center-observation',
    'convective calibrated'=>($cal['mode']??'')==='locally-calibrated' && (int)($cal['samples']??0)===40,
];
$failed=array_keys(array_filter($checks,static fn($ok)=>!$ok));
if($failed){fwrite(STDERR,"weather replay failed: ".implode(', ',$failed)."\n");exit(1);}echo "weather replay PASS\n";
