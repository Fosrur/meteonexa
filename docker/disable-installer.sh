#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
docker compose exec -T web rm -f /var/www/html/install/ENABLE_INSTALL 2>/dev/null || true
rm -f .installer-key
echo 'Installer disabilitato.'
