#!/usr/bin/env bash
# Sets up status.piuskl.me: Uptime Kuma in Docker behind Apache over HTTPS.
#
#   sudo ./setup-status.sh
#
# Before running:
#   1. Cloudflare DNS: add  A  status -> <VPS public IP>  (DNS only, grey cloud)
#   2. Run deploy/tls/setup-zerossl.sh so the certificate covers *.piuskl.me
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
CERT=/etc/ssl/certs/shop.piuskl.me.crt
KUMA_DIR=/opt/uptime-kuma

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARNING: %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run with sudo"

if ! openssl x509 -in "$CERT" -noout -ext subjectAltName 2>/dev/null | grep -qE 'status\.piuskl\.me|\*\.piuskl\.me'; then
  warn "$CERT does not cover status.piuskl.me yet. Browsers will show a certificate error until you run deploy/tls/setup-zerossl.sh."
fi

if ! docker compose version >/dev/null 2>&1; then
  say "Installing Docker"
  apt-get update -q
  apt-get install -y -q docker.io docker-compose-v2
  systemctl enable --now docker
fi

say "Starting Uptime Kuma"
install -d -m 755 "$KUMA_DIR"
install -m 644 "$HERE/docker-compose.yml" "$KUMA_DIR/docker-compose.yml"
docker compose -f "$KUMA_DIR/docker-compose.yml" up -d

say "Configuring Apache"
a2enmod -q proxy proxy_http proxy_wstunnel rewrite headers ssl
install -m 644 -o root -g root "$HERE/status.piuskl.me.conf" /etc/apache2/sites-available/status.piuskl.me.conf
a2ensite -q status.piuskl.me
apachectl configtest
systemctl reload apache2

say "Waiting for Uptime Kuma to start"
for _ in $(seq 1 30); do
  curl -fs -o /dev/null http://127.0.0.1:3001 && break
  sleep 2
done
curl -fs -o /dev/null http://127.0.0.1:3001 || die "Uptime Kuma isn't answering on 127.0.0.1:3001 (check: docker logs uptime-kuma)"

cat <<'EOF'

Uptime Kuma is running.

DO THIS NOW: open https://status.piuskl.me and create the admin account.
Until you do, anyone who opens the page can claim it.

Then follow "Status page" in deploy/hardening/README.md to add the monitors
and the public status page.
EOF
