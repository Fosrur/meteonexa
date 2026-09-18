#!/usr/bin/env python3
"""Build immutable fingerprinted frontend assets for MeteoNexa.

Sources remain readable in the repository. Public HTML points at dist/<name>.<sha12>.*
and service-worker caching resolves logical names through asset-manifest.js.
"""
from __future__ import annotations
import hashlib, json, os, re, shutil, subprocess, sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
DIST=ROOT/'dist'
ESBUILD_PROD=ROOT/'.build/esbuild-production'
MANIFEST_JSON=ROOT/'asset-manifest.json'
MANIFEST_JS=ROOT/'asset-manifest.js'
ASSETS=[
    'styles.css','advanced.css','suite.css','intelligence.css','decision-timeline.css','watch-plan.css','copilot.css','light-theme.css','standalone.css',
    'boot-clock.js','i18n-runtime.js','config.js','security-runtime.js','custom-controls.js',
    'product-metrics.js','analytics.js','app.js','advanced.js','suite.js','weather-intelligence.js','privacy-context.js','page-i18n.js',
    'protected-page.js','offline.js','error.css','error.js',
    'modules/esm/bootstrap.mjs','modules/esm/core/service-registry.mjs','modules/esm/core/runtime-api.mjs','modules/esm/core/store.mjs','modules/esm/core/runtime-state.mjs','modules/esm/core/tooltips.mjs','modules/esm/core/weather-utils.mjs','modules/esm/core/i18n-preferences.mjs',
    'modules/esm/domains/visualization.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/suite-integrations.mjs','modules/esm/domains/locations.mjs','modules/esm/domains/navigation.mjs','modules/esm/domains/notifications.mjs','modules/esm/domains/weather.mjs','modules/esm/domains/radar.mjs','modules/esm/domains/alerts.mjs',
    'modules/esm/domains/auth.mjs','modules/esm/domains/auth-flow.mjs','modules/esm/domains/account.mjs','modules/esm/domains/radar-motion.mjs','modules/esm/domains/radar-controller.mjs','modules/esm/domains/privacy.mjs','modules/esm/domains/feedback.mjs','modules/esm/domains/ai.mjs',
    'modules/esm/features/route-weather.mjs','modules/esm/features/intelligence.mjs','modules/esm/features/decision-timeline.mjs','modules/esm/features/watch-plan.mjs','modules/esm/features/copilot.mjs',
    'install/installer.css','install/installer.js','diagnostics/diagnostics.css','diagnostics/diagnostics.js','qa/qa.css','qa/qa.js',
]
ROOT_HTML=['index.html','privacy.html','cookie-policy.html','offline.html']
# Semantic aliases from previous asset names. They are used only while
# normalizing an existing work tree; generated public manifests contain only
# the current semantic names below.
LOGICAL_ALIASES={
    'intelligence-decision.css':'decision-timeline.css',
    'intelligence-watch.css':'watch-plan.css',
    'modules/esm/features/intelligence-decision.mjs':'modules/esm/features/decision-timeline.mjs',
    'modules/esm/features/intelligence-watch.mjs':'modules/esm/features/watch-plan.mjs',
}

def digest(path:Path)->str:
    return hashlib.sha256(path.read_bytes()).hexdigest()[:12]

def load_previous()->dict[str,str]:
    try:
        data=json.loads(MANIFEST_JSON.read_text(encoding='utf-8'))
        return data if isinstance(data,dict) else {}
    except Exception:
        return {}

def reset_old_refs(previous:dict[str,str])->None:
    """Normalize public HTML back to logical asset names before each build.

    Do not rely only on the previous manifest. A partially rebuilt work tree can
    contain an HTML reference to an older content hash while asset-manifest.json
    already points at a newer one. That exact drift caused production to request
    missing JS files and receive the branded HTML 404 page (MIME text/html).
    """
    for name in ROOT_HTML:
        p=ROOT/name
        if not p.exists(): continue
        s=p.read_text(encoding='utf-8')
        for logical,target in previous.items():
            clean=str(target).removeprefix('./')
            s=s.replace(clean,logical).replace('./'+clean,logical)
        for old,new in LOGICAL_ALIASES.items():
            s=s.replace(old,new)
        for logical in ASSETS:
            if logical.startswith(('install/','diagnostics/','qa/')) or logical in ('error.css','error.js'):
                continue
            rel=Path(logical)
            parent='' if str(rel.parent)=='.' else re.escape(rel.parent.as_posix())+'/'
            stale=re.compile(r'(?:\./)?dist/'+parent+re.escape(rel.stem)+r'\.[a-f0-9]{12}'+re.escape(rel.suffix),re.I)
            s=stale.sub(logical,s)
        p.write_text(s,encoding='utf-8')

def reset_aux_refs(previous:dict[str,str])->None:
    """Restore auxiliary PHP surfaces to logical source names before rebuilding.

    This makes the fingerprint build idempotent: a second run must never leave
    installer/diagnostics/error surfaces pointing at deleted hashes.
    """
    p=ROOT/'install/index.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        for logical in ('install/installer.css','install/installer.js'):
            target=previous.get(logical)
            if target:
                clean=str(target).removeprefix('./')
                s=s.replace('../'+clean,Path(logical).name)
        p.write_text(s,encoding='utf-8')

    p=ROOT/'diagnostics/index.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        for logical in ('diagnostics/diagnostics.css','diagnostics/diagnostics.js'):
            target=previous.get(logical)
            if target:
                clean=str(target).removeprefix('./')
                s=s.replace('../'+clean,Path(logical).name)
        for logical in ('security-runtime.js','i18n-runtime.js'):
            target=previous.get(logical)
            if target:
                clean=str(target).removeprefix('./')
                s=s.replace('../'+clean,'../'+logical)
        p.write_text(s,encoding='utf-8')

    p=ROOT/'qa/index.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        for logical in ('qa/qa.css','qa/qa.js'):
            target=previous.get(logical)
            if target:
                clean=str(target).removeprefix('./')
                s=s.replace('../'+clean,Path(logical).name)
        for logical in ('security-runtime.js','i18n-runtime.js'):
            target=previous.get(logical)
            if target:
                clean=str(target).removeprefix('./')
                s=s.replace('../'+clean,'../'+logical)
        p.write_text(s,encoding='utf-8')

    p=ROOT/'api/error_page.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        for logical in ('error.css','error.js'):
            target=previous.get(logical)
            if target:
                clean=str(target).removeprefix('./')
                s=s.replace('/'+clean,'/'+logical)
            rel=Path(logical)
            s=re.sub(r'/dist/'+re.escape(rel.stem)+r'\.[a-f0-9]{12}'+re.escape(rel.suffix),'/'+logical,s,flags=re.I)
        p.write_text(s,encoding='utf-8')

def build()->dict[str,str]:
    if DIST.exists(): shutil.rmtree(DIST)
    manifest={}
    for logical in ASSETS:
        source=ROOT/logical
        built=ESBUILD_PROD/logical if logical.endswith('.mjs') else None
        require_esbuild=os.environ.get('METEONEXA_REQUIRE_ESBUILD','').strip()=='1'
        if logical.endswith('.mjs') and built is not None and built.is_file():
            src=built
        elif logical.endswith('.mjs') and require_esbuild:
            raise SystemExit(f'missing esbuild production asset: {logical}; run npm run build:esm')
        else:
            src=source
        if not src.is_file(): raise SystemExit(f'missing asset: {logical}')
        rel=Path(logical)
        out_rel=Path('dist')/rel.parent/f'{rel.stem}.{digest(src)}{rel.suffix}'
        out=ROOT/out_rel
        out.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(src,out)
        manifest[logical]='./'+out_rel.as_posix()
    return manifest

def rewrite_html(manifest:dict[str,str])->None:
    for name in ROOT_HTML:
        p=ROOT/name
        if not p.exists(): continue
        s=p.read_text(encoding='utf-8')
        for logical,target in manifest.items():
            # Root public HTML only references root/modules logical paths; installer/diagnostics are PHP surfaces.
            if logical.startswith(('install/','diagnostics/','qa/')) or logical in ('error.css','error.js'): continue
            target=target.removeprefix('./')
            s=re.sub(r'(?P<a>\b(?:src|href)=["\'])'+re.escape(logical)+r'(?:\?v=[^"\']+)?(?P<b>["\'])',lambda m:m.group('a')+target+m.group('b'),s)
        p.write_text(s,encoding='utf-8')

def rewrite_aux(manifest:dict[str,str])->None:
    # Installer is one directory below root.
    p=ROOT/'install/index.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        for logical in ('install/installer.css','install/installer.js'):
            target='../'+manifest[logical].removeprefix('./')
            base=Path(logical).name
            s=re.sub(re.escape(base)+r'(?:\?v=[^"\']+)?',target,s)
        p.write_text(s,encoding='utf-8')
    # Diagnostics is one directory below root; security/i18n are root assets.
    p=ROOT/'diagnostics/index.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        replacements={
            'diagnostics.css':'../'+manifest['diagnostics/diagnostics.css'].removeprefix('./'),
            'diagnostics.js':'../'+manifest['diagnostics/diagnostics.js'].removeprefix('./'),
            '../security-runtime.js':'../'+manifest['security-runtime.js'].removeprefix('./'),
            '../i18n-runtime.js':'../'+manifest['i18n-runtime.js'].removeprefix('./'),
        }
        for source,target in replacements.items():
            s=re.sub(re.escape(source)+r'(?:\?v=[^"\']+)?',target,s)
        p.write_text(s,encoding='utf-8')
    # QA is one directory below root and shares the same immutable runtime assets.
    p=ROOT/'qa/index.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        replacements={
            'qa.css':'../'+manifest['qa/qa.css'].removeprefix('./'),
            'qa.js':'../'+manifest['qa/qa.js'].removeprefix('./'),
            '../security-runtime.js':'../'+manifest['security-runtime.js'].removeprefix('./'),
            '../i18n-runtime.js':'../'+manifest['i18n-runtime.js'].removeprefix('./'),
        }
        for source,target in replacements.items():
            s=re.sub(re.escape(source)+r'(?:\?v=[^"\']+)?',target,s)
        p.write_text(s,encoding='utf-8')
    # Error page is rendered from /api but emitted at site root using absolute paths.
    p=ROOT/'api/error_page.php'
    if p.exists():
        s=p.read_text(encoding='utf-8')
        s=re.sub(r'/error\.css(?:\?v=[^"\']+)?','/'+manifest['error.css'].removeprefix('./'),s)
        s=re.sub(r'/error\.js(?:\?v=[^"\']+)?','/'+manifest['error.js'].removeprefix('./'),s)
        p.write_text(s,encoding='utf-8')

def main()->None:
    subprocess.run([sys.executable, str(ROOT/'tools/build_css.py')], check=True)
    previous=load_previous()
    reset_old_refs(previous)
    reset_aux_refs(previous)
    manifest=build()
    rewrite_html(manifest)
    rewrite_aux(manifest)
    MANIFEST_JSON.write_text(json.dumps(manifest,indent=2,sort_keys=True)+'\n',encoding='utf-8')
    sw={k:('./'+v.removeprefix('./')) for k,v in manifest.items() if not k.startswith(('install/','diagnostics/','qa/')) and k not in ('error.css','error.js')}
    MANIFEST_JS.write_text("'use strict';\nself.METEONEXA_ASSET_MANIFEST="+json.dumps(sw,separators=(',',':'),sort_keys=True)+";\n",encoding='utf-8')
    print(f'fingerprinted {len(manifest)} assets')

if __name__=='__main__': main()
