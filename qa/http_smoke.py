#!/usr/bin/env python3
from __future__ import annotations
import argparse, json, sys, urllib.error, urllib.request

def request(url: str, accept='application/json'):
    req=urllib.request.Request(url,headers={'User-Agent':'MeteoNexa-QA/20.1','Accept':accept})
    try:
        with urllib.request.urlopen(req,timeout=15) as r:
            return r.status,r.read(2_000_000),r.headers.get('content-type','')
    except urllib.error.HTTPError as e:
        return e.code,e.read(2_000_000),e.headers.get('content-type','')

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('base'); ap.add_argument('--demo',action='store_true'); ap.add_argument('--routing',action='store_true',help='also verify Apache rewrite/ErrorDocument routing'); args=ap.parse_args()
    base=args.base.rstrip('/')+'/'
    failures=[]
    def check(path, expected, predicate, label, accept='application/json'):
        try:
            status,body,ctype=request(base+path.lstrip('/'),accept)
            ok=status==expected and predicate(body,ctype)
        except Exception as e:
            ok=False; body=str(e).encode(); status=0
        print(('[ OK ] ' if ok else '[FAIL] ')+f'{label} (HTTP {status})')
        if not ok: failures.append(label)
    check('api/system/status.php',200,lambda b,c: json.loads(b).get('ok') is True,'health endpoint')
    check('api/ui-config.php',200,lambda b,c: isinstance(json.loads(b),dict) and json.loads(b).get('ok') is True,'public UI config')
    check('privacy.html',200,lambda b,c: b'dist/privacy-context.' in b and b'.js' in b,'privacy page','text/html')
    # Protected diagnostics must not expose an inert dashboard to anonymous users.
    check('diagnostics/',401,lambda b,c: b'error-card' in b and b'401' in b,'diagnostics requires authentication','text/html')
    check('api/public-error.php?code=403',403,lambda b,c: b'error-card' in b and b'403' in b,'branded 403 renderer','text/html')
    check('api/public-error.php?code=404',404,lambda b,c: b'error-card' in b and b'404' in b,'branded 404 renderer','text/html')
    check('api/error.php?code=404',404,lambda b,c: json.loads(b).get('ok') is False,'API 404 renderer remains JSON','application/json')
    if args.routing:
        check('qa',403,lambda b,c: b'error-card' in b and b'403' in b,'Apache QA directory denial','text/html')
        check('__meteonexa_missing_page__',404,lambda b,c: b'error-card' in b and b'404' in b,'Apache browser 404 routing','text/html')
        check('api/__meteonexa_missing_api__',404,lambda b,c: json.loads(b).get('ok') is False,'Apache API 404 routing','application/json')
    check('?preview',200,lambda b,c: b'id="home-chart"' in b and b'js/asset-manifest.js' in b and b'dist/modules/esm/bootstrap.' in b,'preview application shell','text/html')
    if args.demo:
        check('api/demo/intelligence.php?lat=45.4642&lon=9.1900&location=Milano',200,lambda b,c: json.loads(b).get('ok') is True,'guest intelligence demo')
    if failures: print(f"HTTP smoke FAILED: {len(failures)}",file=sys.stderr); return 1
    print('HTTP smoke PASS'); return 0
if __name__=='__main__': raise SystemExit(main())
