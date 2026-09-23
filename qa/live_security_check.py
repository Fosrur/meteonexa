#!/usr/bin/env python3
import sys, ssl, socket, urllib.request, urllib.parse, urllib.error
from datetime import datetime, timezone

url=sys.argv[1] if len(sys.argv)>1 and not sys.argv[1].startswith('--') else 'https://www.meteonexa.com/'
allow_maintenance='--allow-maintenance' in sys.argv[2:]
p=urllib.parse.urlparse(url)
if p.scheme!='https' or not p.hostname: raise SystemExit('FAIL: URL HTTPS richiesta')
req=urllib.request.Request(url,headers={'User-Agent':'MeteoNexa-Release-Security/20.1','Accept':'text/html'})
body=b''
try:
  with urllib.request.urlopen(req,timeout=20,context=ssl.create_default_context()) as r:
    final=r.geturl(); status=r.status; h={k.lower():v for k,v in r.headers.items()}; body=r.read(262144)
except urllib.error.HTTPError as e:
  if not (allow_maintenance and e.code==503):
    raise SystemExit(f'FAIL HTTPS fetch: HTTP {e.code}')
  final=e.geturl(); status=e.code; h={k.lower():v for k,v in e.headers.items()}; body=e.read(262144)
except Exception as e: raise SystemExit(f'FAIL HTTPS fetch: {e}')

maintenance_expected=allow_maintenance
checks={
  ('HTTP 503 maintenance' if maintenance_expected else 'HTTP 200'): status==(503 if maintenance_expected else 200),
  'final HTTPS':urllib.parse.urlparse(final).scheme=='https',
  'HSTS':bool(h.get('strict-transport-security')),
  'CSP':'default-src' in h.get('content-security-policy',''),
  'frame protection':h.get('x-frame-options','').upper() in {'DENY','SAMEORIGIN'} or 'frame-ancestors' in h.get('content-security-policy',''),
  'nosniff':h.get('x-content-type-options','').lower()=='nosniff',
  'referrer policy':bool(h.get('referrer-policy')),
  'permissions policy':bool(h.get('permissions-policy')),
  'CSP reporting endpoint':'report-to csp-endpoint' in h.get('content-security-policy','') and '/api/csp-report.php' in h.get('reporting-endpoints',''),
  'legacy CSP report-uri':'report-uri /api/csp-report.php' in h.get('content-security-policy',''),
}
if maintenance_expected:
  decoded=body.decode('utf-8','replace')
  checks['maintenance surface']='maintenance-card' in decoded and 'maintenance-critical' in decoded
  checks['Retry-After']=bool(h.get('retry-after'))
ctx=ssl.create_default_context()
with socket.create_connection((p.hostname,p.port or 443),timeout=15) as raw:
  with ctx.wrap_socket(raw,server_hostname=p.hostname) as tls: proto=tls.version() or ''; cert=tls.getpeercert()
checks['TLS >= 1.2']=proto in {'TLSv1.2','TLSv1.3'}
days=(datetime.fromtimestamp(ssl.cert_time_to_seconds(cert['notAfter']),timezone.utc)-datetime.now(timezone.utc)).total_seconds()/86400
checks['certificate > 14 days']=days>14
for k,v in checks.items(): print(f"[{'OK' if v else 'FAIL'}] {k}")
failed=[k for k,v in checks.items() if not v]
if failed: raise SystemExit('Live security FAILED: '+', '.join(failed))
mode='maintenance' if maintenance_expected else 'live'
print(f'Live security PASS ({mode}): {final} | HTTP {status} | {proto} | cert {days:.0f} days')
