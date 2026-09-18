#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
read=lambda p:(ROOT/p).read_text(encoding='utf-8')
advanced=read('advanced.js'); suite=read('suite.js'); integ=read('modules/esm/domains/suite-integrations.mjs')
nav=read('modules/esm/domains/navigation.mjs'); loc=read('modules/esm/domains/locations.mjs'); radar=read('modules/esm/domains/radar-controller.mjs'); app=read('app.js'); css=read('styles/suite/40-ui-regression-fixes.css')
checks={
 'advanced service remains immutable':'Object.freeze({ build: ADVANCED_BUILD' in advanced,
 'suite classic does not patch frozen advanced':'original.renderAll =' not in suite and 'function patchLifecycle()' not in suite,
 'suite integrations do not patch frozen advanced':'original.renderAll =' not in integ and 'deps.advanced' not in integ[integ.find('function extendSuiteLifecycle'):integ.find('let initialized')],
 'suite exposes lifecycle hooks':all(x in suite for x in ('renderAll: renderAllSuite','onPage','locationChanged: locationChangedSuite')),
 'navigation fans out to advanced and suite':'deps.advanced?.onPage?.' in nav and 'deps.suite?.onPage?.' in nav,
 'location changes fan out to suite':'deps.suite?.locationChanged?.' in loc and "'suite'" in loc.split('\n',3)[1],
 'radar changes fan out to suite':'deps.suite?.locationChanged?.' in radar and "'suite'" in radar.split('\n',3)[1],
 'app repaint fans out to suite':"SERVICES.get('suite')?.renderAll?.()" in app,
 'manual refresh fans out to suite':"SERVICES.get('suite')?.afterRefresh?.()" in app,
 'guest control owns full pointer surface':'#guest-login>*{pointer-events:none!important}' in css and 'width:100%!important' in css,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(f"[{'OK' if v else 'FAIL'}] {k}")
if failed: print('Lifecycle contract FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Lifecycle contract smoke PASS')
