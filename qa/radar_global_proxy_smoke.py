#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
frames=(root/'api/radar/frames.php').read_text(encoding='utf-8')
tile=(root/'api/radar/tile.php').read_text(encoding='utf-8')
controller=(root/'modules/esm/domains/radar-controller.mjs').read_text(encoding='utf-8')
motion=(root/'modules/esm/domains/radar-motion.mjs').read_text(encoding='utf-8')
config=(root/'api/config.php').read_text(encoding='utf-8')
checks={
 'frame signature binds provider host': "radar-frame-v2" in frames and "$device . '|' . $host . '|' . $path" in frames,
 'frames expose signed tile host': "$frame['tileHost'] = $host" in frames,
 'browser vector tiles send signed host': "host: frame.tileHost || frame.host || ''" in controller,
 'motion tiles send signed host': "host: frame.tileHost || frame.host || ''" in motion,
 'tile endpoint verifies v2 host-bound signature': "radar-frame-v2" in tile and "$device . '|' . $host . '|' . $path" in tile,
 'signed v2 tiles do not require metadata lookup': "if (!$validFrameSignature)" in tile and "Compatibility path only for old tabs" in tile,
 'provider host remains allowlisted': 'meteonexa_validate_remote_url($host' in tile and 'meteonexa_radar_allowed_hosts($config)' in tile,
 'radar palette is high contrast': '$color = 10;' in frames and 'tileColor ?? 10' in controller,
 'global tile budget is configurable': 'METEONEXA_RADAR_TILE_GLOBAL_HOUR' in config and '30000' in config,
 'immutable radar tile cache is extended': 'max-age=1800' in tile and '21600' in tile,
 'provider failure is observable, not transparent 200': 'http_response_code(502)' in tile and 'provider-error' in tile and 'image/svg+xml' not in tile,
 'stale radar tile is preferred before provider error': "$emitPng($staleTile, 'STALE')" in tile and '21600' in tile,
}
failed=[]
for name,ok in checks.items():
 print(('PASS' if ok else 'FAIL')+': '+name)
 if not ok: failed.append(name)
if failed:
 print('Radar global proxy smoke FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Radar global proxy smoke PASS')
