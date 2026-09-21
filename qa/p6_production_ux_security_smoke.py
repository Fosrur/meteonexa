#!/usr/bin/env python3
from pathlib import Path
import re, sys
ROOT=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
read=lambda p:(ROOT/p).read_text(encoding='utf-8',errors='replace')
ht=read('.htaccess'); css=read('styles/main/80-location-radar-overrides.css'); maint=read('maintenance.html')
workflow=read('.github/workflows/meteonexa-tests.yml'); compose=read('docker-compose.yml')
p0=read('qa/p0_security_refactor_smoke.py'); radar=read('js/advanced.js')
checks={
 'CSP reporting endpoint is configured': all(x in ht for x in ('Reporting-Endpoints','Report-To','report-uri /api/csp-report.php','report-to csp-endpoint')) and (ROOT/'api/csp-report.php').is_file(),
 'maintenance static assets bypass the maintenance rewrite': all(x in ht for x in ('css/maintenance\\.css','js/maintenance\\.js','assets/logo-full\\.png','assets/i18n/')),
 'maintenance has usable no-JS/no-CSS fallback copy': all(x in maint for x in ('MeteoNexa è in manutenzione','RILASCIO IN CORSO','Riprova ora','style="min-height:100vh')),
 'welcome language menu participates in auth layout': '#auth-view .welcome-language-menu{position:relative!important' in css and 'bottom:auto!important' in css,
 'nightly dependency security workflow exists': (ROOT/'.github/workflows/dependency-security.yml').is_file(),
 'browser performance regression is included': (ROOT/'qa/e2e/performance.spec.mjs').is_file(),
 'production-like maintenance assets are tested in staging CI': 'Verify maintenance mode surface and assets' in workflow and 'maintenance.flag' in workflow,
 'worker is backend-only': bool(re.search(r'(?ms)^  worker:.*?^    networks:\n      - backend\n(?=\n|volumes:)', compose)) and 'worker:\n' in compose,
 'P0 contract covers reporting and worker isolation': 'CSP reporting is configured' in p0 and 'worker stays off public proxy network' in p0,
 'radar stale async retries are version-gated': 'radarLayerSelectionVersion' in radar and 'selectionVersion !== radarLayerSelectionVersion' in radar,
}
failed=[name for name,ok in checks.items() if not ok]
for name,ok in checks.items(): print(f"[{'OK' if ok else 'FAIL'}] {name}")
if failed: raise SystemExit('P6 production UX/security smoke FAILED: '+', '.join(failed))
print('P6 production UX/security smoke PASS')
