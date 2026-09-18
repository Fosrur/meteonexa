#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]; read=lambda p:(ROOT/p).read_text(encoding='utf-8')
req=read('api/auth/request-code.php'); verify=read('api/auth/verify-code.php'); auth=read('api/auth_session.php'); devices=read('api/auth/devices.php'); logout=read('api/auth/logout.php'); status=read('api/auth/status.php'); smtp=read('api/SmtpMailer.php'); bug=read('api/feedback/bug-report.php'); worker=read('api/pipeline/worker.php'); push=read('api/push/dispatch.php'); notifications=read('modules/esm/domains/notifications.mjs'); app=read('app.js'); config=read('api/config.php')
checks={
 'OTP is device-bound and server-verified':'meteonexa_otp_device_scope_hash' in req and 'meteonexa_otp_device_scope_hash' in verify and 'password_verify' in verify,
 'trusted-device proof is server-side':'trusted_devices' in auth and 'meteonexa_current_trusted_device_identity' in read('api/auth/trusted-check.php'),
 'remote logout/revoke others is supported':'revokeOthers' in devices and 'DELETE FROM trusted_devices' in devices,
 'stale trusted cookie is cleared after remote revoke':'meteonexa_clear_stale_trusted_device_cookie' in auth and 'meteonexa_clear_stale_trusted_device_cookie' in status,
 'explicit logout revokes server session/trust':'meteonexa_revoke_current_auth_session' in logout and 'meteonexa_revoke_current_trusted_device' in logout,
 'SMTP transport uses authenticated TLS path':'AUTH PLAIN' in smtp and 'STARTTLS' in smtp,
 'bug report recipient is server controlled':'recipient_email' in bug and 'privacy_contact_email' in bug and 'METEONEXA_BUG_REPORT_EMAIL' in config,
 'worker is protected and lock-serialized':'HTTP_X_CRON_KEY' in worker and "meteonexa_acquire_lock('industrial-weather-pipeline')" in worker,
 'push dispatch is server-side':'push_subscriptions' in push,
 'notification UI binds subscription/permission lifecycle':'Notification.requestPermission' in notifications and 'serviceWorker' in notifications,
 'foreground device reconciliation exists':'reconcileRemoteDeviceRevocation' in app,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(f"[{'OK' if v else 'FAIL'}] {k}")
if failed: print('Release flow contract FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Release flow contract PASS')
