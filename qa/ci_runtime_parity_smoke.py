from __future__ import annotations
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

def fail(message: str, log: str = "") -> None:
    print(f"CI runtime parity: FAIL - {message}")
    if log.strip():
        print(log.rstrip())
    raise SystemExit(1)

def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
        probe.bind(("127.0.0.1", 0))
        return int(probe.getsockname()[1])

def main() -> int:
    extension_probe = subprocess.run(["php", "-r", '$required=["pdo_sqlite","sqlite3"]; foreach($required as $extension){if(!extension_loaded($extension)){fwrite(STDERR,"Missing PHP extension: $extension\n"); exit(1);}}'], cwd=ROOT, capture_output=True, text=True)
    if extension_probe.returncode != 0:
        print("CI runtime parity: SKIP (PHP SQLite extensions unavailable locally)")
        return 0
    port = free_port()
    with tempfile.TemporaryDirectory(prefix="meteonexa-ci-runtime-") as temporary:
        runtime = Path(temporary) / "runtime"
        runtime.mkdir(parents=True, exist_ok=True)
        env = os.environ.copy()
        env["METEONEXA_STORAGE_PATH"] = str(runtime)
        env["METEONEXA_MAINTENANCE_FLAG"] = str(runtime / "maintenance.flag")
        process = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", ".", "qa/php-browser-router.php"], cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        try:
            base = f"http://127.0.0.1:{port}/"
            ready = False
            for _ in range(40):
                if process.poll() is not None:
                    break
                try:
                    with urllib.request.urlopen(base + "?preview", timeout=1) as response:
                        if response.status == 200:
                            ready = True
                            break
                except Exception:
                    time.sleep(0.1)
            if not ready:
                output = process.stdout.read() if process.stdout else ""
                fail("PHP test server did not become ready", output)
            smoke = subprocess.run([sys.executable, "qa/http_smoke.py", base], cwd=ROOT, capture_output=True, text=True)
            if smoke.returncode != 0:
                process.terminate()
                try:
                    process.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    process.kill()
                server = process.stdout.read() if process.stdout else ""
                fail("HTTP smoke failed", smoke.stdout + smoke.stderr + "\n" + server)
            print(smoke.stdout.rstrip())
            print("CI runtime parity: PASS")
            return 0
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    process.kill()

if __name__ == "__main__":
    raise SystemExit(main())
