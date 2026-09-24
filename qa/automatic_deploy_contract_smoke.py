#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
workflow = ROOT / '.github/workflows/deploy-production.yml'
deploy = ROOT / 'docker/deploy-production.sh'
errors = []

def require(text, needle, label):
    if needle not in text:
        errors.append(label)

if not workflow.is_file():
    errors.append('automatic production deploy workflow missing')
else:
    text = workflow.read_text(encoding='utf-8')
    require(text, 'workflow_run:', 'deploy must be triggered by completed QA workflow')
    require(text, 'workflows: ["MeteoNexa QA"]', 'deploy must depend on MeteoNexa QA')
    require(text, "github.event.workflow_run.conclusion == 'success'", 'deploy must require successful QA conclusion')
    require(text, "github.event.workflow_run.head_branch == 'main'", 'deploy must be main-only')
    require(text, 'METEONEXA_KEEP_MAINTENANCE=1', 'automatic deploy must retain maintenance through internal deploy checks')
    require(text, 'docker/deploy-production.sh', 'workflow must use canonical deploy script')
    require(text, 'qa/live_security_check.py https://www.meteonexa.com/ --allow-maintenance', 'maintenance-aware external production smoke missing')
    require(text, 'Release maintenance for final live validation', 'maintenance release step before final live validation missing')
    require(text, 'docker/maintenance-mode.sh off', 'maintenance release step missing')
    require(text, 'Restore maintenance on failed deployment validation', 'failed deployment validation must restore maintenance')
    require(text, 'if: failure()', 'maintenance preservation message must be conditional on workflow failure')
    require(text, 'bash docker/maintenance-mode.sh on', 'workflow must activate maintenance before canonical deploy')
    require(text, 'sleep 6', 'maintenance quiescence window must exceed the 5-second active-session poll interval')
    deploy_pos = text.find('bash docker/deploy-production.sh')
    smoke_pos = text.find('External live production security smoke under maintenance')
    release_pos = text.find('Release maintenance for final live validation')
    final_smoke_pos = text.find('External live production security smoke after release')
    if min(deploy_pos, smoke_pos, release_pos, final_smoke_pos) < 0 or not (deploy_pos < smoke_pos < release_pos < final_smoke_pos):
        errors.append('deploy order must be maintenance smoke -> release -> final live security smoke')
    require(text, 'METEONEXA_VPS_SSH_KEY', 'dedicated VPS SSH secret missing')
    require(text, 'METEONEXA_VPS_KNOWN_HOSTS', 'pinned VPS host key secret missing')
    require(text, 'REPOSITORY: ${{ github.repository }}', 'GitHub repository identity must be exported by workflow')
    require(text, 'METEONEXA_GITHUB_REPOSITORY="$REPOSITORY"', 'deploy must pass repository identity to CI verifier on VPS')
    require(text, 'git fetch --prune origin main', 'VPS must sync the validated SHA before starting the deploy script')
    require(text, 'git merge --ff-only', 'VPS sync must be fast-forward only')
    require(text, 'bash -s -- "$DEPLOY_SHA" "$REPOSITORY" <<\'REMOTE\'', 'VPS deploy must use a quoted remote heredoc')
    require(text, 'set -euo pipefail', 'remote deploy shell must fail fast')
    if r'\$(git status --porcelain)' in text:
        errors.append('legacy nested SSH quoting must be absent')
    require(text, 'test "$(git rev-parse HEAD)" = "$DEPLOY_SHA"', 'workflow must verify the exact SHA before starting deploy script')
    require(text, 'qa/live_security_check.py https://www.meteonexa.com/ --allow-maintenance', 'external security gate must validate the real maintenance response')
    require(text, 'run: python3 qa/live_security_check.py https://www.meteonexa.com/', 'final live HTTP 200 security gate missing')
    require(text, 'bash docker/maintenance-mode.sh on" || true', 'failure recovery must re-enable maintenance after a failed final validation')
    on_pos = text.find('bash docker/maintenance-mode.sh on')
    quiescence_pos = text.find('sleep 6', on_pos)
    script_pos = text.find('bash docker/deploy-production.sh')
    if min(on_pos, quiescence_pos, script_pos) < 0 or not (on_pos < quiescence_pos < script_pos):
        errors.append('automatic deploy must activate maintenance, wait for the 5-second client poll window, then start the canonical deploy script')

if deploy.is_file():
    text = deploy.read_text(encoding='utf-8')
    require(text, 'METEONEXA_KEEP_MAINTENANCE', 'deploy script cannot retain maintenance for external smoke')
    require(text, 'docker/verify-live-integrations.sh', 'deploy must verify real runtime integrations before maintenance release')
    require(text, 'DEPLOY_FINAL_PASS', 'deploy must emit the Final marker')
    if 'DEPLOY_RC2_PASS' in text:
        errors.append('legacy RC2 deploy marker must be absent')
else:
    errors.append('canonical deploy script missing')

if errors:
    print('AUTOMATIC DEPLOY CONTRACT FAILED')
    for item in errors:
        print(f'[FAIL] {item}')
    sys.exit(1)
print('AUTOMATIC DEPLOY CONTRACT PASS')
