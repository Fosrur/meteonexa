#!/usr/bin/env python3
from pathlib import Path
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

if len(sys.argv) != 3:
    raise SystemExit('Usage: live_release_identity_check.py <base-url> <expected-sha>')

base = sys.argv[1].rstrip('/')
expected_sha = sys.argv[2].strip().lower()
if re.fullmatch(r'[0-9a-f]{40}', expected_sha) is None:
    raise SystemExit('LIVE RELEASE IDENTITY FAIL: invalid expected SHA')

root = Path(__file__).resolve().parents[1]
migrations = (root / 'api/database/migrations.php').read_text(encoding='utf-8')
match = re.search(r'function\s+meteonexa_current_schema_version\s*\(\s*\)\s*:\s*int\s*\{.*?return\s+(\d+)\s*;', migrations, re.S)
if match is None:
    raise SystemExit('LIVE RELEASE IDENTITY FAIL: current schema version not found')
expected_schema = match.group(1)

last_error = ''
for attempt in range(1, 9):
    query = urllib.parse.urlencode({'release_probe': expected_sha[:12], 'attempt': attempt, 'ts': int(time.time())})
    url = f'{base}/api/system/status.php?{query}'
    request = urllib.request.Request(url, headers={
        'Accept': 'application/json',
        'Cache-Control': 'no-cache, no-store, max-age=0',
        'Pragma': 'no-cache',
        'User-Agent': 'MeteoNexa-Release-Identity/1.0',
    })
    try:
        with urllib.request.urlopen(request, timeout=12) as response:
            status = response.status
            payload = json.loads(response.read().decode('utf-8'))
        actual_sha = str(payload.get('releaseSha', '')).lower()
        actual_schema = str(payload.get('schema', ''))
        if status == 200 and payload.get('ok') is True and actual_sha == expected_sha and actual_schema == expected_schema:
            print(f'LIVE RELEASE IDENTITY PASS sha={actual_sha} schema={actual_schema}')
            raise SystemExit(0)
        last_error = f'HTTP {status} sha={actual_sha or "<missing>"} schema={actual_schema or "<missing>"} expected_sha={expected_sha} expected_schema={expected_schema}'
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, json.JSONDecodeError, OSError) as error:
        last_error = f'{type(error).__name__}: {error}'
    if attempt < 8:
        time.sleep(2)

raise SystemExit('LIVE RELEASE IDENTITY FAIL: ' + last_error)
