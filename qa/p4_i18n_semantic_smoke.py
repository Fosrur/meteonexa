#!/usr/bin/env python3
from __future__ import annotations
import json, re, sqlite3, sys
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
HASH_LIKE = re.compile(r'^(?:[a-z0-9_]+\.)*[0-9a-f]{12,}$')

def fail(message: str) -> None:
    raise SystemExit(f'P4 semantic i18n: FAIL - {message}')

def opaque(keys):
    return [key for key in keys if HASH_LIKE.fullmatch(str(key))]

seed = json.loads((ROOT/'api/install/translations.json').read_text(encoding='utf-8'))
rows = seed.get('rows', [])
keys = sorted({str(row.get('text_key','')) for row in rows})
locales = sorted({str(row.get('locale','')) for row in rows})
if opaque(keys): fail(f'hash-like keys remain in packaged translations: {opaque(keys)[:5]}')
if len(keys) != 4656 or locales != ['de','en','es','fr','it']:
    fail(f'unexpected translation catalog shape: {len(keys)} keys / {locales}')
if len(rows) != 23280: fail(f'unexpected translation row count: {len(rows)}')
if seed.get('version') != '20.1-semantic-i18n-v2': fail(f'unexpected seed version: {seed.get("version")}')

primary = json.loads((ROOT/'api/install/i18n-key-map-20.1.json').read_text(encoding='utf-8'))
residual = json.loads((ROOT/'api/install/i18n-key-map-20.1-residual.json').read_text(encoding='utf-8'))
if primary.get('count') != 1221 or len(primary.get('mapping', {})) != 1221: fail('primary semantic migration map must contain 1221 keys')
if residual.get('count') != 96 or len(residual.get('mapping', {})) != 96: fail('residual semantic migration map must contain 96 keys')
if any(HASH_LIKE.fullmatch(new) for new in [*primary['mapping'].values(), *residual['mapping'].values()]): fail('semantic map targets hash-like keys')

with sqlite3.connect(ROOT/'api/install/meteonexa-baseline.sqlite') as connection:
    db_keys = [row[0] for row in connection.execute('SELECT DISTINCT text_key FROM translations')]
    metadata = dict(connection.execute("SELECT meta_key,meta_value FROM app_metadata WHERE meta_key IN ('schema_version','translation_seed_version')"))
if opaque(db_keys): fail('hash-like keys remain in baseline SQLite')
if metadata != {'schema_version':'28','translation_seed_version':'20.1-semantic-i18n-v2'}: fail(f'unexpected baseline metadata: {metadata}')

for locale in locales:
    catalog = json.loads((ROOT/f'assets/i18n/{locale}.json').read_text(encoding='utf-8'))
    static_keys = list(catalog.get('translations', {}).keys())
    if len(static_keys) != 4656 or opaque(static_keys): fail(f'hash-like/missing keys in static {locale} catalog')

migration = (ROOT/'api/database/migrations/0028_semantic_i18n_residual.php').read_text(encoding='utf-8')
for needle in ['semantic-i18n-residual','i18n-key-map-20.1-residual.json','I18N_SEMANTIC_RESIDUAL_KEY_MAP_INVALID','meteonexa_write_schema_version($pdo, 28)']:
    if needle not in migration: fail(f'migration 0028 contract missing: {needle}')

legacy_keys = set(primary['mapping']) | set(residual['mapping'])
for path in ROOT.rglob('*'):
    if not path.is_file() or path.suffix.lower() not in {'.js','.mjs','.html','.php','.py','.md','.json'}: continue
    rel = path.relative_to(ROOT)
    if any(part in {'.git','dist','.build','node_modules'} for part in rel.parts): continue
    if rel.as_posix() in {'api/install/i18n-key-map-20.1.json','api/install/i18n-key-map-20.1-residual.json'}: continue
    text = path.read_text(encoding='utf-8', errors='ignore')
    remaining = [key for key in legacy_keys if key in text]
    if remaining: fail(f'legacy i18n reference remains in {rel}: {remaining[:3]}')

print('P4 semantic i18n: PASS (4656 semantic keys, 1317 legacy/hash keys migrated, schema 28)')
