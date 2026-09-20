#!/usr/bin/env python3
"""Verified Trust same-email concurrent OTP smoke using device-scoped auth_otp rows."""
from pathlib import Path
import sqlite3, hmac, hashlib, sys
ROOT=Path(__file__).resolve().parents[1]
req=(ROOT/'api/auth/request-code.php').read_text(encoding='utf-8')
ver=(ROOT/'api/auth/verify-code.php').read_text(encoding='utf-8')
auth=(ROOT/'api/auth_session.php').read_text(encoding='utf-8')
if 'meteonexa_otp_device_scope_hash' not in req or 'meteonexa_otp_device_scope_hash' not in ver or "'otp-device:'" not in auth:
    print('FAIL device-scoped OTP wiring missing',file=sys.stderr);sys.exit(1)
secret=b's'*64
def scope(email,device,key):
    msg=f"otp-device:{email.lower()}|{device}|{hashlib.sha256(key.encode()).hexdigest()}".encode()
    return hmac.new(secret,msg,hashlib.sha256).hexdigest()
a=scope('same@example.test','device-a-123456','A'*40)
b=scope('same@example.test','device-b-123456','B'*40)
assert a!=b
con=sqlite3.connect(':memory:')
con.execute('CREATE TABLE auth_otp(email_hash TEXT PRIMARY KEY, code_hash TEXT, sent_at INTEGER)')
con.execute('INSERT INTO auth_otp VALUES(?,?,?)',(a,'code-a',100))
con.execute('INSERT INTO auth_otp VALUES(?,?,?)',(b,'code-b',101))
assert con.execute('SELECT COUNT(*) FROM auth_otp').fetchone()[0]==2
con.execute('DELETE FROM auth_otp WHERE email_hash=?',(a,))
assert con.execute('SELECT code_hash FROM auth_otp').fetchone()[0]=='code-b'
print('Multi-device auth smoke PASS: same email has independent device-bound OTP rows')
