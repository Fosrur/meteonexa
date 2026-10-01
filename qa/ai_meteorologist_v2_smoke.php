<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/api/database/driver.php';
require_once $root.'/api/database/schema.php';
require_once $root.'/api/database/migrations.php';
require_once $root.'/api/ai/meteorologist_v2.php';
$fail=[];$ok=static function(bool $c,string $m)use(&$fail){echo ($c?'[ OK ] ':'[FAIL] ').$m."\n";if(!$c)$fail[]=$m;};
$orchestration=[
 'tools'=>[
   'current_forecast'=>['temperature'=>18.4,'rainProbabilityPct'=>32],
   'model_consensus'=>['modelsAvailable'=>6,'agreementPct'=>76],
   'nowcast'=>['etaMinutes'=>25,'rainProbabilityPct'=>61],
   'official_warnings'=>['available'=>false],
   'route'=>['available'=>true,'risk'=>'low'],
   'trust_score'=>['score'=>82],
   'forecast_change'=>['material'=>true],
 ],
 'decision'=>['status'=>'caution','confidence'=>78,'dominantRisk'=>'rain','nowcast'=>['etaMinutes'=>25],'generatedAt'=>'2026-10-01T08:00:00Z'],
 'toolRun'=>['executed'=>['current_forecast','model_consensus','nowcast','official_warnings','route','trust_score','forecast_change'],'generatedAt'=>'2026-10-01T08:00:00Z'],
 'sources'=>[['id'=>'forecast','type'=>'weather','available'=>true],['id'=>'radar','type'=>'nowcast','available'=>true]],
];
$c=meteonexa_ai_v2_contract($orchestration,'it','assistant');
$ok(($c['contractVersion']??'')==='ai-meteorologist-2.0','contract version');
foreach(['asOf','decisionId','confidence','sources','limitations','tools','policy'] as $k)$ok(array_key_exists($k,$c),"mandatory metadata $k");
foreach(['current_forecast','model_consensus','nowcast','official_warnings','route','trust_score','forecast_change'] as $tool)$ok(isset($c['tools'][$tool]['contract']['type']),"typed tool $tool");
$ok(($c['policy']['deterministicDecisionAuthoritative']??false)===true && ($c['policy']['llmMayInventNumbers']??true)===false,'deterministic authority policy');
$c2=meteonexa_ai_v2_contract($orchestration,'it','assistant');
$ok($c['decisionId']===$c2['decisionId'],'decision id deterministic');
$ok((meteonexa_ai_v2_grounding_check('Pioggia possibile; confidenza 78%. ETA 25 minuti.',$c)['ok']??false)===true,'grounded numeric answer accepted');
$bad=meteonexa_ai_v2_grounding_check('Pioggia al 97% tra 13 minuti.',$c);
$ok(($bad['ok']??true)===false && ($bad['reason']??'')==='numeric_claim_not_grounded','ungrounded numeric claim rejected');
foreach(['it','en','es','fr','de'] as $lang){$f=meteonexa_ai_v2_fallback($c,$lang);$ok($f!==''&&str_contains($f,'78'),"deterministic fallback $lang");}
$h1=meteonexa_ai_v2_context_hash($c,'Pioverà tra poco?');$h2=meteonexa_ai_v2_context_hash($c,'  Pioverà   tra poco? ');$ok($h1===$h2,'semantic cache normalizes equivalent question');
$ok(!str_contains(json_encode($c),'44.123456')&&!str_contains(json_encode($c),'9.123456'),'AI contract does not require precise coordinates');
if(in_array('sqlite',PDO::getAvailableDrivers(),true)){
 $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $pdo->exec('CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)');
 $pdo->exec("INSERT INTO app_metadata VALUES('schema_version','32','2026-10-01T00:00:00Z')");
 $m=require $root.'/api/database/migrations/0033_ai_meteorologist_v2.php';
 $v=($m['up'])($pdo,32,'sqlite');
 $ok($v===33 && meteonexa_db_table_exists($pdo,'ai_semantic_cache'),'schema 33 AI semantic cache');
 meteonexa_ai_v2_cache_put($pdo,$c,'Pioverà tra poco?','Risposta grounded 78 e 25',180);
 $hit=meteonexa_ai_v2_cache_get($pdo,$c,'Pioverà tra poco?');
 $ok(is_array($hit)&&($hit['answer']??'')==='Risposta grounded 78 e 25','fresh equivalent context cache hit');
 $miss=meteonexa_ai_v2_cache_get($pdo,$c,'Domanda diversa');$ok($miss===null,'non-equivalent question cache miss');
}
if($fail){fwrite(STDERR,'AI Meteorologist 2.0 smoke FAILED: '.implode(', ',$fail)."\n");exit(1);} echo "AI Meteorologist 2.0 smoke PASS\n";
