#!/bin/sh
# Builds and (re)starts SIS on the AWS server (deploy/aws/sis.yaml). Run as root from the repo root:
#   sh deploy/aws/deploy.sh
# Same steps as the VPS (deploy/vps/deploy.sh: backup, build, migrate, least-privilege DB accounts),
# plus SIS's own Caddy. The site name is /etc/sis/domain when set, otherwise <public-ip>.sslip.io.
# Nothing secret is printed: the admin password goes to /root/sis-admin-password.txt (root only).
set -eu
cd "$(dirname "$0")/../.."
export HOME="${HOME:-/root}"

# First boot: wait for the setup in the template (Docker, swap) to finish.
command -v cloud-init >/dev/null && cloud-init status --wait >/dev/null 2>&1 || true
command -v docker >/dev/null || { echo "Docker is not installed yet; see /var/log/cloud-init-output.log" >&2; exit 1; }

domain="$(cat /etc/sis/domain 2>/dev/null || true)"
if [ -z "$domain" ]; then
    token=$(curl -fsS -X PUT http://169.254.169.254/latest/api/token -H 'X-aws-ec2-metadata-token-ttl-seconds: 60')
    ip=$(curl -fsS -H "X-aws-ec2-metadata-token: $token" http://169.254.169.254/latest/meta-data/public-ipv4)
    domain="$(echo "$ip" | tr . -).sslip.io"
fi

SIS_APP_URL="https://$domain" SIS_DOMAIN="$domain" \
SIS_COMPOSE_EXTRA=deploy/aws/docker-compose.aws.yml SIS_OWN_CADDY=1 \
    sh deploy/vps/deploy.sh

echo "SIS_URL=https://$domain"
