#!/usr/bin/env python3
from pathlib import Path
import json,sys
root=Path(__file__).resolve().parents[1]
bootstrap=(root/'modules/esm/bootstrap.mjs').read_text(encoding='utf-8')
registry=(root/'modules/esm/core/service-registry.mjs').read_text(encoding='utf-8')
privacy=(root/'privacy.html').read_text(encoding='utf-8')
cookie=(root/'cookie-policy.html').read_text(encoding='utf-8')
ht=(root/'.htaccess').read_text(encoding='utf-8')
compose=(root/'docker-compose.yml').read_text(encoding='utf-8')
manifest=json.loads((root/'asset-manifest.json').read_text(encoding='utf-8'))
checks={
 'analytics runtime assets removed': not (root/'analytics.js').exists() and not (root/'product-metrics.js').exists() and 'analytics.js' not in manifest and 'product-metrics.js' not in manifest and "'analytics.js'" not in bootstrap and "'product-metrics.js'" not in bootstrap,
 'analytics endpoints removed': not (root/'api/analytics/config.php').exists() and not (root/'api/metrics/product.php').exists(),
 'analytics service removed from registry': 'MeteoNexaAnalytics' not in registry and "analytics:" not in registry,
 'Plausible removed from CSP': 'plausible.io' not in ht,
 'analytics deployment variables removed': 'METEONEXA_PLAUSIBLE_' not in compose and 'METEONEXA_PRODUCT_METRICS_' not in compose,
 'privacy page has no analytics processing section': 'privacy-analytics-title' not in privacy and 'privacy.analytics.processing' not in privacy and 'privacy.metrics.' not in privacy and 'dist/analytics.' not in privacy,
 'cookie page has no analytics section': 'cookie.analytics.' not in cookie and 'cookie.metrics.' not in cookie and 'dist/analytics.' not in cookie,
}
failed=[]
for name,ok in checks.items():
 print(('PASS' if ok else 'FAIL')+': '+name)
 if not ok: failed.append(name)
if failed:
 print('Traffic analytics removal smoke FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Traffic analytics removal smoke PASS')
