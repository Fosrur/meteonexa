#!/usr/bin/env python3
from pathlib import Path
import json, sqlite3, sys
ROOT=Path(__file__).resolve().parents[1]
worker=(ROOT/'api/pipeline/worker.php').read_text(encoding='utf-8')
helpers=(ROOT/'api/pipeline/helpers.php').read_text(encoding='utf-8')
lifecycle=(ROOT/'api/official/lifecycle_helpers.php').read_text(encoding='utf-8')
radar=(ROOT/'api/radar/archive_helpers.php').read_text(encoding='utf-8')
metadata=(ROOT/'api/radar/metadata_helpers.php').read_text(encoding='utf-8')
config=(ROOT/'api/config.php').read_text(encoding='utf-8')
push=(ROOT/'api/push/dispatch.php').read_text(encoding='utf-8')
app=(ROOT/'app.js').read_text(encoding='utf-8')
smart=(ROOT/'weather-intelligence.js').read_text(encoding='utf-8')
suite=(ROOT/'suite.js').read_text(encoding='utf-8')
privacy=(ROOT/'privacy.html').read_text(encoding='utf-8')
cookie=(ROOT/'cookie-policy.html').read_text(encoding='utf-8')
checks={
 'worker protected': "HTTP_X_CRON_KEY" in worker and "PHP_SAPI!=='cli'" in worker and "meteonexa_acquire_lock('industrial-weather-pipeline')" in worker,
 'run-level provider cadence': all(x in worker for x in ['$officialDue=','$lightningDue=','$observationsDue=','$calibrationDue=']) and "if($officialDue)" in worker,
 'worker no raw run location': "'locationRef'=>$locationRef" in worker and "'location'=>$locationKey" not in worker,
 'radar five-minute default': "METEONEXA_RADAR_ARCHIVE_INTERVAL_MINUTES" in config and "?: 5" in config and "fallback_metadata_url" in config,
 'radar quality evidence': 'radar_frame_quality' in radar and 'signal_coverage' in radar and 'provider_frame_age_seconds' in radar,
 'provider health': 'weather_provider_health' in helpers and 'consecutive_failures' in helpers and 'meteonexa_pipeline_health_summary' in helpers,
 'official lifecycle': all(x in lifecycle for x in ["'escalated'","'extended'","'downgraded'","'shortened'","official_alert_revisions"]),
 'push bypasses cooldown on revisions': "officialChanged" in push and "'escalated','extended'" in push,
 'inline article loader': 'MeteoNexaPanelLoader' in app and 'smart-intelligence-panel' in smart and 'setSummaryPanelsLoading(true)' in smart and '#ai-briefing-panel' in app and '#proactive-ai-panel' in app,
 'advanced article loaders': 'loadingPanels' in suite and 'MeteoNexaPanelLoader' in suite,
 'privacy disclosure': 'privacy.pipeline.server_side' in privacy and 'privacy.pipeline.retention' in privacy and 'cookie.pipeline.copy' in cookie,
}
con=sqlite3.connect(ROOT/'api/install/meteonexa-baseline.sqlite')
meta=dict(con.execute('select meta_key,meta_value from app_metadata'))
tables={r[0] for r in con.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")}
con.close()
checks['current schema']=meta.get('schema_version')=='28' and meta.get('app_version')=='20.1'
checks['industrial tables']=all(t in tables for t in ['weather_provider_health','weather_pipeline_runs','official_alert_state','official_alert_revisions','lightning_observation_snapshots','radar_frame_quality'])
failed=[]
for label,ok in checks.items():
 print(('[ OK ] ' if ok else '[FAIL] ')+label)
 if not ok: failed.append(label)
if failed:
 print('FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Industrial Weather Pipeline smoke PASS')
