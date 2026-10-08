#!/usr/bin/env python3
"""Restore quality-history.jsonl from the latest prior GitHub Actions artifact.
Fail-soft by design: absence of prior history must never block the current run.
"""
from __future__ import annotations
import argparse, io, json, os, urllib.request, zipfile
from pathlib import Path

def main()->int:
    ap=argparse.ArgumentParser();ap.add_argument('--destination',default='quality-reports/quality-history.jsonl');ap.add_argument('--prefix',default='continuous-quality-');ns=ap.parse_args()
    token=os.getenv('GITHUB_TOKEN','');repo=os.getenv('GITHUB_REPOSITORY','')
    if not token or not repo:
        print('Quality history restore: SKIP (GitHub context unavailable)');return 0
    req=urllib.request.Request(f'https://api.github.com/repos/{repo}/actions/artifacts?per_page=100',headers={'Authorization':f'Bearer {token}','Accept':'application/vnd.github+json','X-GitHub-Api-Version':'2022-11-28','User-Agent':'MeteoNexa-quality-history'})
    try:
        data=json.load(urllib.request.urlopen(req,timeout=15));arts=[a for a in data.get('artifacts',[]) if str(a.get('name','')).startswith(ns.prefix) and not a.get('expired')]
        if not arts:print('Quality history restore: SKIP (no prior artifact)');return 0
        arts.sort(key=lambda a:str(a.get('created_at','')),reverse=True);url=arts[0]['archive_download_url']
        req=urllib.request.Request(url,headers={'Authorization':f'Bearer {token}','Accept':'application/vnd.github+json','User-Agent':'MeteoNexa-quality-history'})
        payload=urllib.request.urlopen(req,timeout=30).read();z=zipfile.ZipFile(io.BytesIO(payload));names=[n for n in z.namelist() if n.endswith('quality-history.jsonl')]
        if not names:print('Quality history restore: SKIP (artifact has no history)');return 0
        dest=Path(ns.destination);dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(z.read(names[0]));print(f'Quality history restore: PASS ({arts[0]["name"]}, {len(dest.read_text(encoding='utf-8').splitlines())} records)');return 0
    except Exception as e:
        print(f'Quality history restore: SKIP ({type(e).__name__})');return 0
if __name__=='__main__':raise SystemExit(main())
