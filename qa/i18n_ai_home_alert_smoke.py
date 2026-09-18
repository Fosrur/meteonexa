#!/usr/bin/env python3
from pathlib import Path
import json,re,sys
ROOT=Path(__file__).resolve().parents[1]
trans=json.loads((ROOT/'api/install/translations.json').read_text(encoding='utf-8'))
rows=trans['rows']; locales={'it','en','fr','es','de'}; by={}
for row in rows: by.setdefault(row['text_key'],{})[row['locale']]=row['translation']
required=[
 'home.official.kicker','home.official.details','home.official.none.title','home.official.none.copy','home.official.active.copy','home.official.active.multiple','home.official.source','ai.system.no_markdown',
 'intelligence.event.rain.now.title','intelligence.event.storm.near.title','intelligence.event.wind.title','intelligence.event.snow.title','intelligence.event.official.title','intelligence.summary.none'
]
checks=[]
checks.append(('new i18n keys complete',all(k in by and set(by[k])==locales and all(str(v).strip() for v in by[k].values()) for k in required)))
app=(ROOT/'app.js').read_text(encoding='utf-8'); suite=(ROOT/'suite.js').read_text(encoding='utf-8'); suite_assistant=(ROOT/'modules/esm/domains/suite-assistant.mjs').read_text(encoding='utf-8'); chat=(ROOT/'api/ai/chat.php').read_text(encoding='utf-8'); engine=(ROOT/'api/intelligence/engine_helpers.php').read_text(encoding='utf-8'); html=(ROOT/'index.html').read_text(encoding='utf-8')
checks += [
 ('home official warning card wired','id="home-official-alert"' in html and 'loadHomeOfficialAlerts' in app and 'renderHomeOfficialAlert' in app and 'api/official/alerts.php' in app),
 ('home warning is location-scoped','officialAlertsLocationKey' in app and "toFixed(3)" in app),
 ('AI server strips Markdown','function meteonexa_ai_plain_output' in chat and 'meteonexa_ai_plain_output(meteonexa_ai_extract_answer' in chat and "ai.system.no_markdown" in chat),
 ('AI client strips Markdown','function assistantPlainText' in suite_assistant and "const answer = assistantPlainText" in suite_assistant),
 ('assistant history tab-session only','sessionStorage.getItem(KEYS.assistant)' in suite_assistant and 'sessionStorage.setItem(KEYS.assistant' in suite_assistant and 'localStorage.removeItem(KEYS.assistant)' in suite),
 ('intelligence event text backend-i18n',"meteonexa_backend_text('intelligence.event.rain.now.title')" in engine and "meteonexa_backend_text('intelligence.summary.none')" in engine),
 ('old Italian event literals removed',not any(x in engine for x in ['Pioggia in arrivo','Temporale in avvicinamento','Raffiche di vento','Neve possibile','Nessun fenomeno rilevante nelle prossime ore.'])),
 ('Smart source placeholders localized','id="smart-source-radar" data-i18n-key="smart.source.radar"' in html and 'Official: --' not in html and 'Models: --' not in html),
]
for label,ok in checks: print(('[ OK ] ' if ok else '[FAIL] ')+label)
failed=[label for label,ok in checks if not ok]
if failed:
 print('FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('I18N / AI plain text / Home alert smoke PASS')
