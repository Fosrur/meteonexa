#!/usr/bin/env python3
from pathlib import Path
import sys,re
root=Path(__file__).resolve().parents[1]; fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m); fail.append(m) if not v else None
def t(p): return (root/p).read_text()
def compact(source): return re.sub(r'\s+', '', source)
html=t('index.html');dec=t('api/intelligence/decision_timeline_helpers.php');prob=t('api/intelligence/probabilistic_nowcast_helpers.php');acc=t('api/accuracy/public.php');app=t('app.js');watch=t('api/plans/watch_engine.php')
activities=['run','bike','motorcycle','sea','trekking','kids','pets','worksite','commute','event','photography']
ck(all(("'"+a+"'=>") in dec and ('data-decision-activity="'+a+'"') in html for a in activities),'Decision Timeline keeps 11 activity profiles')
ck("timeShiftMinutes'=>30" in dec and "rainProbabilityDelta'=>15" in dec and "agreementDelta'=>15" in dec,'Forecast Change materiality thresholds remain')
ck('meteonexa_arrival_distribution' in prob and "$radarMode==='active'?'radar3-verified':'radar2-authoritative'" in compact(prob),'probabilistic nowcast and radar authority remain')
ck('minimumContributors=3' in compact(acc) and 'minimumSamples=20' in compact(acc) and '$days=60' in compact(acc),'Public Local Accuracy thresholding remains')
ck("credentials:'omit'" in app and 'loadPublicLocalAccuracy' in app,'Public Local Accuracy omits browser session')
ck('time()+72*3600' in compact(watch) and '$drop>=15' in compact(watch) and '10800' in compact(watch),'Watch My Plan evaluation horizon/materiality/cooldown remain')
ck(html.count('id="decision-decision-panel"')==1 and html.count('id="model-accuracy-panel"')==1 and html.count('id="watch-watch-panel"')==1,'major intelligence articles remain unique')
print('\nFeature regression: '+('PASS' if not fail else 'FAIL'));sys.exit(bool(fail))
