<?php
declare(strict_types=1);
$root=dirname(__DIR__);require_once $root.'/api/bootstrap.php';require_once $root.'/api/observability/weather_release_helpers.php';
$options=getopt('', ['sha:','baseline-start:','baseline-end:','candidate-start:','candidate-end:']);
foreach(['sha','baseline-start','baseline-end','candidate-start','candidate-end'] as $k)if(trim((string)($options[$k]??''))===''){fwrite(STDERR,"Missing --$k\n");exit(2);}
$config=load_config();$pdo=meteonexa_db($config);$base=meteonexa_canary_snapshot($pdo,(string)$options['sha'],'baseline',(string)$options['baseline-start'],(string)$options['baseline-end']);$cand=meteonexa_canary_snapshot($pdo,(string)$options['sha'],'candidate',(string)$options['candidate-start'],(string)$options['candidate-end']);$comparison=meteonexa_canary_compare($base,$cand);
echo json_encode(['releaseSha'=>$options['sha'],'baseline'=>$base,'candidate'=>$cand,'comparison'=>$comparison,'generatedAt'=>gmdate('c')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit(!empty($comparison['manualPromotionReviewEligible'])?0:1);
