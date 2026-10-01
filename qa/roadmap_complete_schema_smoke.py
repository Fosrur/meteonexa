from pathlib import Path
import json,re,sqlite3,sys
root=Path(__file__).resolve().parents[1]
fail=[]
def check(cond,msg):
 print(('[ OK ] ' if cond else '[FAIL] ')+msg)
 if not cond: fail.append(msg)
migrations=(root/'api/database/migrations.php').read_text()
check('return 35;' in migrations,'current schema version is 35')
files=sorted((root/'api/database/migrations').glob('[0-9][0-9][0-9][0-9]_*.php'))
versions=[int(p.name[:4]) for p in files]
check(versions==list(range(16,36)),f'migration registry contiguous 16..35 ({len(versions)} revisions)')
mysql=(root/'api/install/mysql-schema.sql').read_text()
tables=re.findall(r'CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)',mysql,re.I)
check(len(set(tables))==53,f'MySQL baseline has 53 application tables (found {len(set(tables))})')
for t in ['ai_semantic_cache','personal_station_samples','material_decision_state','release_canary_snapshots']:
 check(t in tables,f'MySQL baseline contains {t}')
check("VALUES('schema_version','35'" in mysql,'MySQL baseline metadata schema 35')
sqlite=root/'api/install/meteonexa-baseline.sqlite'
con=sqlite3.connect(sqlite);cur=con.cursor()
sv=cur.execute("select meta_value from app_metadata where meta_key='schema_version'").fetchone();check(sv and sv[0]=='35','SQLite baseline metadata schema 35')
count=cur.execute("select count(*) from sqlite_master where type='table' and name not like 'sqlite_%'").fetchone()[0];check(count==53,f'SQLite baseline has 53 tables (found {count})')
for t in ['ai_semantic_cache','personal_station_samples','material_decision_state','release_canary_snapshots']:
 check(bool(cur.execute("select 1 from sqlite_master where type='table' and name=?",(t,)).fetchone()),f'SQLite baseline contains {t}')
con.close()
gold=json.loads((root/'config/golden-locations-europe.json').read_text());cases={x for r in gold for x in r.get('cases',[])};check({'plain','mountain','coast','city'}<=cases,'golden locations cover plain/mountain/coast/city')
workflow=(root/'.github/workflows/deploy-production.yml').read_text();markers=['maintenance-mode.sh on','docker/deploy-production.sh','--allow-maintenance','maintenance-mode.sh off','live_security_check.py https://www.meteonexa.com/']
pos=[workflow.find(markers[0]),workflow.find(markers[1]),workflow.find(markers[2]),workflow.find(markers[3]),workflow.rfind(markers[4])];check(all(x>=0 for x in pos) and pos==sorted(pos),'synthetic release path order: maintenance on -> deploy -> 503/security -> off -> live smoke')
if fail:
 print('Roadmap complete schema/release smoke FAILED: '+', '.join(fail),file=sys.stderr);sys.exit(1)
print('Roadmap complete schema/release smoke PASS')
