#!/usr/bin/env python3
from pathlib import Path
import re,sqlite3,sys
root=Path(__file__).resolve().parents[1];fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m); fail.append(m) if not v else None
client=(root/'product-metrics.js').read_text(); endpoint=(root/'api/metrics/product.php').read_text(); config=(root/'api/config.php').read_text(); db='\n'.join(path.read_text() for path in sorted((root/'api/database').glob('*.php')))
expected={'page_home','page_radar','page_intelligence','search_location','open_alert','open_explainability','use_route','use_ai','enable_notifications','bug_report','install_pwa'}
quoted=set(re.findall(r"'([a-z_]+)'",client)); a=endpoint.find('$allowed'); b=endpoint.find('], true);',a); server=set(re.findall(r"'([a-z_]+)'",endpoint[a:b]))
ck(expected<=quoted,'client contains product event allowlist')
ck(server==expected,'server allowlist is exact')
ck("credentials: 'omit'" in client,'metrics request omits credentials')
ck(all(x not in client for x in ['localStorage','sessionStorage','document.cookie','deviceId','latitude','longitude','email','locationName','question','message']),'client has no persistent/sensitive analytics dimensions')
ck('array_diff(array_keys($input)' in endpoint and 'require_global_rate_limit' in endpoint and 'assert_same_origin();' in endpoint,'endpoint rejects extras and enforces abuse/origin guard')
ck('METEONEXA_PRODUCT_METRICS_ENABLED' in config and 'METEONEXA_PRODUCT_METRICS_RETENTION_DAYS' in config,'kill switch and retention setting exist')
ck('product_metrics_daily' in db,'aggregate metrics schema self-heals')
con=sqlite3.connect(root/'api/install/meteonexa-baseline.sqlite'); cols=[r[1] for r in con.execute('pragma table_info(product_metrics_daily)')];con.close();ck(cols==['metric_date','event_name','event_count','updated_at'],'metrics table is aggregate-only')
print('\nProduct Metrics: '+('PASS' if not fail else 'FAIL'));sys.exit(bool(fail))
