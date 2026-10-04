<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/database.php';
require dirname(__DIR__) . '/api/official/lifecycle_helpers.php';

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
