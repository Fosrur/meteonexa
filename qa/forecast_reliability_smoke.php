<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/api/intelligence/reliability_helpers.php';
$levels=[0=>'initial',9=>'initial',10=>'preliminary',29=>'preliminary',30=>'building',99=>'building',100=>'consolidated'];
foreach($levels as $n=>$expected){$got=meteonexa_reliability_sample_level($n);if(($got['id']??'')!==$expected)throw new RuntimeException("level $n");}
if(!empty(meteonexa_reliability_sample_level(29)['publishable'])||empty(meteonexa_reliability_sample_level(30)['publishable']))throw new RuntimeException('publishability threshold');
$ci=meteonexa_wilson_interval(.70,40);if(count($ci)!==2||$ci[0]>=.70||$ci[1]<=.70||$ci[0]<0||$ci[1]>1)throw new RuntimeException('Wilson interval');
$src=file_get_contents($root.'/api/calibration/worker.php').file_get_contents($root.'/api/calibration/helpers.php');if(str_contains($src,'meteonexa_calibration_observation($weather)')||!str_contains($src,'meteonexa_observations_collect'))throw new RuntimeException('worker truth source is not independent');
echo "Forecast Reliability smoke PASS\n";
