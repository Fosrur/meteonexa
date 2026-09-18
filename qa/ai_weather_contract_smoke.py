#!/usr/bin/env python3
from pathlib import Path
import json, re, sys
ROOT=Path(__file__).resolve().parents[1]
chat=(ROOT/'api/ai/chat.php').read_text(encoding='utf-8')
suite=(ROOT/'suite.js').read_text(encoding='utf-8')
config=(ROOT/'api/config.php').read_text(encoding='utf-8')
privacy=(ROOT/'privacy.html').read_text(encoding='utf-8')
cookie=(ROOT/'cookie-policy.html').read_text(encoding='utf-8')
checks={
 'weather contract builder':'function meteonexa_ai_weather_contract' in chat,
 'strict json schema':'meteonexa_ai_weather_schema' in chat and "'type'=>'json_schema'" in chat and "'strict'=>true" in chat,
 'numeric prose rejection':"'numeric_claim_in_text'" in chat and "preg_match('/\\d/u'" in chat,
 'evidence id validation':"'no_valid_evidence'" in chat and "isset($facts[$id])" in chat,
 'openrouter privacy routing':"'data_collection'=>'deny'" in chat and "'require_parameters'=>true" in chat,
 'weather model pinned':"METEONEXA_WEATHER_AI_MODEL" in config and "openai/gpt-5.6-sol" in config,
 'fallback model configured':"METEONEXA_WEATHER_AI_FALLBACK_MODEL" in config and "'models'" in chat,
 'structured client rendering':'assistantContractHtml' in suite and 'assistant-contract-evidence' in suite,
 'tab session history':'sessionStorage.getItem(KEYS.assistant)' in suite and 'sessionStorage.setItem(KEYS.assistant' in suite,
 'privacy docs session history':'privacy.ai.session_history' in privacy and 'cookie.assistant_session.copy' in cookie,
 'plaintext provision removed':not (ROOT/'api/install/ai-provider-provision.json').exists(),
 'deployment bootstrap not packaged':not (ROOT/'api/install/ai-provider-bootstrap.json').exists(),
}
for label,ok in checks.items(): print(('[ OK ] ' if ok else '[FAIL] ')+label)
failed=[k for k,v in checks.items() if not v]
# scan release text files for OpenRouter plaintext-secret prefix
leaks=[]
for p in ROOT.rglob('*'):
    if not p.is_file() or p.suffix.lower() in {'.sqlite','.png','.jpg','.jpeg','.webp','.ico','.woff','.woff2'}: continue
    try:b=p.read_bytes()
    except:continue
    import re
    if re.search(rb'sk-or-v1-[A-Za-z0-9_-]{40,}', b): leaks.append(str(p.relative_to(ROOT)))
if leaks:
    failed.append('plaintext OpenRouter key'); print('[FAIL] plaintext OpenRouter key: '+', '.join(leaks))
else: print('[ OK ] no plaintext OpenRouter key pattern')
if failed:
    print('FAILED: '+', '.join(failed),file=sys.stderr); sys.exit(1)
print('AI Weather Contract smoke PASS')
