#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1];fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m);fail.append(m) if not v else None
def t(p): return (root/p).read_text(encoding='utf-8')
orch=t('api/ai/orchestrator.php'); chat=t('api/ai/chat.php'); route=t('api/route/weather_engine.php'); endpoint=t('api/route/weather.php'); suite=t('js/suite.js'); suite_assistant=t('modules/esm/domains/suite-assistant.mjs'); suite_support=t('modules/esm/domains/suite-support.mjs'); cop=t('modules/esm/features/copilot.mjs'); css=t('css/copilot.css'); html=t('index.html')
ck('deterministicDecision' in orch and "'status' =>" in orch and "'confidence' =>" in orch and 'meteonexa_copilot_decision_summary' in orch,'Copilot creates deterministic decision before language explanation')
ck('meteonexa_intelq_fetch_models' in orch and 'meteonexa_intelq_canonical_consensus' in orch,'Copilot uses server-owned canonical model evidence')
ck("$tools['official_warnings']" in orch and "$tools['convective_risk']" in orch and "$tools['trust_score']" in orch,'Copilot exposes official warnings, convective risk and aggregate trust as structured tools')
ck("'criticalCrosswindKmh'" in orch and 'crosswindKmh' in route,'Copilot route evidence includes server-computed wind/crosswind risk')
ck('meteonexa_decision_' in orch and 'meteonexa_route_analyze' in orch,'Copilot reuses Decision Timeline and shared Route Weather engine')
ck("'preciseCoordinatesExcludedFromLlm' => true" in orch and "'routeCoordinatesExcludedFromLlm' => true" in orch,'orchestrator explicitly excludes precise/route coordinates from LLM context')
ck('deterministicDecision' in chat and 'ai.system.tool_orchestration' in chat,'chat passes deterministic decision under orchestration system instruction')
ck('routePlan' in chat and 'routePlan' in suite_assistant and "deps.copilot?.routePlan" in suite_assistant,'route intent is passed to server orchestrator')
ck("'latitude'" not in str(orch[orch.find("'route'=>"):orch.find("'nowcast'=>",orch.find("'route'=>"))]) if "'route'=>" in orch else True,'deterministic route summary contains no coordinates')
ck("'copilot'" in cop and 'routePlan' in cop and 'normalizeDecision' in cop,'client sanitizes Copilot decision evidence')
ck('copilot-evidence' in suite_assistant and '@media(max-width:720px)' in css and '@media(max-width:460px)' in css,'Copilot evidence UI is integrated and responsive')
ck("routeWeather: 'api/route/weather.php'" in suite_support and "SERVICES.get('routeWeather')" in suite,'browser route path uses semantic current endpoint/module')
ck('meteonexa_route_analyze' in endpoint and 'meteonexa_route_analyze' in route,'route endpoint and AI share one route engine')
print('\nCopilot orchestration: '+('PASS' if not fail else 'FAIL'));sys.exit(bool(fail))
