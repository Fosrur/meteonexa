#!/usr/bin/env python3
from pathlib import Path
import re, sys
root=Path(__file__).resolve().parents[1]; fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m); fail.append(m) if not v else None
def t(p): return (root/p).read_text(encoding='utf-8')
def compact(source): return re.sub(r'\s+', '', source)
html=t('index.html'); app=t('js/app.js'); advanced=t('js/advanced.js'); suite=t('js/suite.js')
quality=t('api/intelligence/quality_helpers.php'); radar=t('qa/radar3_production_gate_smoke.php')
analytics=t('qa/traffic_analytics_removed_smoke.py'); decision=t('api/intelligence/decision_timeline_helpers.php')
prob=t('api/intelligence/probabilistic_nowcast_helpers.php'); accuracy=t('api/accuracy/public.php')
watch=t('api/plans/watch_engine.php'); intel=t('js/weather-intelligence.js'); cop=t('api/ai/orchestrator.php'); route=t('api/route/weather_engine.php')
# Reliability consistency
m=re.search(r'function\s+meteonexa_intelq_model_definitions\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[(.*?)\];\s*\}',quality,re.S)
providers=re.findall(r"'([a-z0-9_]+)'\s*=>\s*\[",m.group(1)) if m else []
ck(len(providers)==6,'six canonical provider families are defined server-side')
ck('loadForecastFusion({ force })' in app and 'loadForecastFusion({ force })' in advanced,'Home/Intelligence/Advanced reuse server fusion evidence')
ck(all(token not in app+advanced+suite for token in ['CONFIG.ECMWF_API','CONFIG.AIFS_API','CONFIG.ICON_API','CONFIG.GFS_API','CONFIG.METEOFRANCE_API','CONFIG.UKMO_API']),'browser has no direct model-provider fan-out')
ck('20' in radar and 'probation' in radar.lower(),'Radar authority retains evidence-gated probation coverage')
# Analytics/tracking removal
ck('product-metrics.js' in analytics and 'api/metrics/product.php' in analytics and 'privacy.metrics.' in analytics,'user-behaviour analytics removal remains enforced')
# Decision timeline / forecast change / explainability / skill
activities=['run','bike','motorcycle','sea','trekking','kids','pets','worksite','commute','event','photography']
ck(all(("'"+a+"'=>") in decision for a in activities),'Decision Timeline retains all supported activity profiles')
ck("timeShiftMinutes'=>30" in decision and "rainProbabilityDelta'=>15" in decision and "agreementDelta'=>15" in decision,'Forecast Change filters non-decisional noise')
ck('intelq-explain-panel' in html and 'explainability' in intel,'Explainability remains visible and evidence-driven')
ck('intelq-explain-condition' in html and "intelq.explain.change.title" in intel,'Explainability states what fresh evidence could change the forecast')
ck('byMetricHorizon' in intel and 'eligibleForRanking' in intel,'verified model skill remains visible by metric/horizon')
# Probabilistic nowcast
ck(all(key in prob for key in ['rainProbabilityPct','stormProbabilityPct','gustProbabilityPct','hailPotentialScore','arrivalDistribution']),'probabilistic Nowcast exposes rain/storm/gust/hail-potential/arrival evidence')
ck('hailIsPotentialScoreNotProbability' in prob and 'radar3ProbationHonoured' in prob,'probabilistic semantics keep hail and radar authority guardrails')
# Public accuracy
ck('$days=60' in compact(accuracy) and 'minimumContributors=3' in compact(accuracy) and 'minimumSamples=20' in compact(accuracy),'Public Local Accuracy remains thresholded and fail-closed')
# Watch plan
ck('time()+72*3600' in compact(watch) and '10800' in compact(watch) and '$drop>=15' in compact(watch),'Watch My Plan retains horizon/materiality/cooldown safeguards')
# Copilot orchestration
ck('meteonexa_intelq_canonical_consensus' in cop and 'meteonexa_route_analyze' in cop,'Copilot uses canonical consensus and shared Route engine')
ck(all(x in cop for x in ["$tools['official_warnings']","$tools['convective_risk']","$tools['trust_score']",'criticalCrosswindKmh']),'Copilot exposes official/convective/trust/wind-crosswind evidence')
ck("'preciseCoordinatesExcludedFromLlm' => true" in cop and "'routeCoordinatesExcludedFromLlm' => true" in cop,'Copilot excludes precise and sampled route coordinates from LLM payload')
ck('crosswindKmh' in route and 'windKmh' in route and 'gustKmh' in route,'Route engine computes wind/gust/crosswind risk')
print('\nRoadmap contract: '+('PASS' if not fail else 'FAIL'))
sys.exit(bool(fail))
