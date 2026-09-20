#!/usr/bin/env python3
"""Verified Trust request-code/device-history regression gate."""
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
request=(ROOT/'api/auth/request-code.php').read_text(encoding='utf-8')
verify=(ROOT/'api/auth/verify-code.php').read_text(encoding='utf-8')
auth=(ROOT/'api/auth_session.php').read_text(encoding='utf-8')
devices=(ROOT/'api/auth/devices.php').read_text(encoding='utf-8')
status=(ROOT/'api/auth/status.php').read_text(encoding='utf-8')
app=(ROOT/'js/app.js').read_text(encoding='utf-8')
checks={
 'request validates browser proof':"clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID']" in request and '$deviceKey = meteonexa_device_key()' in request,
 'verify same scope':'meteonexa_otp_device_scope_hash($config, $email, $deviceId, $deviceKey)' in verify,
 'no challengeId critical path':'challengeId' not in request and 'challengeId' not in verify and 'authLoginChallengeId' not in app,
 'history 30 days':"$cutoff = $now - 30 * 86400" in devices and "'retentionDays'=>30" in devices,
 'active/history separated':"'activeDevices'=>$activeDevices" in devices and "'history'=>$history" in devices and 'devices-history-disclosure' in app,
 'revoke others includes dormant trusted devices':"'revocableOthersCount'=>$revocableOthersCount" in devices and "SELECT DISTINCT device_id FROM trusted_devices" in devices and "DELETE FROM trusted_devices WHERE email_hash=:email AND device_id<>:current" in devices and "data?.revocableOthersCount" in app,
 'remote cookie cleanup':'meteonexa_clear_stale_trusted_device_cookie' in auth and 'meteonexa_clear_stale_trusted_device_cookie($pdo, $config)' in status,
 'foreground remote revoke':'reconcileRemoteDeviceRevocation' in app,
 'portable pruning':'LIMIT -1 OFFSET 8' not in auth and 'meteonexa_prune_identity_rows' in auth,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(('[ OK ] ' if v else '[FAIL] ')+k)
if failed: print('FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Auth request/device-history runtime gate PASS')
