<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/api/account/account_helpers.php';
require $root.'/api/intelligence/confidence_helpers.php';
$p=meteonexa_account_activity_sanitize('motorcycle',['rainMax'=>17,'gustMax'=>39,'tempMin'=>7,'tempMax'=>35,'visibilityMin'=>4.5]);
if(($p['rainMax']??null)!==17||($p['gustMax']??null)!==39||abs((float)($p['visibilityMin']??0)-4.5)>.01)throw new RuntimeException('activity sanitize');
$c=meteonexa_weather_confidence(['confidence'=>82],['modelsAvailable'=>5,'primary'=>['weightedAgreementPct'=>78]],['metricVerifiedSamples'=>42,'observationQualityScore'=>86,'observationSourceCount'=>3,'independentObservations'=>true,'runStability'=>['status'=>'stable','snapshots'=>5],'calibration'=>['publishable'=>true]],['available'=>true,'confidence'=>74,'sourceIds'=>['radar','lightning'],'impactProbability'=>61]);
if(empty($c['available'])||($c['score']??0)<70||($c['maturity']??'')!=='building'||empty($c['verified']))throw new RuntimeException('confidence engine');
$schemaFile='';foreach(array_merge(glob($root.'/api/database/*.php')?:[],glob($root.'/api/database/migrations/*.php')?:[]) as $file)$schemaFile.=file_get_contents($file)."\n";$mysqlFile=file_get_contents($root.'/api/install/mysql-schema.sql');
foreach(['account_sync_state','account_activity_profiles','observation_evidence','nowcast_fusion_snapshots'] as $table){if(!str_contains($schemaFile,$table)||!str_contains($mysqlFile,$table))throw new RuntimeException("missing schema $table");}
if(!str_contains($schemaFile,'function meteonexa_current_schema_version(): int')||!str_contains($schemaFile,'return 29;')||!str_contains($mysqlFile,"VALUES('schema_version','29'"))throw new RuntimeException('schema 29 markers');
$suite=file_get_contents($root.'/js/suite.js');$app=file_get_contents($root.'/js/app.js')."\n".file_get_contents($root.'/modules/esm/domains/account.mjs');$intel=file_get_contents($root.'/js/weather-intelligence.js');
foreach(['renderRouteDepartureComparison','applyRouteDepartureCandidate','[...candidates].sort'] as $x)if(!str_contains($suite,$x))throw new RuntimeException("route:$x");
foreach(['api/account/sync.php','scheduleAccountFavoritesPush','provided.accountSync'] as $x)if(!str_contains($app,$x))throw new RuntimeException("sync:$x");
foreach(['verifiedLevelId','renderPersonalConfidence','renderPersonalActivity'] as $x)if(!str_contains($intel,$x))throw new RuntimeException("intel:$x");
echo "Decision / Route / Account Sync smoke PASS\n";
