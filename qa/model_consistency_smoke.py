#!/usr/bin/env python3
from pathlib import Path
import re,sys
root=Path(__file__).resolve().parents[1]; errors=[]
app=(root/'js/app.js').read_text(encoding='utf-8');suite=(root/'js/suite.js').read_text(encoding='utf-8');fusion=(root/'api/weather/fusion.php').read_text(encoding='utf-8');quality=(root/'api/intelligence/quality_helpers.php').read_text(encoding='utf-8');summary=(root/'api/intelligence/summary.php').read_text(encoding='utf-8')
if re.search(r'modelsExpected\s*:\s*5\b|modelsExpected\s*\|\|\s*5\b',app): errors.append('legacy five-model denominator in app')
if '(5 - rows.length)' in suite: errors.append('legacy five-model confidence arithmetic')
if "suite.canonicalConsensus?.modelsExpected || rows.length" not in suite: errors.append('client confidence expected count is not server-consensus-driven')
if 'count($modelDefinitions)' not in fusion: errors.append('fusion expected model count is not provider-driven')
if "'modelsExpected'=>count(meteonexa_intelq_model_definitions())" not in summary: errors.append('intelligence summary expected count is not provider-driven')
m=re.search(r'function\s+meteonexa_intelq_model_definitions\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[(.*?)\];\s*\}',quality,re.S)
ids=re.findall(r"'([a-z0-9_]+)'\s*=>\s*\[",m.group(1)) if m else []
if len(ids)!=6: errors.append(f'provider definition count={len(ids)}')
print('Model consistency: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
