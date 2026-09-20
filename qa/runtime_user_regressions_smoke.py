#!/usr/bin/env python3
from pathlib import Path
import sys
R=Path(__file__).resolve().parents[1]
def t(p): return (R/p).read_text(encoding="utf-8")
app=t("js/app.js"); nav=t("modules/esm/domains/navigation.mjs"); account=t("modules/esm/domains/account.mjs"); assistant=t("modules/esm/domains/suite-assistant.mjs"); suite=t("js/suite.js"); suite_css=t("css/suite.css"); advanced=t("js/advanced.js"); integ=t("modules/esm/domains/suite-integrations.mjs"); ht=t(".htaccess"); html=t("index.html"); e2e=t("qa/e2e/ui-controls.spec.mjs"); charts=t("qa/e2e/charts.spec.mjs"); workflow=t(".github/workflows/meteonexa-tests.yml"); helper=t("qa/e2e/test-helpers.mjs")
checks={
 "guest local assistant visible": "'feature.assistant': { guest: true, authenticated: true }" in app and "featureKey === 'feature.assistant' && mode === 'guest'" in nav,
 "guest assistant opens local": 'button.hidden = guest' in assistant and "if (guest) setAssistantMode('local', false)" in assistant,
 "assistant center has delegated binding": "closest?.('#assistant-center-button')" in suite and "cloneAndBind('#assistant-center-button'" not in suite,
 "assistant controls delegated": "closest?.('#assistant-close')" in suite and "closest?.('#assistant-clear')" in suite and "closest?.('[data-assistant-question]')" in suite and "addEventListener('submit'" in suite and "cloneAndBind('#assistant-close'" not in suite,
 "guest AI hidden wins over dialog display rules": '#assistant-dialog .assistant-mode-button[hidden]{display:none!important}' in suite_css,
 "guest AI blocked": "requested === 'ai' && isGuest() ? 'local'" in assistant and "if (!guest && suite.assistantMode === 'ai')" in assistant,
 "device identity from module security": 'deps.security?.deviceId' in account and 'function create(context)' in account and 'function create(deps)' not in account,
 "empty device sync blocked": 'accountSyncDeviceReady()' in account and '!accountSyncDeviceReady()' in account,
 "lightning button not cloned": 'cloneNode(true)' not in integ and "SERVICES.get('suiteIntegrations') || SERVICES.get('suite')" in advanced,
 "radar layers use delegated click binding": "closest?.('[data-advanced-radar-layer]')" in advanced and "qa('[data-advanced-radar-layer]').forEach(button => button.addEventListener" not in advanced,
 "lightning refresh follows active radar page": "q('#page-radar')?.classList.contains('active-page')" in integ and "q('#page-radar')?.classList.contains('active')" not in integ,
 "www canonical redirect": '^meteonexa\\.com$' in ht and 'https://www.meteonexa.com%{REQUEST_URI}' in ht,
 "www metadata": 'https://www.meteonexa.com/' in html,
 "live security uses www": 'qa/live_security_check.py https://www.meteonexa.com/' in workflow,
 "scheduled QA cannot cancel push QA": 'group: meteonexa-qa-${{ github.event_name }}-${{ github.ref }}' in workflow,
 "browser regression rebuilds production assets": 'Build production frontend for browser regression' in workflow and 'npm run build:production' in workflow,
 "browser E2E preserves app-ready and adds Assistant interactive-ready": "__meteonexaQaReady" in helper and "__meteonexaInteractiveReady" in helper and "meteonexa:interactive-ready" in helper and "waitForMeteoNexaInteractiveReady" in helper,
 "radar layers browser-clicked": 'await control.click({ trial: true });' in e2e and 'await control.click();' in e2e and 'await expect(control).toHaveClass(/active/);' in e2e,
 "built chart assertion is minification-safe": "chart-interaction-layer" in charts and "function registerChartInteraction(canvas, meta)" not in charts,
 "readme name": (R/'readme.md').is_file() and not (R/'reade.md').exists(),
}
failed=[]
for name,ok in checks.items(): print(('PASS' if ok else 'FAIL')+': '+name); failed += [] if ok else [name]
if failed: print('Runtime user regressions FAILED: '+', '.join(failed),file=sys.stderr); sys.exit(1)
print('Runtime user regressions PASS')
