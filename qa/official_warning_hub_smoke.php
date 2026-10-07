<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/database.php';
require dirname(__DIR__) . '/api/official/lifecycle_helpers.php';
require dirname(__DIR__) . '/api/official/administrative_area_match.php';

$fail=[];
$ok=static function(bool $condition,string $label)use(&$fail):void{echo ($condition?'[ OK ] ':'[FAIL] ').$label."\n";if(!$condition)$fail[]=$label;};

$capPolygon='44.000,8.800 44.000,9.800 45.000,9.800 45.000,8.800 44.000,8.800';
$geometry=meteonexa_official_hub_cap_polygon($capPolygon);
$ok(is_array($geometry)&&($geometry['type']??'')==='Polygon','CAP polygon normalizes to GeoJSON Polygon');
$ok(meteonexa_official_hub_geometry_contains((array)$geometry,44.5,9.2),'polygon geofencing accepts inside point');
$ok(!meteonexa_official_hub_geometry_contains((array)$geometry,43.5,9.2),'polygon geofencing rejects outside point');

$fixtureNow=time();
$issuedAt=gmdate('c',$fixtureNow-15*60);
$updatedAt=gmdate('c',$fixtureNow-5*60);
$cancelledAt=gmdate('c',$fixtureNow);
$endsAt=gmdate('c',$fixtureNow+12*3600);
$base=[
    'id'=>'root-alert','identifier'=>'root-alert','source'=>'MeteoAlarm EDR','authority'=>'National Weather Service',
    'sender'=>'warnings@example.test','messageType'=>'Alert','status'=>'Actual','event'=>'Thunderstorm','title'=>'Thunderstorm warning',
    'severity'=>'yellow','certainty'=>'Likely','urgency'=>'Expected','sentAt'=>$issuedAt,'updatedAt'=>$issuedAt,
    'startsAt'=>$issuedAt,'endsAt'=>$endsAt,'geometry'=>$geometry,'geospatialMatch'=>true,
];

$yellowFixture=[
    'comune'=>'Example City',
    'regione'=>'Example Region',
    'zona'=>'Example Warning Zone',
    'oggi'=>[
        'allerta'=>['colore'=>'giallo','descrizione'=>'Allerta Gialla'],
        'dettagli'=>[
            'idraulico'=>'Assenza di fenomeni significativi prevedibili / NESSUNA ALLERTA',
            'temporali'=>'Ordinaria / ALLERTA GIALLA',
            'idrogeologico'=>'Assenza di fenomeni significativi prevedibili / NESSUNA ALLERTA'
        ]
    ]
];
$greenFixture=$yellowFixture;
$greenFixture['oggi']['allerta']=['colore'=>'verde','descrizione'=>'Nessuna Allerta'];
$greenFixture['oggi']['dettagli']['temporali']='Assenza di fenomeni significativi prevedibili / NESSUNA ALLERTA';

$ok(meteonexa_official_municipality_severity($yellowFixture)==='yellow','municipality provider severity normalizes yellow');
$ok(meteonexa_official_municipality_severity($greenFixture)==='green','municipality provider severity normalizes green');
$ok(meteonexa_official_municipality_risks($yellowFixture)===['temporali'],'municipality provider extracts only active risks');

$storm=array_replace($base,['id'=>'storm','identifier'=>'storm','geometry'=>null,'geospatialMatch'=>false,'event'=>'Orange Thunderstorm Warning','title'=>'Orange Thunderstorm Warning','severity'=>'orange','windowState'=>'active']);
$rain=array_replace($base,['id'=>'rain','identifier'=>'rain','geometry'=>null,'geospatialMatch'=>false,'event'=>'Orange Rain Warning','title'=>'Orange Rain Warning','severity'=>'orange','windowState'=>'active']);
$ok(meteonexa_official_municipality_row_score($storm,['temporali'])>meteonexa_official_municipality_row_score($rain,['temporali']),'municipality verification prefers provider event matching the active local risk');

$appSource=(string)file_get_contents(dirname(__DIR__).'/js/app.js');
$apiSource=(string)file_get_contents(dirname(__DIR__).'/api/official/alerts.php');
$matchSource=(string)file_get_contents(dirname(__DIR__).'/api/official/administrative_area_match.php');
$styleSource=(string)file_get_contents(dirname(__DIR__).'/styles/main/99-reliability-patches.css');

$ok(str_contains($apiSource,'meteonexa_intelq_meteoalarm_edr')&&str_contains($apiSource,'meteonexa_official_alerts'),'MeteoAlarm remains the primary official warning provider');
$ok(str_contains($matchSource,'allertameteo.app/api/alert/'),'municipality fallback uses an external municipality-level service');
$ok(!preg_match('/\b(?:Lavagna|Genova|Liguria|Roma|Milano)\b/i',$matchSource),'municipality matcher contains no hardcoded city or region');
$ok(!str_contains($matchSource,"if (\$admin !== '' && in_array(\$admin"),'region-wide administrative promotion is removed');
$ok(!str_contains($appSource,"params.set('deviceId'")&&str_contains($appSource,'api/official/alerts.php?${params}'),'guest and authenticated Home use the same public official-alert request');
$ok(!str_contains($apiSource,'require_authenticated_device_session')&&str_contains($apiSource,'meteonexa_verify_device_proof')&&str_contains($apiSource,'meteonexa_official_public_snapshot'),'public official-alert API degrades invalid device proof instead of failing weather data');

$colorStates=true;
foreach(['green','yellow','orange','red'] as $colorState){
    $colorStates=$colorStates&&str_contains($styleSource,'.home-official-alert[data-severity="'.$colorState.'"]');
}
$ok($colorStates,'Home official-alert card defines explicit green yellow orange red states');

if($fail){fwrite(STDERR,"Official Warning Hub smoke FAILED: ".implode(', ',$fail)."\n");exit(1);}
echo "Official Warning Hub smoke PASS\n";
