#!/usr/bin/env python3
from pathlib import Path
import json,re,sqlite3,sys
root=Path(__file__).resolve().parents[1]
fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m); fail.append(m) if not v else None
analytics=(root/'analytics.js').read_text(encoding='utf-8')
config=(root/'api/config.php').read_text(encoding='utf-8')
api=(root/'api/analytics/config.php').read_text(encoding='utf-8')
index=(root/'index.html').read_text(encoding='utf-8')
privacy=(root/'privacy.html').read_text(encoding='utf-8')
cookie=(root/'cookie-policy.html').read_text(encoding='utf-8')
ht=(root/'.htaccess').read_text(encoding='utf-8')
sw=(root/'sw.js').read_text(encoding='utf-8')
bootstrap=(root/'modules/esm/bootstrap.mjs').read_text(encoding='utf-8')
diag=(root/'api/diagnostics/check.php').read_text(encoding='utf-8')
rows=json.loads((root/'api/install/translations.json').read_text(encoding='utf-8'))['rows']
by={(r['locale'],r['text_key']):r['translation'] for r in rows}
locales={'it','en','es','fr','de'}

ck("METEONEXA_PLAUSIBLE_ENABLED" in config and "METEONEXA_PLAUSIBLE_DOMAIN" in config,'Plausible deployment flags exist')
ck("'endpoint' => 'https://plausible.io/api/event'" in config and "'provider' => 'plausible'" in config,'Plausible endpoint/provider fixed server-side')
ck("'endpoint'=>'https://plausible.io/api/event'" in api and "'customEvents'=>false" in api,'public analytics config is pageview-only')
ck("credentials: 'omit'" in analytics and "name: 'pageview'" in analytics,'browser sends cookieless pageviews without application credentials')
ck("navigator.globalPrivacyControl" in analytics and 'navigator.doNotTrack' in analytics,'GPC and DNT are respected')
ck("meteonexa_analytics_optout_v1" in analytics and 'localStorage' in analytics,'local opt-out preference is explicit')
ck(all(x not in analytics for x in ['latitude','longitude','deviceId','accountId','assistant-input','question','aiText','routeCoordinates']),'analytics client has no sensitive application dimensions')
ck("ACQUISITION_KEYS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ref','source']" in analytics,'acquisition parameters are closed-allowlist')
ck("return `${ref.protocol}//${ref.hostname}/`;" in analytics,'external referrer is reduced to origin')
ck("https://plausible.io" in ht and "script-src 'self'" in ht,'CSP allows Plausible connect only while scripts remain same-origin')
ck('src="https://plausible.io' not in index and 'src="https://plausible.io' not in privacy and 'src="https://plausible.io' not in cookie,'no third-party Plausible JavaScript is loaded')
manifest=json.loads((root/'asset-manifest.json').read_text(encoding='utf-8'))
analytics_asset=manifest.get('analytics.js','').removeprefix('./')
ck(bool(analytics_asset) and "'analytics.js'" in bootstrap and analytics_asset not in index and analytics_asset in privacy and analytics_asset in cookie,'analytics module covers app bootstrap and legal pages')
ck("asset('analytics.js')" in sw and "analytics.js" in (root/'tools/fingerprint_assets.py').read_text(),'analytics asset participates in immutable shell/fingerprint contract')
ck('privacy-analytics-toggle' in index and 'privacy.analytics.short_notice' in index,'Privacy Center exposes analytics opt-out and disclosure')
ck('privacy.analytics.processing.data' in privacy and 'privacy.external.analytics' in privacy,'Privacy Policy contains analytics processing/provider disclosure')
ck('cookie.analytics.title' in cookie and 'cookie.analytics.preference' in cookie,'Cookie Policy documents cookieless analytics and opt-out storage')
for key in ['privacy.analytics.fact.title','privacy.analytics.copy','privacy.analytics.optout','privacy.analytics.processing.data','privacy.external.analytics','cookie.analytics.title','cookie.analytics.copy','diag.analytics.title','diag.result.analytics.test.ok']:
    ck(all(str(by.get((loc,key),'')).strip() for loc in locales),f'i18n complete: {key}')
ck(re.search(r"\$action\s*===\s*['\"]analytics['\"]", diag) and re.search(r"['\"]provider['\"]\s*=>\s*['\"]plausible['\"]", diag) and re.search(r"['\"]coordinates['\"]\s*=>\s*false", diag) and re.search(r"['\"]aiText['\"]\s*=>\s*false", diag),'Diagnostics exposes safe analytics configuration state')
ck('product_metrics_daily' in (root/'api/metrics/product.php').read_text() and 'Plausible' not in (root/'api/metrics/product.php').read_text(),'Product Metrics remain separate from Plausible')
con=sqlite3.connect(root/'api/install/meteonexa-baseline.sqlite')
tables={r[0] for r in con.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")}
meta=dict(con.execute("select meta_key,meta_value from app_metadata where meta_key in ('app_version','schema_version','translation_seed_version')"))
con.close()
ck(len(tables)==47,'analytics adds no database table')
ck(meta=={'app_version':'20.1','schema_version':'28','translation_seed_version':'20.1-semantic-i18n-v2'},'20.1 metadata/schema contract')
print('\nAnalytics & Observability: '+('PASS' if not fail else 'FAIL')+f' ({len(fail)} failures)')
sys.exit(bool(fail))
