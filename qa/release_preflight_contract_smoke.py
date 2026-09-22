#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
script=(ROOT/'docker/release-preflight.sh').read_text(encoding='utf-8'); env=(ROOT/'.env.example').read_text(encoding='utf-8')
checks={
 'legal identity is optional and never blocks technical deploy':all(x in script for x in ('optional METEONEXA_LEGAL_CONTROLLER_NAME','optional METEONEXA_LEGAL_CONTROLLER_ADDRESS','optional METEONEXA_PRIVACY_CONTACT_EMAIL')),
 'DPO defaults to not-appointed and appointed still requires contact':'${METEONEXA_DPO_STATUS:-not-appointed}' in script and 'appointed) need METEONEXA_DPO_EMAIL' in script and 'METEONEXA_DPO_STATUS=not-appointed' in env,
 'SMTP production credentials are preflighted':'METEONEXA_SMTP_USERNAME' in script and 'METEONEXA_SMTP_PASSWORD' in script,
 'worker/push production secrets are preflighted':all(x in script for x in ['METEONEXA_PIPELINE_CRON_SECRET','METEONEXA_PUSH_CRON_SECRET','METEONEXA_VAPID_SUBJECT']),
 'external provider disclosures are preflighted':all(x in script for x in ('OpenRouter','OpenFreeMap','BigDataCloud','LibreWXR')),
 'dependency lockfiles block production GO when absent':all(x in script for x in ('package-lock.json','qa/package-lock.json','composer.lock','tools/generate-lockfiles.sh')),
 'runtime secret supports hardened package deployments':all(x in script for x in ('METEONEXA_RUNTIME_DIR','METEONEXA_WEB_CONTAINER_NAME','docker exec "$WEB_CONTAINER"','/var/lib/meteonexa/.app-secret')),
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(f"[{'OK' if v else 'FAIL'}] {k}")
if failed: print('Release preflight contract FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Release preflight contract PASS')
