#!/usr/bin/env python3
"""Aggregate cross-run quality history into failure-rate, duration and flakiness trends."""
from __future__ import annotations
import argparse, json, statistics
from collections import defaultdict
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def percentile(values:list[float],q:float)->float|None:
    if not values:return None
    v=sorted(values);idx=min(len(v)-1,max(0,int(round((len(v)-1)*q))))
    return round(v[idx],3)

def main()->int:
    ap=argparse.ArgumentParser();ap.add_argument('--history',default='.quality-reports/quality-history.jsonl');ap.add_argument('--out-dir',default='.quality-reports');ap.add_argument('--mode');ap.add_argument('--window',type=int,default=20);ns=ap.parse_args()
    hp=Path(ns.history);hp=hp if hp.is_absolute() else ROOT/hp
    out=Path(ns.out_dir);out=out if out.is_absolute() else ROOT/out;out.mkdir(parents=True,exist_ok=True)
    records=[]
    if hp.is_file():
        for line in hp.read_text(encoding='utf-8').splitlines():
            try:r=json.loads(line)
            except Exception:continue
            if ns.mode and r.get('mode')!=ns.mode:continue
            records.append(r)
    records=records[-max(2,ns.window):]
    gates=defaultdict(list)
    for r in records:
        statuses=r.get('gateStatuses') or {};durations=r.get('gateDurations') or {}
        for gid,status in statuses.items():gates[gid].append({'status':status,'duration':float(durations.get(gid) or 0.0),'at':r.get('finishedAt'),'sha':r.get('gitSha')})
    rows=[];flaky=[];timing=[]
    for gid,obs in sorted(gates.items()):
        passes=sum(1 for x in obs if x['status']=='pass');fails=len(obs)-passes;rate=fails/len(obs) if obs else 0
        transitions=sum(1 for a,b in zip(obs,obs[1:]) if a['status']!=b['status'])
        durations=[x['duration'] for x in obs if x['duration']>=0]
        is_flaky=len(obs)>=4 and passes>0 and fails>0 and transitions>0
        baseline=durations[:-1];latest=durations[-1] if durations else 0;p95_prev=percentile(baseline,.95) if baseline else None
        timing_reg=bool(p95_prev and latest>max(p95_prev*1.5,p95_prev+1.0))
        row={'id':gid,'observations':len(obs),'passRatePct':round(100*passes/len(obs),1) if obs else None,'failureRatePct':round(100*rate,1) if obs else None,'transitions':transitions,'flaky':is_flaky,'p50Seconds':percentile(durations,.5),'p95Seconds':percentile(durations,.95),'latestSeconds':round(latest,3),'timingRegression':timing_reg}
        rows.append(row)
        if is_flaky:flaky.append(row)
        if timing_reg:timing.append(row)
    report={'schemaVersion':1,'mode':ns.mode,'windowRuns':len(records),'history':str(hp),'gates':rows,'flakyGates':flaky,'timingRegressions':timing,'policy':{'silentQuarantine':False,'minimumObservationsForFlaky':4,'timingRegression':'latest > max(previous p95 * 1.5, previous p95 + 1s)'}}
    (out/'quality-trends.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    lines=['# MeteoNexa quality trends','',f"Runs analyzed: **{len(records)}**"+(f" (`{ns.mode}`)" if ns.mode else ''),'',f"Flaky gates: **{len(flaky)}** · timing regressions: **{len(timing)}**",'', '| Gate | Runs | Pass rate | Failure rate | P50 | P95 | Flaky | Timing regression |','|---|---:|---:|---:|---:|---:|---:|---:|']
    for r in rows:lines.append(f"| `{r['id']}` | {r['observations']} | {r['passRatePct']}% | {r['failureRatePct']}% | {r['p50Seconds']}s | {r['p95Seconds']}s | {'YES' if r['flaky'] else 'no'} | {'YES' if r['timingRegression'] else 'no'} |")
    lines += ['','Policy: a flaky gate is **never silently quarantined**; it remains red when it fails and must have an explicit owner/issue before any temporary exception.','']
    (out/'quality-trends.md').write_text('\n'.join(lines),encoding='utf-8')
    print(f"Quality trends: PASS ({len(records)} runs, {len(rows)} gates, {len(flaky)} flaky, {len(timing)} timing regressions)")
    return 0
if __name__=='__main__':raise SystemExit(main())
