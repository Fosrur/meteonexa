<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/database.php';
require dirname(__DIR__) . '/api/official/lifecycle_helpers.php';
require dirname(__DIR__) . '/api/official/liguria_zone_fallback.php';

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
$update=$base;
$update['id']=$update['identifier']='update-alert';
$update['references']='warnings@example.test,root-alert,' . $issuedAt;
$update['messageType']='Update';$update['severity']='orange';$update['updatedAt']=$updatedAt;
$rows=meteonexa_official_hub_dedupe([$base,$update],44.5,9.2);
$ok(count($rows)===1,'event+area dedupe keeps one current version');
$ok(($rows[0]['eventId']??'')==='root-alert','CAP references keep stable root event identity');
$ok(($rows[0]['severity']??'')==='orange'&&($rows[0]['messageType']??'')==='update','latest warning version wins dedupe');
$ok(!empty($rows[0]['versionId'])&&!empty($rows[0]['areaKey'])&&!empty($rows[0]['hubKey']),'canonical warning exposes event/version/area keys');
$atomDuplicate=$base;$atomDuplicate['source']='MeteoAlarm Atom';$atomDuplicate['updatedAt']='2026-10-01T06:30:00Z';
$adapterRows=meteonexa_official_hub_dedupe([$base,$atomDuplicate],44.5,9.2);
$ok(count($adapterRows)===1,'MeteoAlarm transport adapters do not duplicate the same event+area');
$ok(($rows[0]['origin']??'')==='official-warning-authority'&&($rows[0]['forecastAuthoritySeparated']??false)===true,'official warning remains semantically separate from MeteoNexa forecast');

$cancel=$update;$cancel['id']=$cancel['identifier']='cancel-alert';$cancel['messageType']='Cancel';$cancel['updatedAt']=$cancelledAt;
$normalized=meteonexa_official_normalize(['available'=>true,'providerFresh'=>true,'geospatial'=>true,'relevant'=>[$cancel]]);
$ok(count((array)($normalized['relevant']??[]))===0&&count((array)($normalized['terminalRevisions']??[]))===1,'CAP cancellation is terminal and not rendered active');
$ok(($normalized['hub']['lifecycle']??[])===['issued','updated','cancelled','expired'],'hub contract publishes canonical lifecycle');

$ok(meteonexa_liguria_municipality_zones('Lavagna')===['C'],'Lavagna resolves to official Liguria alert zone C');
$ok(meteonexa_liguria_municipality_zones('Uscio')===['B','C'],'cross-zone municipality keeps both official alert zones');
$zoneStatuses=meteonexa_liguria_parse_zone_statuses('<h3>zona </h3><h4>C</h4><h6>Emessa allerta gialla</h6><h3>zona D</h3><h6>Nessuna allerta</h6>');
$ok(($zoneStatuses['C']??'')==='yellow'&&($zoneStatuses['D']??'')==='green','AllertaLiguria zone status parser distinguishes warning from no-warning');
$rssNow=time();
$rssFixture='<rss><channel><item><title>Prolungamento allerta gialla sul centro levante</title><description><![CDATA[Arpal prolunga l’allerta gialla per temporali sul centro-levante della regione (Zone BCE) fino alle 15:00 di domani. Sul ponente (Zona A) l’allerta termina alle 15:00 di oggi.]]></description><pubDate>'.date(DATE_RSS,$rssNow-600).'</pubDate></item></channel></rss>';
$rssZones=meteonexa_liguria_parse_arpal_rss($rssFixture,$rssNow);
$ok(($rssZones['B']??'')==='yellow'&&($rssZones['C']??'')==='yellow'&&($rssZones['E']??'')==='yellow','ARPAL RSS fallback resolves compact BCE zone group');
$staleRss=str_replace(date(DATE_RSS,$rssNow-600),date(DATE_RSS,$rssNow-96*3600),$rssFixture);
$ok(meteonexa_liguria_parse_arpal_rss($staleRss,$rssNow)===[],'ARPAL RSS fallback rejects stale alert news');
$mergedSources=meteonexa_liguria_merge_zone_snapshots([['source'=>'AllertaLiguria / ARPAL','zones'=>['A'=>'green','B'=>'green','C'=>'green','D'=>'green','E'=>'green']],['source'=>'ARPAL RSS','zones'=>['B'=>'yellow','C'=>'yellow','E'=>'yellow']]]);
$ok(($mergedSources['zones']['C']??'')==='yellow'&&($mergedSources['zones']['D']??'')==='green','fresh ARPAL RSS warning overrides stale green homepage for affected zones');
$ok(($mergedSources['verificationSources']??[])===['AllertaLiguria / ARPAL','ARPAL RSS'],'zone snapshot records both verification sources');
$regionalFixture=[
    'available'=>true,'source'=>'MeteoAlarm','relevant'=>[],
    'regionalAdvisories'=>[array_replace($base,[
        'id'=>'regional-liguria','identifier'=>'regional-liguria','source'=>'MeteoAlarm Atom','authority'=>'MeteoAlarm / national warning authority',
        'title'=>'Thunderstorm warning issued for Italy - Liguria','summary'=>'Liguria','area'=>'Liguria','geometry'=>null,'geospatialMatch'=>false,
    ])],
];
$zonePromoted=meteonexa_liguria_apply_zone_status($regionalFixture,'Lavagna',['C'],['zones'=>['C'=>'yellow'],'fetchedAt'=>$updatedAt,'providerFresh'=>true]);
$promoted=$zonePromoted['relevant'][0]??[];
$ok(count((array)($zonePromoted['relevant']??[]))===1&&($promoted['severity']??'')==='yellow','official zone warning promotes the regional text fallback to relevant');
$ok(($promoted['officialZone']??'')==='C'&&($promoted['matchScope']??'')==='official-municipality-zone'&&($promoted['municipalityZoneVerified']??false)===true,'promotion records authoritative municipality-zone verification');
$ok(($promoted['startsAt']??'')===$issuedAt&&($promoted['endsAt']??'')===$endsAt,'promotion preserves MeteoAlarm validity window when available');
$multiRegional=$regionalFixture;
$multiRegional['regionalAdvisories']=[
    array_replace($regionalFixture['regionalAdvisories'][0],['id'=>'wind-upcoming','title'=>'Yellow Wind Warning issued for Italy - Liguria','event'=>'Yellow Wind Warning','severity'=>'yellow','windowState'=>'upcoming','startsAt'=>gmdate('c',$fixtureNow+3600),'endsAt'=>gmdate('c',$fixtureNow+7200)]),
    array_replace($regionalFixture['regionalAdvisories'][0],['id'=>'storm-active','title'=>'Orange Thunderstorm Warning issued for Italy - Liguria','event'=>'Orange Thunderstorm Warning','severity'=>'orange','windowState'=>'active','startsAt'=>gmdate('c',$fixtureNow-3600),'endsAt'=>gmdate('c',$fixtureNow+7200)]),
    array_replace($regionalFixture['regionalAdvisories'][0],['id'=>'rain-active','title'=>'Orange Rain Warning issued for Italy - Liguria','event'=>'Orange Rain Warning','severity'=>'orange','windowState'=>'active','startsAt'=>gmdate('c',$fixtureNow-3600),'endsAt'=>gmdate('c',$fixtureNow+7200)]),
];
$multiPromoted=meteonexa_liguria_apply_zone_status($multiRegional,'Lavagna',['C'],['zones'=>['C'=>'yellow'],'source'=>'AllertaLiguria / ARPAL + ARPAL RSS','verificationSources'=>['AllertaLiguria / ARPAL','ARPAL RSS'],'providerFresh'=>true]);
$multiWarning=$multiPromoted['relevant'][0]??[];
$ok(($multiWarning['id']??'')==='storm-active'&&($multiWarning['severity']??'')==='yellow','promotion prefers active thunderstorm over upcoming wind while keeping official zone severity');
$ok(($multiWarning['verificationSources']??[])===['AllertaLiguria / ARPAL','ARPAL RSS'],'promoted warning exposes authoritative verification sources');
$zoneClear=meteonexa_liguria_apply_zone_status($regionalFixture,'Lavagna',['C'],['zones'=>['C'=>'green'],'providerFresh'=>true]);
$ok(count((array)($zoneClear['relevant']??[]))===0,'official green zone never promotes a regional advisory');

$appSource=(string)file_get_contents(dirname(__DIR__).'/js/app.js');
$apiSource=(string)file_get_contents(dirname(__DIR__).'/api/official/alerts.php');
$styleSource=(string)file_get_contents(dirname(__DIR__).'/styles/main/99-reliability-patches.css');
$ok(!str_contains($appSource,"params.set('deviceId'")&&str_contains($appSource,'api/official/alerts.php?${params}'),'guest and authenticated Home use the same public official-alert request');
$ok(!str_contains($apiSource,'require_authenticated_device_session')&&str_contains($apiSource,'meteonexa_verify_device_proof')&&str_contains($apiSource,'meteonexa_official_public_snapshot'),'public official-alert API degrades invalid device proof instead of failing weather data');
$colorStates=true;foreach(['green','yellow','orange','red'] as $colorState){$colorStates=$colorStates&&str_contains($styleSource,'.home-official-alert[data-severity="'.$colorState.'"]');}
$ok($colorStates,'Home official-alert card defines explicit green yellow orange red states');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)");
    $pdo->exec("INSERT INTO app_metadata VALUES('schema_version','31','2026-10-01T00:00:00Z')");
    $pdo->exec("CREATE TABLE official_alert_state(location_key TEXT NOT NULL,alert_key TEXT NOT NULL,location_name TEXT NOT NULL DEFAULT '',provider_alert_id TEXT NOT NULL DEFAULT '',severity TEXT NOT NULL DEFAULT 'yellow',starts_at TEXT NOT NULL DEFAULT '',ends_at TEXT NOT NULL DEFAULT '',source_updated_at TEXT NOT NULL DEFAULT '',content_hash TEXT NOT NULL,first_seen_at TEXT NOT NULL,last_seen_at TEXT NOT NULL,last_change_type TEXT NOT NULL DEFAULT 'new',last_change_at TEXT NOT NULL,previous_severity TEXT NOT NULL DEFAULT '',previous_ends_at TEXT NOT NULL DEFAULT '',payload_json TEXT NOT NULL,PRIMARY KEY(location_key,alert_key))");
    $pdo->exec("CREATE TABLE official_alert_revisions(id INTEGER PRIMARY KEY AUTOINCREMENT,location_key TEXT NOT NULL,alert_key TEXT NOT NULL,revision_type TEXT NOT NULL,previous_severity TEXT NOT NULL DEFAULT '',new_severity TEXT NOT NULL DEFAULT '',previous_ends_at TEXT NOT NULL DEFAULT '',new_ends_at TEXT NOT NULL DEFAULT '',provider_alert_id TEXT NOT NULL DEFAULT '',payload_json TEXT NOT NULL,observed_at TEXT NOT NULL)");
    $version=meteonexa_run_sqlite_migrations($pdo,31,32);
    $ok($version===32,'schema 32 Official Warning Hub migration completes');
    foreach(['event_id','version_id','area_key','lifecycle_status','authority_name','sender','message_type','source_name','geometry_json'] as $column)$ok(meteonexa_db_column_exists($pdo,'official_alert_state',$column),"state column $column exists");

    $issued=meteonexa_official_track($pdo,44.5,9.2,'Test location',['available'=>true,'providerFresh'=>true,'geospatial'=>true,'relevant'=>[$base]]);
    $state=$pdo->query('SELECT * FROM official_alert_state')->fetch();
    $ok(is_array($state)&&$state['event_id']==='root-alert'&&$state['lifecycle_status']==='issued','initial warning persists as issued canonical event');
    $updated=meteonexa_official_track($pdo,44.5,9.2,'Test location',['available'=>true,'providerFresh'=>true,'geospatial'=>true,'relevant'=>[$update]]);
    $state=$pdo->query('SELECT * FROM official_alert_state')->fetch();
    $ok(is_array($state)&&$state['event_id']==='root-alert'&&$state['lifecycle_status']==='updated'&&$state['severity']==='orange','update reuses event+area state and changes lifecycle to updated');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM official_alert_state')->fetchColumn()===1,'updated version does not duplicate the event state row');
    meteonexa_official_track($pdo,44.5,9.2,'Test location',['available'=>true,'providerFresh'=>true,'geospatial'=>true,'relevant'=>[$cancel]]);
    $state=$pdo->query('SELECT * FROM official_alert_state')->fetch();
    $ok(is_array($state)&&$state['lifecycle_status']==='cancelled'&&$state['last_change_type']==='cancelled','explicit cancellation closes lifecycle as cancelled');
    $types=$pdo->query('SELECT revision_type FROM official_alert_revisions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $ok(in_array('new',$types,true)&&in_array('escalated',$types,true)&&in_array('cancelled',$types,true),'revision ledger keeps issued/update/cancel history');
} else {
    echo "[SKIP] PHP pdo_sqlite unavailable; schema/lifecycle persistence covered by Python schema gate\n";
}

if($fail){fwrite(STDERR,"Official Warning Hub smoke FAILED: ".implode(', ',$fail)."\n");exit(1);}echo "Official Warning Hub smoke PASS\n";
