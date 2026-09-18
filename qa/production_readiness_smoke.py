#!/usr/bin/env python3
from pathlib import Path
import json, re, subprocess, sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
read = lambda rel: (ROOT / rel).read_text(encoding='utf-8')
compose = read('docker-compose.yml')
dockerfile = read('Dockerfile')
workflow = read('.github/workflows/meteonexa-tests.yml')
env = read('.env.example')
md = read('METEONEXA-20.1-RC2.md')

root_lock = json.loads(read('package-lock.json'))
qa_lock = json.loads(read('qa/package-lock.json'))
composer_lock = json.loads(read('composer.lock'))
root_pkgs = root_lock.get('packages', {})
qa_pkgs = qa_lock.get('packages', {})


def source_markdown_files() -> list[str]:
    """Return Markdown files belonging to the source tree, not installed deps.

    CI intentionally runs npm/composer installs before the release smoke. Their
    dependency READMEs must never turn the one-authoritative-project-document
    contract red. Prefer the Git index; keep a package/archive fallback for
    release bundles that are tested without a .git directory.
    """
    try:
        result = subprocess.run(
            ['git', '-C', str(ROOT), 'ls-files', '*.md'],
            check=True,
            text=True,
            capture_output=True,
        )
        return sorted(line.strip() for line in result.stdout.splitlines() if line.strip())
    except (OSError, subprocess.CalledProcessError):
        ignored = {'.git', 'node_modules', 'vendor', '.cache', '.build'}
        files = []
        for path in ROOT.rglob('*.md'):
            relative = path.relative_to(ROOT)
            if any(part in ignored for part in relative.parts):
                continue
            files.append(relative.as_posix())
        return sorted(files)


project_markdown = source_markdown_files()

checks = {
    'single consolidated Markdown source of truth': project_markdown == ['METEONEXA-20.1-RC2.md'],
    'schema 28 is the current contract': 'schema **28**' in md or 'schema 28' in md,
    'runtime bind mount is explicit and staging-parameterized': compose.count('${METEONEXA_RUNTIME_DIR:-./runtime}:/var/lib/meteonexa') >= 2,
    'staging can isolate all fixed container names': all(x in compose for x in ['METEONEXA_DB_CONTAINER_NAME', 'METEONEXA_WEB_CONTAINER_NAME', 'METEONEXA_WORKER_CONTAINER_NAME']),
    'legal controller fields reach web and worker': all(x in compose for x in ['METEONEXA_LEGAL_CONTROLLER_NAME', 'METEONEXA_LEGAL_CONTROLLER_ADDRESS', 'METEONEXA_DPO_STATUS', 'METEONEXA_DPO_EMAIL', 'METEONEXA_LEGAL_SITE_URL']),
    'worker/push secrets reach web and worker': all(x in compose for x in ['METEONEXA_PIPELINE_CRON_SECRET','METEONEXA_PUSH_CRON_SECRET','METEONEXA_VAPID_SUBJECT']),
    'Plausible deployment settings reach web and worker': 'METEONEXA_PLAUSIBLE_ENABLED' in compose and 'METEONEXA_PLAUSIBLE_DOMAIN' in compose,
    'backup and destructive restore are separate explicit scripts': (ROOT/'docker/backup-production.sh').is_file() and (ROOT/'docker/restore-production-backup.sh').is_file(),
    'backup restore drill exists': (ROOT/'docker/verify-backup-restore.sh').is_file(),
    'workflow executes the real backup restore drill': 'backup-restore-drill:' in workflow and './docker/verify-backup-restore.sh' in workflow,
    'workflow boots staging from the production compose': 'staging-compose:' in workflow and './docker/staging-up.sh .env.staging' in workflow,
    'runtime ownership helper exists': (ROOT/'docker/prepare-runtime.sh').is_file(),
    'staging uses production compose instead of a divergent compose file': (ROOT/'docker/staging-up.sh').is_file() and not (ROOT/'docker-compose.staging.yml').exists(),
    'root npm lock is exact': root_pkgs.get('node_modules/esbuild', {}).get('version') == '0.28.2' and root_pkgs.get('node_modules/eslint', {}).get('version') == '10.10.0',
    'composer lock is present and non-empty': bool(composer_lock.get('packages-dev')),
    'CI and Docker build use npm ci without npm install fallback': workflow.count('npm ci') >= 2 and 'npm ci --ignore-scripts --no-audit --no-fund' in dockerfile and 'npm install ' not in dockerfile,
    'Playwright lock is exact': qa_pkgs.get('node_modules/@playwright/test', {}).get('version') == '1.55.0' and qa_pkgs.get('node_modules/playwright', {}).get('version') == '1.55.0',
    'browser CI is Chromium + Firefox': 'chromium firefox' in workflow and 'npm run test:e2e' in workflow,
    'MySQL CI is real 8.4': 'image: mysql:8.4' in workflow and 'qa/mysql_full_integration.php' in workflow,
    'security CI includes Semgrep and Trivy': 'semgrep/semgrep:1.169.0' in workflow and workflow.count('aquasecurity/trivy-action@v0.36.0') >= 2,
    'production env template exposes legal identity': all(re.search(rf'^{re.escape(k)}=', env, re.M) for k in ['METEONEXA_PRIVACY_CONTACT_EMAIL','METEONEXA_LEGAL_CONTROLLER_NAME','METEONEXA_LEGAL_CONTROLLER_ADDRESS','METEONEXA_DPO_STATUS','METEONEXA_DPO_EMAIL','METEONEXA_LEGAL_SITE_URL']),
}

failed=[]
for name, ok in checks.items():
    print(f"[{'OK' if ok else 'FAIL'}] {name}")
    if not ok: failed.append(name)
if failed:
    print('Production readiness static smoke FAILED: ' + ', '.join(failed), file=sys.stderr)
    if 'single consolidated Markdown source of truth' in failed:
        print('Tracked/source Markdown files: ' + ', '.join(project_markdown), file=sys.stderr)
    raise SystemExit(1)
print('Production readiness static smoke PASS')
