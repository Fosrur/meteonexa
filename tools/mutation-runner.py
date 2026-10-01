#!/usr/bin/env python3
"""Small deterministic mutation gate for critical metric-driven weather guardrails."""
from __future__ import annotations
import json, os, subprocess, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
TARGET=ROOT/'api/observability/weather_improvement_helpers.php'
source=TARGET.read_text(encoding='utf-8')
mutations=[
 ('disable-min-samples', "'minimumDriftSamplesPerWindow'=>30", "'minimumDriftSamplesPerWindow'=>0"),
 ('inflate-relative-threshold', "'relativeMaeDriftThresholdPct'=>15.0", "'relativeMaeDriftThresholdPct'=>95.0"),
 ('disable-absolute-threshold', "'absoluteMaeDriftThreshold'=>0.5", "'absoluteMaeDriftThreshold'=>9.5"),
 ('allow-auto-production', "'automaticProductionChange'=>false", "'automaticProductionChange'=>true"),
 ('remove-canary-requirement', "'requiresCanaryComparison'=>true", "'requiresCanaryComparison'=>false"),
]
killed=[];survived=[]
with tempfile.TemporaryDirectory(prefix='meteonexa-mutants-') as td:
    td=Path(td)
    for mid,old,new in mutations:
        if old not in source: raise SystemExit(f'Mutation testing: FAIL - target pattern missing for {mid}')
        mutant=td/f'{mid}.php';mutant.write_text(source.replace(old,new,1),encoding='utf-8')
        env=os.environ.copy();env['METEONEXA_WEATHER_IMPROVEMENT_HELPER']=str(mutant)
        proc=subprocess.run(['php','qa/property_weather_invariants.php'],cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,timeout=45)
        (killed if proc.returncode!=0 else survived).append({'id':mid,'exitCode':proc.returncode,'tail':'\n'.join(proc.stdout.splitlines()[-8:])})
report={'mutants':len(mutations),'killed':len(killed),'survived':len(survived),'scorePct':round(100*len(killed)/len(mutations),1),'killedMutants':killed,'survivedMutants':survived}
out=ROOT/'.quality-reports'/'mutation-report.json';out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
if survived:
    print('Mutation testing: FAIL - survivors: '+', '.join(x['id'] for x in survived));raise SystemExit(1)
print(f"Mutation testing: PASS ({len(killed)}/{len(mutations)} mutants killed, score 100%)")
