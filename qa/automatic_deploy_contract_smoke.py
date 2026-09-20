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
    require(text, 'METEONEXA_KEEP_MAINTENANCE=1', 'automatic deploy must retain maintenance through external smoke')
    require(text, 'docker/deploy-production.sh', 'workflow must use canonical deploy script')
    require(text, 'qa/live_security_check.py https://www.meteonexa.com/', 'external production smoke missing')
    require(text, 'docker/maintenance-mode.sh off', 'maintenance release step missing')
    require(text, 'METEONEXA_VPS_SSH_KEY', 'dedicated VPS SSH secret missing')
    require(text, 'METEONEXA_VPS_KNOWN_HOSTS', 'pinned VPS host key secret missing')

if deploy.is_file():
    text = deploy.read_text(encoding='utf-8')
    require(text, 'METEONEXA_KEEP_MAINTENANCE', 'deploy script cannot retain maintenance for external smoke')
else:
    errors.append('canonical deploy script missing')

if errors:
    print('AUTOMATIC DEPLOY CONTRACT FAILED')
    for item in errors:
        print(f'[FAIL] {item}')
    sys.exit(1)
print('AUTOMATIC DEPLOY CONTRACT PASS')
