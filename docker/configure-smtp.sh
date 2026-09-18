#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "ERRORE: .env non trovato. Esegui prima ./docker/init-env.sh" >&2
  exit 1
fi

read -r -p "Login SMTP Brevo: " SMTP_USER
SMTP_USER="${SMTP_USER//$'\r'/}"
SMTP_USER="${SMTP_USER//$'\n'/}"
if [[ ! "$SMTP_USER" =~ ^[A-Za-z0-9._%+-]+@smtp-brevo\.com$ ]]; then
  echo "ERRORE: login SMTP Brevo non valido (atteso ...@smtp-brevo.com)." >&2
  exit 1
fi

read -r -s -p "Chiave SMTP Brevo (incolla SOLO la chiave xsmtpsib-...): " SMTP_PASS
echo
# read elimina il newline finale, ma manteniamo una validazione stretta per
# impedire commenti/testo accidentale come '?nonmipare' o whitespace.
SMTP_PASS="${SMTP_PASS//$'\r'/}"
SMTP_PASS="${SMTP_PASS//$'\n'/}"
if [ -z "$SMTP_PASS" ]; then
  echo "ERRORE: la chiave SMTP è obbligatoria." >&2
  exit 1
fi
if [[ "$SMTP_PASS" =~ [[:space:]] ]]; then
  echo "ERRORE: la chiave SMTP contiene spazi/tab. Ricopiala dal pulsante Copy di Brevo." >&2
  unset SMTP_PASS
  exit 1
fi
# Formato Standard Brevo osservato: xsmtpsib- + 64 caratteri + '-' + 16 caratteri.
# Se Brevo cambiasse formato, lo script fallisce in sicurezza anziché salvare una credenziale ambigua.
if [[ ! "$SMTP_PASS" =~ ^xsmtpsib-[A-Za-z0-9]{64}-[A-Za-z0-9]{16}$ ]]; then
  echo "ERRORE: formato SMTP key Brevo inatteso. Per la variante Standard è atteso xsmtpsib-<64>-<16> senza altri caratteri." >&2
  echo "       Non aggiungere '?' o testo dopo la chiave." >&2
  unset SMTP_PASS
  exit 1
fi

read -r -p "Mittente [alerts@meteonexa.com]: " FROM_EMAIL
FROM_EMAIL="${FROM_EMAIL:-alerts@meteonexa.com}"
FROM_EMAIL="${FROM_EMAIL//$'\r'/}"
FROM_EMAIL="${FROM_EMAIL//$'\n'/}"
if [[ ! "$FROM_EMAIL" =~ ^[^[:space:]@]+@meteonexa\.com$ ]]; then
  echo "ERRORE: il mittente deve essere un indirizzo @meteonexa.com." >&2
  unset SMTP_PASS
  exit 1
fi

export SMTP_USER SMTP_PASS FROM_EMAIL
python3 - <<'PY'
from pathlib import Path
import os

path = Path('.env')
text = path.read_text()
values = {
    'METEONEXA_SMTP_HOST': 'smtp-relay.brevo.com',
    'METEONEXA_SMTP_PORT': '587',
    'METEONEXA_SMTP_ENCRYPTION': 'tls',
    'METEONEXA_SMTP_USERNAME': os.environ['SMTP_USER'],
    'METEONEXA_SMTP_PASSWORD': os.environ['SMTP_PASS'],
    'METEONEXA_SMTP_FROM_EMAIL': os.environ['FROM_EMAIL'],
    'METEONEXA_SMTP_FROM_NAME': 'MeteoNexa',
    'METEONEXA_SMTP_TIMEOUT_SECONDS': '18',
}
lines = text.splitlines()
seen = set()
out = []
for line in lines:
    if '=' in line and not line.lstrip().startswith('#'):
        key = line.split('=', 1)[0]
        if key in values:
            out.append(f'{key}={values[key]}')
            seen.add(key)
            continue
    out.append(line)
for key, val in values.items():
    if key not in seen:
        out.append(f'{key}={val}')
path.write_text('\n'.join(out) + '\n')
PY
chmod 600 .env
unset SMTP_PASS SMTP_USER BUG_TO FROM_EMAIL

echo "Configurazione SMTP salvata in .env (segreti non mostrati)."
echo "Ricreo web e worker..."
docker compose up -d --force-recreate web worker

echo "Sincronizzo la credenziale SMTP nel database runtime cifrato..."
docker compose exec -T web php -r '''
require_once "/var/www/html/api/bootstrap.php";
require_once "/var/www/html/api/database.php";
$config = require "/var/www/html/api/config.php";
$config["auth"] = (array)($config["auth"] ?? []);
$config["auth"]["app_secret"] = meteonexa_resolve_app_secret();
$smtp = (array)($config["smtp"] ?? []);
$password = (string)($smtp["password"] ?? "");
if ($password === "") { fwrite(STDERR, "SMTP password assente nell ambiente\n"); exit(2); }
$pdo = meteonexa_db($config);
meteonexa_smtp_upsert_encrypted($pdo, $config, $smtp, $password);
echo "SMTP DB sync: OK\n";
'''

echo "Verifico che runtime DB ed environment usino la stessa credenziale..."
docker compose exec -T web php -r '''
require_once "/var/www/html/api/bootstrap.php";
$c = load_config();
$env = (string)(getenv("METEONEXA_SMTP_PASSWORD") ?: "");
$db = (string)($c["smtp"]["password"] ?? "");
if ($env === "" || $db === "" || !hash_equals($env, $db)) {
    fwrite(STDERR, "SMTP credential sync: ERRORE\n");
    exit(3);
}
echo "SMTP credential sync: OK\n";
'''

echo
echo "Stato container:"
docker compose ps web worker

echo
echo "Configurazione applicata. Verifica con:"
echo "docker compose exec -T web env | grep '^METEONEXA_SMTP_' | grep -v PASSWORD"
