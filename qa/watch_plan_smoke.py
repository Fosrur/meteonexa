#!/usr/bin/env python3
from pathlib import Path
import re,sys
root=Path(__file__).resolve().parents[1];fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m);fail.append(m) if not v else None
def t(p): return (root/p).read_text()
def compact(source): return re.sub(r'\s+', '', source)
api=t('api/plans/watch.php');helpers=t('api/plans/watch_helpers.php');engine=t('api/plans/watch_engine.php');html=t('index.html');css=t('css/watch-plan.css');js=t('modules/esm/features/watch-plan.mjs')
ck('assert_same_origin()' in api and 'require_authenticated_device_session' in api,'plan API requires same-origin authenticated device session')
ck('count($active)>=8' in api and "'maxPlans'=>8" in api,'active plans capped at eight')
ck('time()+14*86400' in helpers,'plan start horizon bounded')
m=re.search(r"return \[\s*'id'=>\$id.*?'updatedAt'=>gmdate\('c'\),\s*\];",helpers,re.S); block=m.group(0) if m else ''
ck(block and 'latitude' not in block and 'longitude' not in block and 'text' not in block.lower(),'persisted plan does not duplicate coordinates/free text')
ck('time()+72*3600' in compact(engine) and 'meteonexa_decision_hour_score' in engine,'worker reuses Decision Timeline within bounded horizon')
ck("if(!$baseline||!$previous)return['notify'=>false,'kind'=>'baseline']" in compact(engine),'first evaluation is baseline-only')
ck('10800' in engine and "'recovered'" in engine,'notification cooldown/recovery guard remains')
ck('class="glass-panel watch-watch-panel"' in html and '@media(max-width:720px)' in css and '@media(max-width:460px)' in css,'Watch My Plan article follows responsive glass-panel contract')
ck('deps.confirm' in js and 'deps.toast' in js,'Watch My Plan reuses confirmation/toast UX through dependency injection')
print('\nWatch My Plan: '+('PASS' if not fail else 'FAIL'));sys.exit(bool(fail))
