<?php
declare(strict_types=1);
$root=dirname(__DIR__);require_once $root.'/api/observability/weather_release_helpers.php';
$fail=[];$ok=static function(bool $c,string $m)use(&$fail){echo ($c?'[ OK ] ':'[FAIL] ').$m."\n";if(!$c)$fail[]=$m;};
$defs=meteonexa_observability_slo_definitions();foreach(['home-forecast','nowcast','official-warning','ai-explanation'] as $s)$ok(isset($defs[$s]),"separate SLO $s");
$gold=meteonexa_golden_locations();$cases=[];foreach($gold as $g)foreach((array)($g['cases']??[]) as $c)$cases[$c]=true;
$ok(count($gold)>=8,'European golden-location dataset has broad coverage');foreach(['plain','mountain','coast','city'] as $c)$ok(isset($cases[$c]),"golden case $c");
$base=['samples'=>120,'mae'=>1.0,'brier'=>.12];$candidate=['samples'=>115,'mae'=>1.03,'brier'=>.13];$cmp=meteonexa_canary_compare($base,$candidate);
$ok(!empty($cmp['manualPromotionReviewEligible'])&&empty($cmp['automaticPromotion']),'canary can only open manual promotion review');
$bad=meteonexa_canary_compare($base,['samples'=>115,'mae'=>1.2,'brier'=>.18]);$ok(empty($bad['manualPromotionReviewEligible'])&&in_array('mae-regression',$bad['reasons'],true)&&in_array('brier-regression',$bad['reasons'],true),'canary rejects material metric regression');
$small=meteonexa_canary_compare(['samples'=>8,'mae'=>1.0,'brier'=>.1],['samples'=>8,'mae'=>.9,'brier'=>.09]);$ok(empty($small['manualPromotionReviewEligible'])&&in_array('insufficient-samples',$small['reasons'],true),'canary rejects statistically weak window');
if($fail){fwrite(STDERR,'P7 observability smoke FAILED: '.implode(', ',$fail)."\n");exit(1);}echo "P7 weather observability/release smoke PASS\n";
