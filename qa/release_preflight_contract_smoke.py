#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
script=(ROOT/'docker/release-preflight.sh').read_text(); env=(ROOT/'.env.example').read_text()
checks={
 'legal controller is mandatory before GO':all(x in script for x in ('METEONEXA_LEGAL_CONTROLLER_NAME','METEONEXA_LEGAL_CONTROLLER_ADDRESS','METEONEXA_PRIVACY_CONTACT_EMAIL')),
 'DPO status is explicit':'METEONEXA_DPO_STATUS' in script and 'appointed' in script and 'not-appointed' in script and 'METEONEXA_DPO_STATUS=not-appointed' in env,
 'SMTP production credentials are preflighted':'METEONEXA_SMTP_USERNAME' in script and 'METEONEXA_SMTP_PASSWORD' in script,
 'external provider disclosures are preflighted':all(x in script for x in ('OpenRouter','OpenFreeMap','BigDataCloud','LibreWXR')),
 'dependency lockfiles block production GO when absent':all(x in script for x in ('package-lock.json','qa/package-lock.json','composer.lock','tools/generate-lockfiles.sh')),
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(f"[{'OK' if v else 'FAIL'}] {k}")
if failed: print('Release preflight contract FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Release preflight contract PASS')
