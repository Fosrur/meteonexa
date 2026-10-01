#!/usr/bin/env python3
from pathlib import Path
import sqlite3
import sys

root = Path(__file__).resolve().parents[1]
db = root / 'api/install/meteonexa-baseline.sqlite'
mysql = (root / 'api/install/mysql-schema.sql').read_text(encoding='utf-8')
migration = (root / 'api/database/migrations/0031_radar4_empirical_calibration.php').read_text(encoding='utf-8')

def check(condition, message):
    if not condition:
        raise SystemExit(f'FAIL: {message}')

con = sqlite3.connect(db)
try:
    version = con.execute("SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'").fetchone()
    check(version and version[0] == '35', 'current baseline schema version is 35 while retaining P3.3 columns')
    tables = {row[0] for row in con.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")}
    check(len(tables) == 53, f'baseline keeps 53 application tables, got {len(tables)}')
    event_cols = {row[1] for row in con.execute('PRAGMA table_info(radar4_event_predictions)')}
    eta_cols = {row[1] for row in con.execute('PRAGMA table_info(radar_eta_predictions)')}
    for column in ['area_key','distance_band','coverage_band','season','weather_regime','terrain_class','terrain_relief_m','terrain_gradient_pct','event_observed','calibrated_probability','probability_brier','calibration_context_json']:
        check(column in event_cols, f'event calibration column {column}')
    for column in ['area_key','distance_band','coverage_band','season','weather_regime','terrain_class']:
        check(column in eta_cols, f'ETA calibration column {column}')
    indexes = {row[1] for row in con.execute("PRAGMA index_list(radar4_event_predictions)")}
    check('idx_radar4_event_calibration' in indexes, 'event calibration index exists')
finally:
    con.close()

for marker in ["VALUES('schema_version','35'", 'calibrated_probability DOUBLE NULL', 'probability_brier DOUBLE NULL', 'idx_radar4_event_calibration', 'idx_radar_eta_calibration']:
    check(marker in mysql, f'MySQL schema marker {marker}')
for marker in ["'version' => 31", "'name' => 'radar4-empirical-calibration-context'", "meteonexa_write_schema_version($pdo, 31)"]:
    check(marker in migration, f'migration marker {marker}')

print('Radar4 P3.3 calibration schema smoke PASS')
