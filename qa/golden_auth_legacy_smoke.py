#!/usr/bin/env python3
"""Verified Trust auth regression: keep the proven auth_otp path while scoping OTPs per device."""
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
req=(ROOT/'api/auth/request-code.php').read_text(encoding='utf-8')
ver=(ROOT/'api/auth/verify-code.php').read_text(encoding='utf-8')
auth=(ROOT/'api/auth_session.php').read_text(encoding='utf-8')
app=(ROOT/'app.js').read_text(encoding='utf-8')
checks={
 'request keeps auth_otp path':'auth_otp' in req and 'ON CONFLICT(email_hash)' in req,
 'verify keeps auth_otp path':'FROM auth_otp WHERE email_hash=:email' in ver,
 'device scope helper':'function meteonexa_otp_device_scope_hash' in auth and "'otp-device:'" in auth,
 'request device-bound':'meteonexa_otp_device_scope_hash($config, $email, $deviceId, $deviceKey)' in req,
 'verify device-bound':'meteonexa_otp_device_scope_hash($config, $email, $deviceId, $deviceKey)' in ver,
 'no challengeId contract':'authLoginChallengeId' not in app and 'challengeId' not in req and 'challengeId' not in ver,
 'audit fail-safe':'auth_access_record_failed' in auth and 'auth_access_mark_ended_failed' in auth,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(('[ OK ] ' if v else '[FAIL] ')+k)
if failed: print('FAILED: '+', '.join(failed),file=sys.stderr); sys.exit(1)
print('Auth Verified Trust regression PASS: proven auth_otp path retained, OTP scoped to browser device')
