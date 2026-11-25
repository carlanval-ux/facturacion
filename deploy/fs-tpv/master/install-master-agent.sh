#!/usr/bin/env bash
# UNA VEZ por host, como root. NO SE HA EJECUTADO. Requiere autorizacion del OWNER (el host aloja produccion del TPV).
# Instala el agente, crea el usuario fs-master (sin shell) y la cola. NO activa el temporizador ni toca sshd: eso es manual (ver docs).
set -Eeuo pipefail
D="$(cd "$(dirname "$0")" && pwd)"
getent group fs-master >/dev/null || groupadd --system fs-master
id fs-master >/dev/null 2>&1 || useradd --system --gid fs-master --home /var/lib/fs-master/sftp --shell /usr/sbin/nologin fs-master
install -d -m 755 -o root -g root /var/lib/fs-master/sftp                 # raiz del chroot SFTP: root:root, sin escritura de grupo
install -d -m 3770 -o root -g fs-master /var/lib/fs-master/sftp/requests  # el MASTER escribe peticiones
install -d -m 750 -o root -g fs-master /var/lib/fs-master/sftp/responses  # el MASTER lee respuestas
install -d -m 700 /var/lib/fs-master /etc/fs-master /etc/fs-master/keys /etc/fs/suspend
install -d -m 755 /opt/fs-master
install -m 644 "$D/lib.php" "$D/agent.php" /opt/fs-master/
[[ -f /etc/fs-master/agent.json ]] || install -m 600 "$D/agent.json.example" /etc/fs-master/agent.json
install -m 644 "$D/systemd/fs-master-agent.service" "$D/systemd/fs-master-agent.timer" /etc/systemd/system/
systemctl daemon-reload
echo "instalado. Pendiente (manual): clave /etc/fs-master/keys/<kid>.key (openssl rand -hex 32), authorized_keys de fs-master, sshd (sshd-fs-master.conf.example), release por defecto en /etc/fs-master/default-release y 'systemctl enable --now fs-master-agent.timer'"
