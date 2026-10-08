from pathlib import Path
import shutil
import subprocess
import sys

if len(sys.argv) != 2:
    raise SystemExit('Uso: py apply_roadmap_10_10_final_v4_fullfix.py <cartella-meteonexa>')

package = Path(__file__).resolve().parent
payload = package / 'payload'
target = Path(sys.argv[1]).resolve()
if not (target / 'package.json').is_file() or not (target / 'modules/esm/core/service-registry.mjs').is_file():
    raise SystemExit(f'Cartella MeteoNexa non valida: {target}')

for source in payload.rglob('*'):
    if not source.is_file():
        continue
    relative = source.relative_to(payload)
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)

subprocess.run(['node', 'tools/update-release-checksums.mjs'], cwd=target, check=True)
checks = [
    ['node', 'qa/p2_core_esm_smoke.mjs'],
    [sys.executable, 'qa/p2_toolchain_smoke.py', '.'],
    [sys.executable, 'qa/p4_css_architecture_smoke.py', '.'],
    [sys.executable, 'qa/i18n_ai_home_alert_smoke.py'],
    [sys.executable, 'qa/mobile_bootstrap_smoke.py'],
    [sys.executable, 'qa/mobile_guest_alerts_private_smoke.py'],
]
for command in checks:
    subprocess.run(command, cwd=target, check=True)
print('MeteoNexa 10/10 final v4 full-fix applicato. Esegui: npm run qa:full')
