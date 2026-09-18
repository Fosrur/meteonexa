from pathlib import Path
p = Path(__file__).resolve().parents[1] / 'api' / 'SmtpMailer.php'
s = p.read_text(encoding='utf-8')
checks = {
    'EHLO response retained': "$ehloResponse = $this->command('EHLO ' . $hostname, [250]);" in s,
    'AUTH PLAIN supported': "AUTH PLAIN " in s and 'base64_encode("\\0" . $username . "\\0" . $password)' in s,
    'AUTH PLAIN preferred': s.find("in_array('PLAIN'") < s.find("in_array('LOGIN'"),
    'AUTH LOGIN fallback': "AUTH LOGIN" in s,
    'unsupported auth explicit': 'SMTP_AUTH_METHOD_UNSUPPORTED' in s,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items():
    print(('PASS' if v else 'FAIL') + ': ' + k)
if failed:
    raise SystemExit(1)
print('SMTP AUTH smoke PASS')
