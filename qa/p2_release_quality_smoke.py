#!/usr/bin/env python3
from __future__ import annotations
from pathlib import Path
import json,re,sys
ROOT=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
errors=[]
def need(ok,msg):
    if not ok: errors.append(msg)
def read(p): return (ROOT/p).read_text(encoding='utf-8')

suite=read('js/suite.js'); support=read('modules/esm/domains/suite-support.mjs'); deploy=read('docker/deploy-production.sh'); ci=read('docker/verify-ci-green.sh'); mysql_verify=read('docker/verify-mysql.sh'); dockerfile=read('Dockerfile'); compose=read('docker-compose.yml'); index=read('index.html'); bootstrap=read('modules/esm/bootstrap.mjs'); readme=read('readme.md')
need('window.print()' not in suite and 'window.print()' not in support,'history PDF still delegates to window.print()')
need('exportHistoryPdf(suite.history)' in suite,'history PDF button is not wired to the PDF exporter')
for token in ['function exportHistoryPdf(history)', "type: 'application/pdf'", 'a.download = `meteonexa-history-${history.start}-${history.end}.pdf`']:
    need(token in support,'real history PDF export missing: '+token)
need('bash docker/verify-ci-green.sh "$DEPLOY_SHA"' in deploy,'production deploy does not require green CI for exact SHA')
need('meteonexa_current_schema_version()' in mysql_verify and '[ "$ACTUAL_SCHEMA" = "$EXPECTED_SCHEMA" ]' in mysql_verify,'MySQL post-deploy verification is not a real current-schema gate')
need('org.opencontainers.image.revision' in dockerfile and dockerfile.count('METEONEXA_BUILD_SHA') >= 3,'Docker image revision provenance label/env missing')
need(compose.count('METEONEXA_BUILD_SHA: ${METEONEXA_BUILD_SHA:-unknown}') >= 2,'web/worker build SHA args missing from compose')
need('METEONEXA_BUILD_SHA="$DEPLOY_SHA" docker compose build --pull web worker' in deploy and 'CONTAINER_PROVENANCE_PASS' in deploy and deploy.count('org.opencontainers.image.revision') >= 2,'post-deploy container SHA provenance gate missing')
for token in ['actions/workflows/$WORKFLOW_FILE/runs?head_sha=$SHA','status!=\'completed\'','conclusion!=\'success\'']:
    # tolerate shell/python spacing for status/conclusion checks
    if token.startswith('status'):
        need("status!='completed'" in ci and "conclusion!='success'" in ci,'CI checker does not require completed/success')
    else: need(token in ci,'CI checker contract missing: '+token)
need('METEONEXA_GITHUB_REPOSITORY=' in read('.env.example'),'GitHub repository deployment configuration missing')
need('METEONEXA_GITHUB_TOKEN=' in read('.env.example'),'GitHub token deployment configuration missing')
need("'css/advanced.css'" in bootstrap.split('const DEFERRED_STYLES',1)[1].split(']);',1)[0],'advanced CSS is not deferred')
need(not re.search(r'<link[^>]+href="dist/advanced\.[a-f0-9]{12}\.css"[^>]+rel="stylesheet"',index),'advanced CSS remains parser blocking')

manifest=json.loads(read('asset-manifest.json'))
blocking=[]
for href in re.findall(r'<link[^>]+href="([^"]+\.css)"[^>]+rel="stylesheet"',index):
    p=ROOT/href
    if p.is_file(): blocking.append((href,p.stat().st_size))
blocking_bytes=sum(size for _,size in blocking)
need(blocking_bytes < 400*1024,f'blocking CSS budget exceeded: {blocking_bytes} bytes')

for bad in ['â€”','â€™','Ã ','giÃ','unâ€™']:
    need(bad not in readme,'README mojibake remains: '+bad)
need((ROOT/'reports/ARCHITECTURE-SECURITY.md').is_file(),'architecture/security Markdown report missing')
need((ROOT/'qa/i18n_runtime_literal_smoke.py').is_file(),'extended hardcoded translation audit missing')

print('P2 release quality: '+('PASS' if not errors else 'FAIL'))
print(' - parser-blocking CSS:', blocking_bytes, 'bytes', ', '.join(x for x,_ in blocking) or 'none')
for e in errors: print(' - '+e)
sys.exit(bool(errors))
