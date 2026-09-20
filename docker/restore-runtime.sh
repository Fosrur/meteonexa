#!/bin/sh
set -eu
cd "$(dirname "$0")/.."

BASELINE="${1:-api/install/meteonexa-baseline.sqlite}"
if [ ! -f "$BASELINE" ]; then
  echo "Baseline SQLite non trovata: $BASELINE" >&2
  exit 1
fi

mkdir -p runtime
if [ -n "$(find runtime -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
  echo 'La cartella runtime non è vuota: non sovrascrivo dati esistenti.' >&2
  exit 1
fi

cp "$BASELINE" runtime/meteonexa.sqlite
chmod 700 runtime
chmod 660 runtime/meteonexa.sqlite

# La baseline distribuita è sanitizzata e marcata deployment_unbound=1.
# Creiamo un secret specifico dell'installazione senza includere segreti nello ZIP.
umask 077
if command -v openssl >/dev/null 2>&1; then
  openssl rand -hex 32 > runtime/.app-secret
elif command -v python3 >/dev/null 2>&1; then
  python3 - <<'PY' > runtime/.app-secret
import secrets
print(secrets.token_hex(32))
PY
else
  echo 'ERRORE: serve openssl o python3 per generare .app-secret.' >&2
  rm -f runtime/meteonexa.sqlite
  exit 1
fi
chmod 600 runtime/.app-secret
find runtime -maxdepth 1 -type f -name '*.lock' -delete 2>/dev/null || true

echo 'Runtime SQLite pulito creato da api/install/meteonexa-baseline.sqlite.'
echo 'Nessun segreto o dato utente di produzione è incluso nel pacchetto.'
