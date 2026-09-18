#!/usr/bin/env python3
"""Residual migration from any remaining hash-like i18n keys to readable semantic i18n keys.

The semantic name is derived from the owning runtime domain, nearest function/DOM
context and the English copy. The generated old->new map is retained so existing
installations can migrate customized translations without losing operator edits.
"""
from __future__ import annotations
import collections, hashlib, json, os, re, sqlite3, unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / 'api/install/meteonexa-baseline.sqlite'
SEED = ROOT / 'api/install/translations.json'
MAP_PATH = ROOT / 'api/install/i18n-key-map-20.1-residual.json'
OPAQUE = re.compile(r'^(?:[a-z0-9_]+\.)*[0-9a-f]{12,}$')
TEXT_EXTENSIONS = {'.js', '.mjs', '.html', '.php', '.py', '.md', '.json'}
CATALOGS = {SEED, *(ROOT / 'assets/i18n').glob('*.json')}
EXCLUDED_PARTS = {'.git', 'dist', '.build', 'node_modules'}


def slug(value: str, limit: int = 8) -> str:
    value = unicodedata.normalize('NFKD', value).encode('ascii', 'ignore').decode().lower()
    value = re.sub(r'\{[^}]+\}', ' value ', value).replace('&', ' and ')
    words = re.findall(r'[a-z0-9]+', value)
    stop = {'the','a','an','of','to','for','and','or','in','on','with','is','are','was','were','be','this','that','it','your','you'}
    useful = [word for word in words if word not in stop] or words
    return '_'.join(useful[:limit]) or 'text'


def domain_for(path: Path) -> str:
    rel = path.relative_to(ROOT).as_posix()
    stem = path.stem.replace('-', '_')
    if rel.startswith('modules/esm/domains/'):
        aliases = {
            'model_intelligence':'intelligence', 'forecast_history':'history',
            'radar_controller':'radar', 'radar_motion':'radar', 'auth_flow':'auth',
            'suite_support':'suite', 'suite_integrations':'suite', 'suite_assistant':'suite',
            'app_utilities':'app', 'device_sessions':'sessions', 'app_lifecycle':'lifecycle',
        }
        return aliases.get(stem, stem)
    if rel.startswith('modules/esm/features/'):
        return stem
    if rel.startswith('modules/esm/core/'):
        return {'weather_utils':'weather', 'i18n_preferences':'preferences'}.get(stem, stem)
    return {
        'app':'app', 'suite':'suite', 'advanced':'advanced', 'weather-intelligence':'weather',
        'index':'home', 'privacy':'privacy', 'offline':'offline', 'cookie-policy':'cookie',
        'privacy-context':'privacy',
    }.get(path.stem, stem)


def context_for(path: Path, text: str, pos: int) -> str:
    before = text[:pos]
    if path.suffix in {'.js', '.mjs'}:
        for line in reversed(before.splitlines()[-180:]):
            match = re.search(r'\bfunction\s+([A-Za-z_$][\w$]*)\s*\(', line)
            if match:
                return slug(match.group(1))
            match = re.search(r'\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>', line)
            if match:
                return slug(match.group(1))
            match = re.match(r'\s*(?:async\s+)?([A-Za-z_$][\w$]*)\s*\([^;]*\)\s*\{\s*$', line)
            if match and match.group(1) not in {'if','for','while','switch','catch'}:
                return slug(match.group(1))
    if path.suffix in {'.html', '.php'}:
        chunk = before[-2500:]
        ids = re.findall(r'\bid=["\']([^"\']+)', chunk)
        if ids:
            return slug(ids[-1])
        functions = re.findall(r'\bfunction\s+([A-Za-z_$][\w$]*)\s*\(', chunk)
        if functions:
            return slug(functions[-1])
    return ''


def source_priority(path: Path) -> tuple[int, str]:
    rel = path.relative_to(ROOT).as_posix()
    if rel.startswith('modules/esm/domains/'):
        return (0, rel)
    if rel.startswith('modules/esm/features/'):
        return (1, rel)
    if rel.startswith('modules/esm/core/'):
        return (2, rel)
    if path.name in {'app.js','suite.js','advanced.js','weather-intelligence.js','product-metrics.js','custom-controls.js'}:
        return (3, rel)
    if path.suffix == '.html':
        return (4, rel)
    return (5, rel)


def runtime_sources() -> list[tuple[Path, str]]:
    result = []
    for path in ROOT.rglob('*'):
        if not path.is_file() or path.suffix.lower() not in TEXT_EXTENSIONS:
            continue
        if any(part in EXCLUDED_PARTS for part in path.relative_to(ROOT).parts):
            continue
        if path in CATALOGS or path == MAP_PATH:
            continue
        if path.relative_to(ROOT).as_posix().startswith('api/install/') and path.suffix == '.json':
            continue
        try:
            result.append((path, path.read_text(encoding='utf-8')))
        except UnicodeDecodeError:
            pass
    result.sort(key=lambda item: source_priority(item[0]))
    return result


def build_mapping() -> dict[str, str]:
    with sqlite3.connect(DB) as connection:
        english = dict(connection.execute("SELECT text_key, translation FROM translations WHERE locale='en'"))
    opaque = sorted(key for key in english if OPAQUE.fullmatch(key))
    if not opaque:
        return {}
    sources = runtime_sources()
    used = set(english) - set(opaque)
    mapping: dict[str, str] = {}
    for old in opaque:
        owner = None
        for path, text in sources:
            pos = text.find(old)
            if pos >= 0:
                owner = (path, text, pos)
                break
        if owner:
            path, text, pos = owner
            domain = domain_for(path)
            context = context_for(path, text, pos)
        else:
            domain, context = 'message', ''
        copy_slug = slug(english[old])
        parts = [domain]
        if context and (len(copy_slug.split('_')) <= 3 or domain in {'app','suite','advanced','home'}):
            parts.append(context)
        parts.append(copy_slug)
        base = '.'.join(filter(None, parts))
        if len(base) > 120:
            head = '.'.join(parts[:-1])
            base = head + '.' + copy_slug[:max(24, 120-len(head)-1)].rstrip('_')
        candidate = base
        variant = 2
        while candidate in used:
            candidate = f'{base}.variant_{variant}'
            variant += 1
        used.add(candidate)
        mapping[old] = candidate
    return mapping


def replace_source_references(mapping: dict[str, str]) -> None:
    for path in ROOT.rglob('*'):
        if not path.is_file() or path.suffix.lower() not in TEXT_EXTENSIONS:
            continue
        rel = path.relative_to(ROOT)
        if any(part in EXCLUDED_PARTS for part in rel.parts):
            continue
        if path in CATALOGS or path == MAP_PATH:
            continue
        try:
            text = path.read_text(encoding='utf-8')
        except UnicodeDecodeError:
            continue
        updated = text
        for old, new in mapping.items():
            if old in updated:
                updated = updated.replace(old, new)
        if updated != text:
            path.write_text(updated, encoding='utf-8')


def rewrite_catalog(path: Path, mapping: dict[str, str]) -> None:
    data = json.loads(path.read_text(encoding='utf-8'))
    translations = data.get('translations')
    if not isinstance(translations, dict):
        raise SystemExit(f'invalid static catalog: {path}')
    rewritten = {mapping.get(key, key): value for key, value in translations.items()}
    if len(rewritten) != len(translations):
        raise SystemExit(f'i18n key collision in {path}')
    data['translations'] = dict(sorted(rewritten.items()))
    digest = hashlib.sha256(json.dumps(data['translations'], ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    data['translationsUpdatedAt'] = 'static:' + digest
    path.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')


def rewrite_seed(mapping: dict[str, str]) -> None:
    seed = json.loads(SEED.read_text(encoding='utf-8'))
    rows = seed.get('rows')
    if not isinstance(rows, list):
        raise SystemExit('invalid translations seed')
    for row in rows:
        key = row.get('text_key')
        if key in mapping:
            row['text_key'] = mapping[key]
    seed['version'] = '20.1-semantic-i18n-v2'
    SEED.write_text(json.dumps(seed, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def rewrite_baseline(mapping: dict[str, str]) -> None:
    with sqlite3.connect(DB) as connection:
        connection.execute('BEGIN IMMEDIATE')
        for old, new in mapping.items():
            connection.execute('UPDATE translations SET text_key=? WHERE text_key=?', (new, old))
        connection.execute("UPDATE app_metadata SET meta_value=?, updated_at=datetime('now') WHERE meta_key='translation_seed_version'", ('20.1-semantic-i18n-v2',))
        connection.commit()


def main() -> None:
    mapping = build_mapping()
    if not mapping:
        print('Residual semantic i18n migration: no hash-like keys remain.')
        return
    MAP_PATH.write_text(json.dumps({'version':'20.1-semantic-i18n-v2','count':len(mapping),'mapping':mapping}, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    replace_source_references(mapping)
    rewrite_seed(mapping)
    for path in sorted((ROOT/'assets/i18n').glob('*.json')):
        rewrite_catalog(path, mapping)
    rewrite_baseline(mapping)
    print(f'Residual semantic i18n migration: {len(mapping)} keys migrated.')

if __name__ == '__main__':
    main()
