#!/usr/bin/env bash
# UNA VEZ por host, como root (sudo bash install-host.sh): instala las herramientas de FS y deja al usuario de despliegue
# (ploi) con sudo SOLO sobre ellas, no sudo general. Revocar: rm /usr/local/sbin/fs-* /etc/sudoers.d/fs-deploy
set -Eeuo pipefail
D="$(cd "$(dirname "$0")" && pwd)"; DEPLOY_USER="${FS_DEPLOY_USER:-ploi}"
install -m 750 -o root -g root "$D/provision-fs-tenant.sh" /usr/local/sbin/fs-provision-tenant
install -m 750 -o root -g root "$D/fs-deploy-release.sh"   /usr/local/sbin/fs-deploy-release
install -d -m 750 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/fs-releases"
f=/etc/sudoers.d/fs-deploy
printf '%s ALL=(root) NOPASSWD: /usr/local/sbin/fs-provision-tenant, /usr/local/sbin/fs-deploy-release\n' "$DEPLOY_USER" > "$f.tmp"
visudo -cf "$f.tmp" >/dev/null && chmod 440 "$f.tmp" && mv "$f.tmp" "$f"
echo "instalado: sudo de $DEPLOY_USER limitado a fs-provision-tenant y fs-deploy-release"
