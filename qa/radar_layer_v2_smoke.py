#!/usr/bin/env python3
from pathlib import Path
import sys

root = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
module = (root / 'modules/esm/domains/radar-layers.mjs').read_text(encoding='utf-8')
suite = (root / 'js/suite.js').read_text(encoding='utf-8')
bootstrap = (root / 'modules/esm/bootstrap.mjs').read_text(encoding='utf-8')
required = [
    "cloud: { variable: 'cloud_cover'",
    "wind_speed_10m,wind_direction_10m",
    "if (type === 'snow' && max <= 0)",
    "setLegend(type, 'ready', '0 cm')",
    "meteonexa-radar-v2-heat",
    "meteonexa-radar-v2-symbols",
    "dataset.radarLayerStatus",
    "presentationLayer = `forecast-${type}`",
]
errors = [needle for needle in required if needle not in module]
if "SERVICES.require('radarLayers')" not in suite:
    errors.append('suite delegation missing')
if "modules/esm/domains/radar-layers.mjs" not in bootstrap:
    errors.append('bootstrap registration missing')
if 'function gridCoordinates(size = 5' in suite or 'function mapLayerData(type)' in suite:
    errors.append('legacy generic radar layer renderer still present in suite.js')
if errors:
    print('Radar Layer V2: FAIL')
    for error in errors:
        print(' - ' + error)
    raise SystemExit(1)
print('Radar Layer V2: PASS')
