#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]


def fail(message: str) -> None:
    raise SystemExit(f"P4 backend maintainability: FAIL - {message}")


migration_dir = ROOT / "api" / "database" / "migrations"
expected_versions = list(range(16, 29))
revision_files = sorted(migration_dir.glob("[0-9][0-9][0-9][0-9]_*.php"))
versions = [int(path.name[:4]) for path in revision_files]
if versions != expected_versions:
    fail(f"expected migration files 0016..0027, got {versions}")

legacy_sqlite = migration_dir / "legacy_sqlite_upgrade.php"
if not legacy_sqlite.is_file():
    fail("legacy SQLite compatibility upgrader is not isolated")

runner = (ROOT / "api" / "database" / "migrations.php").read_text(encoding="utf-8")
for needle in [
    "function meteonexa_database_migrations()",
    "DB_MIGRATION_SEQUENCE_INVALID",
    "DB_MIGRATION_CURRENT_VERSION_MISMATCH",
    "meteonexa_run_explicit_migrations",
    "legacy_sqlite_upgrade.php",
]:
    if needle not in runner:
        fail(f"migration registry contract missing: {needle}")

php_probe = r'''
require $argv[1] . '/api/database/driver.php';
require $argv[1] . '/api/database/schema.php';
require $argv[1] . '/api/database/migrations.php';
echo json_encode([
    'current' => meteonexa_current_schema_version(),
    'manifest' => meteonexa_migration_manifest(),
    'count' => count(meteonexa_database_migrations()),
], JSON_UNESCAPED_SLASHES);
'''
probe = subprocess.run(
    ["php", "-r", php_probe, str(ROOT)],
    check=True,
    capture_output=True,
    text=True,
)
try:
    contract = json.loads(probe.stdout)
except json.JSONDecodeError as exc:
    fail(f"migration registry probe returned invalid JSON: {exc}")
if contract.get("current") != 28 or contract.get("count") != 13:
    fail(f"unexpected migration registry contract: {contract}")
manifest_versions = [int(value) for value in contract.get("manifest", {}).keys()]
if manifest_versions != expected_versions:
    fail(f"runtime manifest is not contiguous: {manifest_versions}")

# Prevent reintroduction of the old compressed-PHP pattern: sizeable endpoint
# files must have enough physical structure to be reviewable in diffs/debuggers.
dense_php: list[str] = []
for path in (ROOT / "api").rglob("*.php"):
    source = path.read_text(encoding="utf-8", errors="replace")
    lines = source.splitlines()
    if len(source.encode("utf-8")) > 4_000 and len(lines) <= 15:
        dense_php.append(str(path.relative_to(ROOT)))
    if any('declare(strict_types=1);require' in line for line in lines[:4]):
        dense_php.append(f"{path.relative_to(ROOT)} (compressed bootstrap)")
if dense_php:
    fail("compressed PHP reintroduced: " + ", ".join(sorted(set(dense_php))))

fixer = (ROOT / ".php-cs-fixer.dist.php").read_text(encoding="utf-8")
if "__DIR__ . '/api'" not in fixer:
    fail("PHP-CS-Fixer must cover the complete api/ tree")

# Every revision descriptor must declare its version/name/driver contract.
for path, version in zip(revision_files, expected_versions, strict=True):
    source = path.read_text(encoding="utf-8")
    if re.search(r"['\"]version['\"]\s*=>\s*" + str(version) + r"\b", source) is None:
        fail(f"{path.name} does not declare version {version}")
    for field in ["name", "drivers", "up"]:
        if re.search(r"['\"]" + field + r"['\"]\s*=>", source) is None:
            fail(f"{path.name} missing descriptor field {field}")
    if "static function" not in source:
        fail(f"{path.name} does not expose a static up migration")

print(
    "P4 backend maintainability: PASS "
    f"({len(revision_files)} revision files, schema {contract['current']}, compressed endpoint gate clean)"
)

status=(ROOT/'api/system/status.php').read_text(encoding='utf-8')
assert "meteonexa_current_schema_version()" in status, 'system status must validate the current schema dynamically'
assert "DATABASE_UNAVAILABLE" in status, 'system status failure must expose a stable machine code'
