#!/bin/sh
set -eu
cd "$(dirname "$0")/.."

# Secrets used by the worker/push paths must be present in the running containers.
for service in web worker; do
  docker compose exec -T "$service" sh -lc '
    test -n "${METEONEXA_PIPELINE_CRON_SECRET:-}" &&
    test -n "${METEONEXA_PUSH_CRON_SECRET:-}" &&
    test -n "${METEONEXA_VAPID_SUBJECT:-}"
  ' || { echo "[FAIL] $service: secret worker/push mancanti" >&2; exit 1; }
  echo "[OK] $service: secret worker/push presenti"
done

AUTH_JSON="$(docker compose exec -T web curl -fsS http://127.0.0.1/api/auth/status.php)"
printf '%s' "$AUTH_JSON" | grep -q '"smtpConfigured":true' || { echo '[FAIL] SMTP effettivo non configurato' >&2; exit 1; }
printf '%s' "$AUTH_JSON" | grep -q '"emailTransport":"smtp"' || { echo '[FAIL] transport email production non SMTP' >&2; exit 1; }
echo '[OK] configurazione SMTP effettiva caricata dal runtime'

PUSH_JSON="$(docker compose exec -T web curl -fsS http://127.0.0.1/api/push/public-key.php)"
printf '%s' "$PUSH_JSON" | grep -q '"ok":true' || { echo '[FAIL] endpoint VAPID non disponibile' >&2; exit 1; }
printf '%s' "$PUSH_JSON" | grep -Eq '"publicKey":"[^"]{20,}"' || { echo '[FAIL] chiave pubblica VAPID non valida' >&2; exit 1; }
echo '[OK] VAPID live disponibile'

# Authenticate against the effective SMTP profile over TLS without sending a message.
docker compose exec -T web php <<'PHP'
<?php
declare(strict_types=1);
require '/var/www/html/api/bootstrap.php';
$config = load_config();
if (!smtp_is_configured($config)) {
    fwrite(STDERR, "[FAIL] SMTP non configurato nel runtime\n");
    exit(1);
}
$smtp = (array)($config['smtp'] ?? []);
$host = trim((string)($smtp['host'] ?? ''));
$port = (int)($smtp['port'] ?? 587);
$encryption = strtolower(trim((string)($smtp['encryption'] ?? 'tls')));
$username = trim((string)($smtp['username'] ?? ''));
$password = (string)($smtp['password'] ?? '');
$timeout = max(5, min(30, (int)($smtp['timeout_seconds'] ?? 18)));
if ($host === '' || $username === '' || $password === '' || !in_array($encryption, ['tls','ssl'], true)) {
    fwrite(STDERR, "[FAIL] profilo SMTP runtime incompleto\n");
    exit(1);
}
$ctx = stream_context_create(['ssl'=>[
    'verify_peer'=>true,
    'verify_peer_name'=>true,
    'allow_self_signed'=>false,
    'SNI_enabled'=>true,
    'peer_name'=>$host,
]]);
$transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
$errno=0; $errstr='';
$s = @stream_socket_client($transport.$host.':'.$port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
if (!is_resource($s)) { fwrite(STDERR, "[FAIL] connessione SMTP\n"); exit(1); }
stream_set_timeout($s,$timeout);
function smtpRead($s): string {
    $all='';
    while (($line=fgets($s)) !== false) {
        $all.=$line;
        if (preg_match('/^\\d{3} /',$line)===1) break;
    }
    return $all;
}
function smtpCmd($s,string $cmd,array $codes,bool $secret=false): string {
    fwrite($s,$cmd."\r\n");
    $r=smtpRead($s);
    $code=(int)substr($r,0,3);
    if (!in_array($code,$codes,true)) {
        fwrite(STDERR,"[FAIL] SMTP comando ".($secret?'protetto':$cmd)." code=$code\n");
        exit(1);
    }
    return $r;
}
$r=smtpRead($s); if ((int)substr($r,0,3)!==220) { fwrite(STDERR,"[FAIL] banner SMTP\n"); exit(1); }
$hostname=preg_replace('/[^A-Za-z0-9.-]/','',(string)(gethostname()?:'')) ?: 'localhost';
$ehlo=smtpCmd($s,'EHLO '.$hostname,[250]);
if ($encryption==='tls') {
    smtpCmd($s,'STARTTLS',[220]);
    if (!defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) { fwrite(STDERR,"[FAIL] TLS 1.2 non supportato\n"); exit(1); }
    $method=(int)constant('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT');
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method|=(int)constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
    if (@stream_socket_enable_crypto($s,true,$method)!==true) { fwrite(STDERR,"[FAIL] STARTTLS\n"); exit(1); }
    $ehlo=smtpCmd($s,'EHLO '.$hostname,[250]);
}
$upper=strtoupper($ehlo);
if (str_contains($upper,'AUTH PLAIN')) {
    smtpCmd($s,'AUTH PLAIN '.base64_encode("\0".$username."\0".$password),[235],true);
} elseif (str_contains($upper,'AUTH LOGIN')) {
    smtpCmd($s,'AUTH LOGIN',[334]);
    smtpCmd($s,base64_encode($username),[334],true);
    smtpCmd($s,base64_encode($password),[235],true);
} else {
    fwrite(STDERR,"[FAIL] metodo AUTH SMTP non supportato\n"); exit(1);
}
smtpCmd($s,'QUIT',[221]);
fclose($s);
echo "[OK] SMTP TLS authentication live PASS\n";
PHP

echo 'LIVE_INTEGRATIONS_PASS smtp=auth vapid=ok worker_secrets=ok'
