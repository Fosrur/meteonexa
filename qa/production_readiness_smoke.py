#!/usr/bin/env python3
from pathlib import Path
import json, re, subprocess, sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
read = lambda rel: (ROOT / rel).read_text(encoding='utf-8')
compose = read('docker-compose.yml')
dockerfile = read('Dockerfile')
workflow = read('.github/workflows/meteonexa-tests.yml')
env = read('.env.example')
md = read('readme.md')

root_lock = json.loads(read('package-lock.json'))
qa_lock = json.loads(read('qa/package-lock.json'))
composer_lock = json.loads(read('composer.lock'))
root_pkgs = root_lock.get('packages', {})
qa_pkgs = qa_lock.get('packages', {})


def source_markdown_files() -> list[str]:
    """Return project Markdown without trusting a parent repository.

    A source/release bundle may be unpacked below an unrelated Git checkout. Git
    is authoritative only when ``git rev-parse --show-toplevel`` resolves to
    ROOT exactly. Otherwise scan the package itself. Dependency documentation is
    excluded in both modes.
    """
    try:
        top = subprocess.run(
            ['git', '-C', str(ROOT), 'rev-parse', '--show-toplevel'],
            check=True,
            text=True,
            capture_output=True,
        )
        if Path(top.stdout.strip()).resolve() == ROOT:
            result = subprocess.run(
                ['git', '-C', str(ROOT), 'ls-files', '--', '*.md'],
                check=True,
                text=True,
                capture_output=True,
            )
            return sorted(line.strip() for line in result.stdout.splitlines() if line.strip())
    except (OSError, subprocess.CalledProcessError, ValueError):
        pass

    ignored = {'.git', 'node_modules', 'vendor', '.cache', '.build'}
    files = []
    for path in ROOT.rglob('*.md'):
        relative = path.relative_to(ROOT)
        if any(part in ignored for part in relative.parts):
            continue
        files.append(relative.as_posix())
    return sorted(files)


project_markdown = source_markdown_files()
REPORT_MARKDOWN_PREFIX = 'docs/reports/'
authoritative_markdown = [
    path for path in project_markdown
    if not path.startswith(REPORT_MARKDOWN_PREFIX)
]
report_markdown = [
    path for path in project_markdown
    if path.startswith(REPORT_MARKDOWN_PREFIX)
]

current_contract_match = re.search(
    r'<!-- METEONEXA_CURRENT_CONTRACT_START -->(.*?)<!-- METEONEXA_CURRENT_CONTRACT_END -->',
    md,
    re.S,
)
current_contract = current_contract_match.group(1) if current_contract_match else ''

# Browser CI may be expressed either as one command installing both engines or
# as a matrix that runs one isolated job per engine. Both contracts provide the
# required Chromium + Firefox coverage; the split form is preferred because a
# slow/failing browser no longer serially blocks the other one.
browser_ci_monolithic = (
    'chromium firefox' in workflow
    and 'npm run test:e2e' in workflow
)
browser_ci_matrix = (
    bool(re.search(r'browser:\s*\[\s*chromium\s*,\s*firefox\s*\]', workflow))
    and 'matrix.browser' in workflow
    and 'npx playwright install --with-deps "${{ matrix.browser }}"' in workflow
    and 'npm run test:e2e -- --project="${{ matrix.browser }}"' in workflow
)

checks = {
    'single authoritative Markdown source of truth': authoritative_markdown == ['readme.md'],
    'report Markdown is isolated as non-authoritative evidence': all(path.startswith(REPORT_MARKDOWN_PREFIX) for path in report_markdown),
    'README has an explicit current-contract block': bool(current_contract),
    'schema 29 is the current contract': ('schema **29**' in current_contract or 'schema 29' in current_contract) and not re.search(r'schema\s+(?:\*\*)?(?:26|27|28)(?:\*\*)?', current_contract, re.I),
    'runtime bind mount is explicit and staging-parameterized': compose.count('${METEONEXA_RUNTIME_DIR:-./runtime}:/var/lib/meteonexa') >= 2,
    'staging can isolate all fixed container names': all(x in compose for x in ['METEONEXA_DB_CONTAINER_NAME', 'METEONEXA_WEB_CONTAINER_NAME', 'METEONEXA_WORKER_CONTAINER_NAME']),
    'legal controller fields reach web and worker': all(x in compose for x in ['METEONEXA_LEGAL_CONTROLLER_NAME', 'METEONEXA_LEGAL_CONTROLLER_ADDRESS', 'METEONEXA_DPO_STATUS', 'METEONEXA_DPO_EMAIL', 'METEONEXA_LEGAL_SITE_URL']),
    'worker/push secrets reach web and worker': all(x in compose for x in ['METEONEXA_PIPELINE_CRON_SECRET','METEONEXA_PUSH_CRON_SECRET','METEONEXA_VAPID_SUBJECT']),
    'traffic analytics are removed from runtime/deployment': 'METEONEXA_PLAUSIBLE_' not in compose and not (ROOT/'analytics.js').exists() and not (ROOT/'api/analytics/config.php').exists(),
    'backup and destructive restore are separate explicit scripts': (ROOT/'docker/backup-production.sh').is_file() and (ROOT/'docker/restore-production-backup.sh').is_file(),
    'backup restore drill exists': (ROOT/'docker/verify-backup-restore.sh').is_file(),
    'workflow executes the real backup restore drill': 'backup-restore-drill:' in workflow and './docker/verify-backup-restore.sh' in workflow,
    'workflow boots staging from the production compose': 'staging-compose:' in workflow and './docker/staging-up.sh .env.staging' in workflow,
    'workflow validates committed release provenance': 'qa/release_provenance_smoke.py . --require-git --require-clean' in workflow,
    'runtime ownership helper exists': (ROOT/'docker/prepare-runtime.sh').is_file(),
    'staging uses production compose instead of a divergent compose file': (ROOT/'docker/staging-up.sh').is_file() and not (ROOT/'docker-compose.staging.yml').exists(),
    'root npm lock is exact': root_pkgs.get('node_modules/esbuild', {}).get('version') == '0.28.2' and root_pkgs.get('node_modules/eslint', {}).get('version') == '10.10.0',
    'composer lock is present and non-empty': bool(composer_lock.get('packages-dev')),
    'CI and Docker build use npm ci without npm install fallback': workflow.count('npm ci') >= 2 and 'npm ci --ignore-scripts --no-audit --no-fund' in dockerfile and 'npm install ' not in dockerfile,
    'Playwright lock is exact': qa_pkgs.get('node_modules/@playwright/test', {}).get('version') == '1.55.1' and qa_pkgs.get('node_modules/playwright', {}).get('version') == '1.55.1',
    'browser CI covers Chromium + Firefox': browser_ci_monolithic or browser_ci_matrix,
    'MySQL CI is real 8.4': 'image: mysql:8.4' in workflow and 'qa/mysql_full_integration.php' in workflow,
    'security CI includes Semgrep and Trivy': 'semgrep/semgrep:1.169.0' in workflow and len(re.findall(r'uses:\s*aquasecurity/trivy-action@[0-9a-f]{40}\b', workflow)) >= 2,
    'production env template exposes legal identity': all(re.search(rf'^{re.escape(k)}=', env, re.M) for k in ['METEONEXA_PRIVACY_CONTACT_EMAIL','METEONEXA_LEGAL_CONTROLLER_NAME','METEONEXA_LEGAL_CONTROLLER_ADDRESS','METEONEXA_DPO_STATUS','METEONEXA_DPO_EMAIL','METEONEXA_LEGAL_SITE_URL']),
}

failed=[]
for name, ok in checks.items():
    print(f"[{'OK' if ok else 'FAIL'}] {name}")
    if not ok: failed.append(name)
if failed:
    print('Production readiness static smoke FAILED: ' + ', '.join(failed), file=sys.stderr)
    if 'single authoritative Markdown source of truth' in failed:
        print('Tracked/source Markdown files: ' + ', '.join(project_markdown), file=sys.stderr)
    raise SystemExit(1)
print('Production readiness static smoke PASS')
