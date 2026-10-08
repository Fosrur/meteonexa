from pathlib import Path
import json
import shutil
import subprocess
import sys

source = Path(__file__).resolve().parent
target = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path.cwd().resolve()

if not (target / "package.json").is_file() or not (target / ".git").exists():
    raise SystemExit(f"Target MeteoNexa non valido: {target}")

payload = source / "payload"
if not payload.is_dir():
    raise SystemExit(f"Payload mancante: {payload}")

for item in payload.rglob("*"):
    if not item.is_file():
        continue
    relative = item.relative_to(payload)
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(item, destination)

package_path = target / "package.json"
package = json.loads(package_path.read_text(encoding="utf-8"))
scripts = package.setdefault("scripts", {})
scripts["checksums:update"] = "node tools/update-release-checksums.mjs"
scripts["checksums:verify"] = "node tools/verify-release-checksums.mjs"
package_path.write_text(json.dumps(package, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")

stale_payload = target / "payload"
if stale_payload.exists():
    shutil.rmtree(stale_payload)

self_path = Path(__file__).resolve()
for pattern in ("apply_meteonexa_actions_v*.py", "apply_roadmap_10_10*.py"):
    for candidate in target.glob(pattern):
        if candidate.resolve() == self_path:
            continue
        try:
            candidate.unlink()
        except OSError:
            pass

for command in (["node", "tools/update-release-checksums.mjs"], ["node", "tools/verify-release-checksums.mjs"]):
    subprocess.run(command, cwd=target, check=True)

print("Patch checksum CI v8 applicata.")
print("Esegui ora: npm run lint:eslint && npm run build:production && npm run checksums:update && npm run checksums:verify && npm run qa:full")
