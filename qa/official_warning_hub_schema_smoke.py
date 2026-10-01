#!/usr/bin/env python3
from pathlib import Path
import sqlite3, sys
ROOT=Path(__file__).resolve().parents[1]
fail=[]
def ck(ok,label):
    print(('[ OK ] ' if ok else '[FAIL] ')+label)
    if not ok: fail.append(label)

db=ROOT/'api/install/meteonexa-baseline.sqlite'
con=sqlite3.connect(db)
meta=dict(con.execute("select meta_key,meta_value from app_metadata where meta_key in ('schema_version','app_version')"))
tables={r[0] for r in con.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")}
state={r[1] for r in con.execute('pragma table_info(official_alert_state)')}
revisions={r[1] for r in con.execute('pragma table_info(official_alert_revisions)')}
indexes={r[1] for r in con.execute("select type,name from sqlite_master where type='index'")}
con.close()
ck(meta.get('schema_version')=='35' and meta.get('app_version')=='20.1','current baseline metadata is schema 35 / app 20.1')
ck(len(tables)==53,'current post-roadmap baseline has 53 tables while P4 fields remain compatible')
ck(all(c in state for c in ['event_id','version_id','area_key','lifecycle_status','authority_name','sender','message_type','source_name','geometry_json']),'state table has canonical event/version/area/authority fields')
ck(all(c in revisions for c in ['event_id','version_id','area_key','lifecycle_status']),'revision table has canonical lifecycle fields')
ck(all(i in indexes for i in ['idx_official_alert_state_event','idx_official_alert_state_source','idx_official_alert_revisions_event']),'warning hub indexes exist in SQLite baseline')

migration=(ROOT/'api/database/migrations/0032_official_warning_hub.php').read_text(encoding='utf-8')
migrations=(ROOT/'api/database/migrations.php').read_text(encoding='utf-8')
mysql=(ROOT/'api/install/mysql-schema.sql').read_text(encoding='utf-8')
life=(ROOT/'api/official/lifecycle_helpers.php').read_text(encoding='utf-8')
hub=(ROOT/'api/official/hub_helpers.php').read_text(encoding='utf-8')
engine=(ROOT/'api/intelligence/engine_helpers.php').read_text(encoding='utf-8')
quality=(ROOT/'api/intelligence/quality_helpers.php').read_text(encoding='utf-8')
ck(all(x in migration for x in ["'version' => 32","'name' => 'official-warning-hub'","meteonexa_write_schema_version($pdo, 32)"]),'schema 32 migration descriptor is explicit')
ck('return 35;' in migrations and 'Revisions 16..35' in migrations,'migration registry current contract is schema 35')
ck(all(x in mysql for x in ["VALUES('schema_version','35'",'event_id VARCHAR(255)','lifecycle_status VARCHAR(24)', 'idx_official_alert_state_event', 'idx_official_alert_revisions_event']),'MySQL installer carries Warning Hub fields in current schema 35')
ck('ON DUPLICATE KEY UPDATE' in life and 'ON CONFLICT(location_key,alert_key)' in life,'lifecycle persistence supports MySQL and SQLite upserts')
ck(all(x in life for x in ["'cancelled'","'expired'",'terminalRevisions','freshAuthoritative']),'lifecycle handles explicit cancellation and fresh-response expiry safely')
ck(all(x in hub for x in ['meteonexa_official_hub_cap_polygon','meteonexa_official_hub_cap_circle','meteonexa_official_hub_geometry_contains','meteonexa_official_hub_provider_key','eventId','versionId','areaKey','providerKey','forecastAuthoritySeparated']),'canonical CAP/GeoJSON hub contract is present')
ck("'geometry'=>$geometry" in quality and "'messageType'" in quality and "'references'" in quality,'MeteoAlarm EDR adapter preserves geometry and CAP lifecycle metadata')
ck("meteonexa_official_atom_locals" in engine and "'polygons'=>$polygons" in engine and "matchScope'] = 'polygon'" in engine,'Atom fallback can use CAP polygons instead of text-only locality matching')

if fail:
    print('Official Warning Hub schema smoke FAILED: '+', '.join(fail),file=sys.stderr);sys.exit(1)
print('Official Warning Hub schema smoke PASS')
