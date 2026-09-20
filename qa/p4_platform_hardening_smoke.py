#!/usr/bin/env python3
from pathlib import Path
import re, sys

root = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
read = lambda rel: (root / rel).read_text(encoding='utf-8')

dockerfile = read('Dockerfile')
compose = read('docker-compose.yml')
installer = read('install/index.php')
installer_error = read('install/InstallerException.php')
workflow = read('.github/workflows/meteonexa-tests.yml')
composer = read('composer.json')
semgrep = read('.semgrep.yml')

checks = {
    'production Dockerfile uses an explicit runtime allowlist': 'COPY . /var/www/html' not in dockerfile and ('/var/www/html/api' in dockerfile and '/var/www/html/dist' in dockerfile and 'COPY --from=frontend-build' in dockerfile),
    'production image drops root': 'USER www-data:www-data' in dockerfile,
    'web container is read-only and non-root': re.search(r'web:\n(?:(?:    |      ).*\n)*?    user: "33:33"', compose) is not None and 'read_only: true' in compose,
    'containers enable no-new-privileges': compose.count('no-new-privileges:true') >= 2,
    'containers drop Linux capabilities': compose.count('cap_drop:') >= 2 and compose.count('- ALL') >= 2,
    'runtime state uses the documented host bind mount': compose.count('${METEONEXA_RUNTIME_DIR:-./runtime}:/var/lib/meteonexa') >= 2 and 'meteonexa_runtime:' not in compose,
    'installer has a typed exception contract': 'final class MeteoNexaInstallerException' in installer_error and 'translationKey' in installer_error,
    'installer no longer maps public errors by message text': 'str_contains($message' not in installer and 'instanceof MeteoNexaInstallerException' in installer,
    'PHPStan is configured': 'phpstan/phpstan' in composer and (root / 'phpstan.neon').is_file(),
    'PHP CS Fixer is configured': 'friendsofphp/php-cs-fixer' in composer and (root / '.php-cs-fixer.dist.php').is_file(),
    'Semgrep local rules are configured': 'meteonexa-php-eval' in semgrep and 'semgrep/semgrep:1.169.0' in workflow,
    'Trivy scans source and production image': workflow.count('aquasecurity/trivy-action@v0.36.0') >= 2 and 'image-ref: meteonexa:ci' in workflow,
    'CI executes broader real-MySQL integration': 'qa/mysql_full_integration.php' in workflow and (root / 'qa/mysql_full_integration.php').is_file(),
    'hardened image is built in CI': 'docker build -t meteonexa:ci .' in workflow,
}

failed = [name for name, ok in checks.items() if not ok]
for name, ok in checks.items():
    print(f"[{'OK' if ok else 'FAIL'}] {name}")
if failed:
    print('P4 platform hardening smoke FAILED:', ', '.join(failed), file=sys.stderr)
    raise SystemExit(1)
print('P4 platform hardening smoke PASS')
