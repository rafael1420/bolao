#!/usr/bin/env bash
# Gera release/bolao-site.zip para extrair direto no public_html da Hostinger.
# Precisa de Node 20+ só nesta máquina; o servidor só roda PHP.
set -euo pipefail
cd "$(dirname "$0")"
(cd web && npm ci && npm run build)
rm -rf release && mkdir -p release/site
cp -r dist/. release/site/
cp -r api includes sql assets install.php .htaccess release/site/
rm -f release/site/includes/config.php   # cada servidor tem o seu (criado pelo install.php)
(cd release/site && zip -qr ../bolao-site.zip . -x '.DS_Store')
cp sql/schema.sql release/
echo "Pronto: release/bolao-site.zip e release/schema.sql"
