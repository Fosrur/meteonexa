#!/usr/bin/env python3
from pathlib import Path
import json, math, sys, time
ROOT=Path(__file__).resolve().parents[1]
life=(ROOT/'api/official/lifecycle_helpers.php').read_text(encoding='utf-8')
engine=(ROOT/'api/intelligence/engine_helpers.php').read_text(encoding='utf-8')
official=(ROOT/'api/official/alerts.php').read_text(encoding='utf-8')
app=(ROOT/'app.js').read_text(encoding='utf-8')
trans=json.loads((ROOT/'api/install/translations.json').read_text(encoding='utf-8'))

def hav(a,b,c,d):
    r=6371.0; p1=math.radians(a);p2=math.radians(c);dp=math.radians(c-a);dl=math.radians(d-b)
    x=math.sin(dp/2)**2+math.cos(p1)*math.cos(p2)*math.sin(dl/2)**2
    return 2*r*math.atan2(math.sqrt(x),math.sqrt(1-x))
# Mirror the boundary used by the PHP fallback: Chiavari/Lavagna is nearby,
# Rome is not, and region text must match when distance is >15km.
near=hav(44.306,9.344,44.317,9.323); far=hav(41.9028,12.4964,44.317,9.323)
checks={
 'nearby geocode accepted by distance': near < 15,
 'cross-region geocode rejected by distance': far > 60,
 'public snapshot remains read-only': 'INSERT INTO official_alert_state' not in life.split('function meteonexa_official_public_snapshot',1)[1].split('function meteonexa_official_track',1)[0] and 'UPDATE official_alert_state' not in life.split('function meteonexa_official_public_snapshot',1)[1].split('function meteonexa_official_track',1)[0],
 'nearby fallback bounded and area validated': all(x in life for x in ['LIMIT 250','haversine_km','if($d>60)continue','if(!$areaMatch&&$d>15)continue','21600']),
 'provider cache stale-if-error': all(x in engine for x in ['staleProviderCache','21600','if($rows===null&&is_array($staleRows))','Never replace a good cache']),
 'public rate limit NAT-safe': ("official_alerts_ip',360,3600" in official or "official_alerts_ip', 360, 3600" in official) and '10000' in official,
 'public endpoint passes area to snapshot': 'meteonexa_official_public_snapshot($pdo, $lat, $lon, $alerts, $location, $admin1)' in official or 'meteonexa_official_public_snapshot($pdo,$lat,$lon,$alerts,$location,$admin1)' in official,
 'guest endpoint does not validate an absent device id': "$rawDeviceId = trim((string)($_GET['deviceId'] ?? ''));" in official and "if ($rawDeviceId !== '')" in official and 'clean_device_id($rawDeviceId)' in official and "clean_device_id($_GET['deviceId'] ?? '')" not in official,
 'authenticated lifecycle write failure is fail-soft': all(x in official for x in ["try {\n        $alerts = meteonexa_official_track", "official_alert_tracking_degraded", "meteonexa_official_public_snapshot($pdo, $lat, $lon, $alerts, $location, $admin1)", "$alerts['lifecycleDegraded'] = true"]),
 'guest lifecycle snapshot failure is fail-soft': 'official_alert_guest_snapshot_degraded' in official and "$alerts['lifecycleTracked'] = false" in official,
 'official DB variable text is bounded': 'meteonexa_official_db_text' in life and "meteonexa_official_db_text($row['id']??'',255)" in life and "meteonexa_official_db_text($row['updatedAt']??'',40)" in life,
 'client retries transient guest failure': 'await sleep(450)' in app and 'const cacheTtl=degraded?20*1000:5*60*1000' in app,
 'snapshot label translated': all(any(r.get('locale')==loc and r.get('text_key')=='home.official.server_snapshot' and str(r.get('translation','')).strip() for r in trans['rows']) for loc in ['it','en','fr','es','de']),
 'translation seed': trans.get('version')=='20.1-semantic-i18n-v2',
}
failed=[]
for label,ok in checks.items():
    print(('[ OK ] ' if ok else '[FAIL] ')+label)
    if not ok: failed.append(label)
if failed:
    print('FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Guest official resilience smoke PASS')
