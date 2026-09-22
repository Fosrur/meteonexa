#!/usr/bin/env python3
from pathlib import Path
import json,re,sqlite3,subprocess,sys
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m); fail.append(m) if not v else None
# Syntax
php=list(root.rglob('*.php')); js=[p for p in root.rglob('*.js') if 'dist' not in p.parts and 'node_modules' not in p.parts]
php_ok=True
for p in php:
 r=subprocess.run(['php','-l',str(p)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL); php_ok &= r.returncode==0
ck(php_ok,f'PHP lint ({len(php)} files)')
js_ok=True
for p in js:
 r=subprocess.run(['node','--check',str(p)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL); js_ok &= r.returncode==0
ck(js_ok,f'JavaScript syntax ({len(js)} files)')
# HTML duplicate IDs
ids=[]
for p in [root/'index.html',root/'privacy.html',root/'cookie-policy.html',root/'offline.html']:
 if p.exists(): ids.extend(re.findall(r'\bid=["\']([^"\']+)',p.read_text(errors='replace')))
ck(len(ids)==len(set(ids)),f'HTML IDs unique ({len(ids)} IDs)')
# SQLite
con=sqlite3.connect(root/'api/install/meteonexa-baseline.sqlite')
ck(con.execute('pragma integrity_check').fetchone()[0]=='ok','SQLite integrity')
ck(con.execute('pragma foreign_key_check').fetchall()==[],'SQLite foreign keys')
meta=dict(con.execute("select meta_key,meta_value from app_metadata where meta_key in ('app_version','schema_version','translation_seed_version')"))
tables={r[0] for r in con.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")}
tr=con.execute('select count(*),count(distinct text_key),count(distinct locale) from translations').fetchone();con.close()
ck(meta=={'app_version':'20.1','schema_version':'28','translation_seed_version':'20.1-semantic-i18n-v2'},'SQLite metadata 20.1/schema28/current seed')
ck(len(tables)==47,'SQLite has 47 application tables')
ck(tr[2]==5 and tr[0]==tr[1]*5,f'i18n complete ({tr[1]} keys x 5 = {tr[0]})')
# packaged translations
seed=json.loads((root/'api/install/translations.json').read_text(encoding='utf-8')); rows=seed['rows']; locales={r['locale'] for r in rows}; keys={r['text_key'] for r in rows}
ck(seed.get('version')=='20.1-semantic-i18n-v2' and seed.get('created_for')=='MeteoNexa 20.1','packaged translation metadata current')
ck(locales=={'it','en','es','fr','de'} and len(rows)==len(keys)*5,'packaged translations complete across five locales')
for k in ['ai.system.tool_orchestration','privacy.release.current','cookie.release.current','privacy.ai.orchestration','copilot.evidence.title']:
 ck(all(any(r['locale']==loc and r['text_key']==k for r in rows) for loc in locales),f'i18n key complete: {k}')
# MySQL parity by CREATE TABLE names
mysql=(root/'api/install/mysql-schema.sql').read_text(errors='replace')
mtables=set(re.findall(r'CREATE TABLE IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?',mysql,re.I))
ck(tables==mtables,f'SQLite/MySQL table parity ({len(tables)}/{len(mtables)})')
ck("VALUES('app_version','20.1'" in mysql and "VALUES('schema_version','28'" in mysql,'MySQL metadata current')
# Docs/policies/current runtime
ck('# MeteoNexa Architecture — 20.1' in (root/'readme.md').read_text(encoding='utf-8'),'Architecture current')
ck('# MeteoNexa Security — 20.1' in (root/'readme.md').read_text(encoding='utf-8'),'Security current')
priv=(root/'privacy.html').read_text(encoding='utf-8');cookie=(root/'cookie-policy.html').read_text(encoding='utf-8')
ck('MeteoNexa 20.1' in priv and 'privacy.release.current' in priv and 'privacy.ai.orchestration' in priv,'Privacy current + AI orchestration disclosure')
ck('MeteoNexa 20.1' in cookie and 'cookie.release.current' in cookie,'Cookie Policy current')
ck("'version' => '20.1'" in (root/'api/config.php').read_text(encoding='utf-8'),'API config version current')
ck("const POLICY_VERSION = '20.1'" in (root/'modules/esm/domains/privacy.mjs').read_text(encoding='utf-8'),'privacy runtime version current')
# Core files
for rel in ['api/ai/orchestrator.php','api/route/weather_engine.php','api/intelligence/decision_timeline_helpers.php','api/intelligence/probabilistic_nowcast_helpers.php','api/plans/watch_engine.php']:
 ck((root/rel).is_file(),f'core capability present: {rel}')
print('\nRelease audit: '+('PASS' if not fail else 'FAIL')+f' ({len(fail)} failures)')
sys.exit(bool(fail))
