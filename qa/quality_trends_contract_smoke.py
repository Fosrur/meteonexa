#!/usr/bin/env python3
from __future__ import annotations
import json, subprocess, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='quality-trends-') as td:
    td=Path(td);hist=td/'h.jsonl'
    records=[]
    for i,status in enumerate(['pass','fail','pass','fail','pass']):
        records.append({'finishedAt':f'2026-10-0{i+1}T00:00:00Z','mode':'fast','gitSha':str(i),'gateStatuses':{'stable':'pass','flaky':status},'gateDurations':{'stable':1+i*.01,'flaky':2+i*.1}})
    hist.write_text('\n'.join(json.dumps(r) for r in records)+'\n')
    subprocess.run(['python3','tools/quality-trends.py','--history',str(hist),'--out-dir',str(td),'--mode','fast'],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
    report=json.loads((td/'quality-trends.json').read_text())
    flaky=[r['id'] for r in report['flakyGates']]
    assert 'flaky' in flaky and 'stable' not in flaky
    assert report['policy']['silentQuarantine'] is False
runner=(ROOT/'tools/quality-runner.py').read_text()
fetch=(ROOT/'tools/quality-fetch-history.py').read_text()
assert 'gateStatuses' in runner and 'quality-trends.py' in runner
assert 'archive_download_url' in fetch and 'GITHUB_TOKEN' in fetch
print('Quality trends contract: PASS (cross-run history, failure rate, durations, flakiness, no silent quarantine)')
