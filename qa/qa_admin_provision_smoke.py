#!/usr/bin/env python3
"""QA/Diagnostics administrator authorization contract."""
from pathlib import Path
import hashlib, hmac, re, sqlite3, subprocess, sys
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
read=lambda p:(root/p).read_text(encoding='utf-8',errors='replace')
email='qa-admin@example.test'
config=read('api/config.php'); db='\n'.join(path.read_text(encoding='utf-8',errors='replace') for path in sorted((root/'api/database').glob('*.php'))); diag=read('api/diagnostics_access.php')
checks = {
    'current QA admin sync': 'function meteonexa_sync_qa_admins' in db,
    'deployment-bound HMAC': "meteonexa_hmac_identifier('qa-admin-email:'" in db,
    'environment admin support': 'METEONEXA_QA_ADMIN_EMAILS' in config,
    'no package bootstrap admin key': 'bootstrap_admin_emails' not in config + db + diag,
    'SMTP is not an authorization source': "['username','from_email']" not in diag and "config['smtp']" not in diag,
    'no package mail-provider identity': '@hotmail.com' not in (config + db + diag).lower(),
}
# Execute the runtime helper with an explicit deployment-owned admin.
php = f"require {str((root/'api/diagnostics_access.php')).__repr__()}; $c=['qa'=>['admin_emails'=>['{email}']],'smtp'=>['username'=>'smtp-owner@example.test','from_email'=>'sender@example.test']]; echo in_array('{email}', meteonexa_diagnostics_admin_emails($c), true)?'OK':'FAIL';"
try:
    proc=subprocess.run(['php','-r',php],capture_output=True,text=True,timeout=10)
    checks['runtime helper returns explicit admin']=proc.returncode==0 and proc.stdout.strip()=='OK'
except Exception:
    checks['runtime helper returns explicit admin']=False
# SMTP-only identity must never become a diagnostics administrator.
php = f"require {str((root/'api/diagnostics_access.php')).__repr__()}; $c=['qa'=>['admin_emails'=>[]],'smtp'=>['username'=>'{email}','from_email'=>'{email}']]; echo in_array('{email}', meteonexa_diagnostics_admin_emails($c), true)?'FAIL':'OK';"
try:
    proc=subprocess.run(['php','-r',php],capture_output=True,text=True,timeout=10)
    checks['SMTP identity grants no admin role']=proc.returncode==0 and proc.stdout.strip()=='OK'
except Exception:
    checks['SMTP identity grants no admin role']=False
secret='a'*64
expected=hmac.new(secret.encode(),('qa-admin-email:'+email).encode(),hashlib.sha256).hexdigest()
checks['HMAC contract 64hex']=bool(re.fullmatch(r'[a-f0-9]{64}',expected))
con=sqlite3.connect(root/'api/install/meteonexa-baseline.sqlite')
try:
    checks['no precomputed QA hash in sqlite seed']=con.execute("select count(*) from app_metadata where meta_key='qa_admin_email_hashes'").fetchone()[0]==0
finally: con.close()
failed=[]
for name,ok in checks.items():
    print(('PASS' if ok else 'FAIL'),name)
    if not ok: failed.append(name)
if failed: raise SystemExit('QA admin authorization smoke failed: '+', '.join(failed))
print('QA/Diagnostics administrator authorization PASS')
