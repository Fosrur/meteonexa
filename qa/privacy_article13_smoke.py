#!/usr/bin/env python3
from pathlib import Path
import json, sqlite3, sys
root=Path(__file__).resolve().parents[1]
privacy=(root/'privacy.html').read_text(encoding='utf-8')
context=(root/'js/privacy-context.js').read_text(encoding='utf-8')
required_keys={
 'privacy.art13.title','privacy.art13.scope','privacy.art13.controller.title','privacy.art13.controller.copy',
 'privacy.art13.dpo.title','privacy.art13.dpo.copy','privacy.art13.dpo.configured','privacy.art13.processing.title',
 'privacy.art13.processing.email.basis','privacy.art13.processing.email.retention',
 'privacy.art13.processing.security.basis','privacy.art13.processing.security.retention',
 'privacy.art13.processing.optional.basis',
 'privacy.art13.recipients.title','privacy.art13.recipients.copy',
 'privacy.art13.transfers.title','privacy.art13.transfers.copy','privacy.art13.transfers.groq_link',
 'privacy.art13.rights.title','privacy.art13.rights.copy',
 'privacy.art13.consent.title','privacy.art13.consent.copy',
 'privacy.art13.complaint.title','privacy.art13.complaint.copy',
 'privacy.art13.provision.title','privacy.art13.provision.copy',
 'privacy.art13.automated.title','privacy.art13.automated.copy',
 'privacy.art13.further.title','privacy.art13.further.copy',
 'privacy.access_history.revoke_scope'
}
assert '<section data-email-auth-only hidden id="privacy-email-access-history">' in privacy
assert "node.hidden = !(authenticated && verified)" in context
assert 'privacy_article13_smoke.data_controller_party_publishes_meteonexa_its_domain_personal' not in privacy, 'generic controller copy must not remain in the rendered privacy page'
assert 'https://eur-lex.europa.eu/eli/reg/2016/679/oj' in privacy
assert 'https://www.garanteprivacy.it/diritti/come-agire-per-tutelare-i-tuoi-dati-personali/reclamo' in privacy
assert 'https://openrouter.ai/privacy/' in privacy
assert 'https://groq.com/privacy-policy/' in privacy
assert 'privacy-controller-name' in privacy and 'privacy-controller-address' in privacy
assert 'privacy-controller-warning' not in privacy
assert 'privacy-dpo-email' in privacy
dynamic_keys={'privacy.access_history.revoke_scope','privacy.art13.dpo.configured'}
assert all(f'data-i18n-key="{k}"' in privacy for k in required_keys if k not in dynamic_keys)

seed=json.loads((root/'api/install/translations.json').read_text(encoding='utf-8'))
rows={(r['locale'],r['text_key']):str(r['translation']) for r in seed['rows']}
locales={'it','en','fr','es','de'}
for key in required_keys:
    for loc in locales:
        assert rows.get((loc,key),'').strip(), f'missing {loc}:{key}'
it=' '.join(rows[('it',k)] for k in required_keys)
for token in ['art. 6','lett. b','lett. f','art. 77','30 giorni','art. 22']:
    assert token.lower() in it.lower(), token

con=sqlite3.connect(root/'api/install/meteonexa-baseline.sqlite')
for key in required_keys:
    n=con.execute('select count(*) from translations where text_key=?',(key,)).fetchone()[0]
    assert n==5, (key,n)
assert con.execute("select meta_value from app_metadata where meta_key='app_version'").fetchone()[0]=='20.1'
assert con.execute("select meta_value from app_metadata where meta_key='translation_seed_version'").fetchone()[0]=='20.1-semantic-i18n-v2'
con.close()
config=(root/'api/config.php').read_text(encoding='utf-8')
ui=(root/'api/ui-config.php').read_text(encoding='utf-8')
env=(root/'.env.example').read_text(encoding='utf-8')
for token in ['METEONEXA_LEGAL_CONTROLLER_NAME','METEONEXA_LEGAL_CONTROLLER_ADDRESS','METEONEXA_PRIVACY_CONTACT_EMAIL','METEONEXA_DPO_EMAIL']:
    assert token in config and token in env, token
for token in ['controllerName','controllerAddress','legalConfigured','dpoEmail','aiProvider']:
    assert token in ui, token
assert "setKey('privacy-dpo-copy', 'privacy.art13.dpo.configured')" in context
print('Privacy Article 13 smoke PASS')
