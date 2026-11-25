#!/usr/bin/env bash
# Instala el agente MASTER de Facturalo en el host. COMO ROOT, desde esta carpeta:   sudo bash instalar-todo.sh
# Idempotente. NO activa el temporizador, NO toca tenants (redtelecom, teleco-test, carlos), nginx ni /srv/fs. NO imprime la clave.
# Si algo falla, deshace lo creado en ESTA ejecucion (y restaura sshd). Variables opcionales:
#   RELEASE_TAG=fs-v2026.72-plug10     etiqueta de release por defecto
#   RELEASE_FROM=<ruta a un fs-release.tar.gz>   lo publica como <RELEASE_TAG>.tar.gz(+.sha256) si aun no existe
#   MASTER_PUBKEY='ssh-ed25519 AAAA... panel' [MASTER_IP=1.2.3.4]   clave publica SFTP del panel (se anade con restrict y from=)
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo "ejecutar como root" >&2; exit 1; }
D="$(cd "$(dirname "$0")" && pwd)"
TAG="${RELEASE_TAG:-fs-v2026.72-plug10}"
[[ "$TAG" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || { echo "RELEASE_TAG invalido" >&2; exit 1; }
REL=/home/ploi/fs-releases
for f in lib.php agent.php agent.json.example sshd-fs-master.conf.example systemd/fs-master-agent.service systemd/fs-master-agent.timer; do
  [[ -f "$D/$f" ]] || { echo "falta $D/$f" >&2; exit 1; }
done
for c in php sshd openssl systemctl; do command -v "$c" >/dev/null || { echo "falta $c" >&2; exit 1; }; done
php -l "$D/lib.php" >/dev/null && php -l "$D/agent.php" >/dev/null
exec 9>/var/lock/fs-master-install
flock -n 9 || { echo "otra instalacion en curso" >&2; exit 1; }

# --- rollback: solo lo creado en esta ejecucion (se ejecuta en orden inverso al registro) ---
UNDO=()
SSHD_BACKUP=""
undo() {
  local i
  for ((i = ${#UNDO[@]} - 1; i >= 0; i--)); do eval "${UNDO[i]}" 2>/dev/null || true; done
  if [[ -n "$SSHD_BACKUP" ]]; then
    cp -p "$SSHD_BACKUP" /etc/ssh/sshd_config
    if sshd -t; then systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true; fi
  fi
  systemctl daemon-reload 2>/dev/null || true
}
trap 'rc=$?; echo "FALLO (linea $LINENO): deshaciendo lo creado en esta ejecucion" >&2; undo; exit $rc' ERR
mk() { [[ -e "$1" ]] || UNDO+=("rm -rf '$1'"); }   # registra para deshacer solo lo que no existia
sshd_reload() { systemctl reload ssh 2>/dev/null || systemctl reload sshd; }

echo "[1/5] usuario y colas"
getent group fs-master >/dev/null || { groupadd --system fs-master; UNDO+=("groupdel fs-master"); }
id fs-master >/dev/null 2>&1 || { useradd --system --gid fs-master --home /var/lib/fs-master/sftp --shell /usr/sbin/nologin fs-master; UNDO+=("userdel fs-master"); }
mk /var/lib/fs-master; mk /etc/fs-master; mk /opt/fs-master; mk /etc/fs/suspend
install -d -m 700 /var/lib/fs-master /etc/fs-master /etc/fs-master/keys
install -d -m 755 -o root -g root /var/lib/fs-master/sftp                   # raiz del chroot: root:root, sin escritura de grupo
install -d -m 3770 -o root -g fs-master /var/lib/fs-master/sftp/requests    # el MASTER escribe peticiones
install -d -m 750 -o root -g fs-master /var/lib/fs-master/sftp/responses    # el MASTER lee respuestas
install -d -m 755 /etc/fs /etc/fs/suspend /opt/fs-master

echo "[2/5] agente y unidades (temporizador SIN activar)"
for f in lib.php agent.php; do mk "/opt/fs-master/$f"; install -m 644 "$D/$f" "/opt/fs-master/$f"; done
if [[ ! -f /etc/fs-master/agent.json ]]; then install -m 600 "$D/agent.json.example" /etc/fs-master/agent.json; UNDO+=("rm -f /etc/fs-master/agent.json"); fi
for u in fs-master-agent.service fs-master-agent.timer; do
  [[ -e /etc/systemd/system/$u ]] || UNDO+=("rm -f /etc/systemd/system/$u")
  install -m 644 "$D/systemd/$u" "/etc/systemd/system/$u"
done
systemctl daemon-reload

echo "[3/5] clave de firma (no se muestra)"
KEY=/etc/fs-master/keys/m1.key
if [[ ! -s "$KEY" ]]; then ( umask 077; openssl rand -hex 32 > "$KEY" ); UNDO+=("rm -f '$KEY'"); fi
chown root:root "$KEY"; chmod 600 "$KEY"
AK=/etc/fs-master/authorized_keys
if [[ ! -e "$AK" ]]; then : > "$AK"; UNDO+=("rm -f '$AK'"); fi
chown root:root "$AK"; chmod 644 "$AK"
if [[ -n "${MASTER_PUBKEY:-}" ]]; then
  [[ "$MASTER_PUBKEY" =~ ^(ssh-ed25519|ssh-rsa|ecdsa-sha2-[a-z0-9]+)\ [A-Za-z0-9+/=]+(\ [^[:cntrl:]]*)?$ ]] || { echo "MASTER_PUBKEY con formato invalido" >&2; exit 1; }
  LINE="${MASTER_IP:+from=\"$MASTER_IP\",}restrict $MASTER_PUBKEY"
  grep -qxF "$LINE" "$AK" || printf '%s\n' "$LINE" >> "$AK"
fi

echo "[4/5] sshd: solo SFTP enjaulado para fs-master"
UNDO+=("sshd_reload")   # se registra primero: al deshacer corre el ultimo, despues de quitar el fragmento
if grep -qE '^\s*Include\s+/etc/ssh/sshd_config\.d/' /etc/ssh/sshd_config; then
  CONF=/etc/ssh/sshd_config.d/fs-master.conf
  if [[ -e "$CONF" ]]; then cp -p "$CONF" "$CONF.prev"; UNDO+=("mv -f '$CONF.prev' '$CONF'"); else UNDO+=("rm -f '$CONF'"); fi
  install -m 644 "$D/sshd-fs-master.conf.example" "$CONF"
elif ! grep -q '^# fs-master-begin' /etc/ssh/sshd_config; then   # sin Include: el bloque Match debe ir al FINAL
  SSHD_BACKUP=/etc/ssh/sshd_config.fs-master.bak
  cp -p /etc/ssh/sshd_config "$SSHD_BACKUP"
  { echo '# fs-master-begin'; grep -v '^#' "$D/sshd-fs-master.conf.example"; echo '# fs-master-end'; } >> /etc/ssh/sshd_config
fi
sshd -t                                                                   # si falla: el trap restaura y NO se recarga
EFF="$(sshd -T -C user=fs-master,host=x,addr=127.0.0.1 2>/dev/null || true)"
grep -qi '^chrootdirectory /var/lib/fs-master/sftp' <<<"$EFF" || { echo "el Match de fs-master no se aplica" >&2; false; }
OTHER="$(sshd -T -C user=ploi,host=x,addr=127.0.0.1 2>/dev/null || true)"
grep -qi '^chrootdirectory none' <<<"$OTHER" || { echo "el Match afectaria a otros usuarios" >&2; false; }
sshd_reload
SSHD_BACKUP=""                                                            # ya validado y recargado

echo "[5/5] release por defecto: $TAG"
printf '%s\n' "$TAG" > /etc/fs-master/default-release; chmod 644 /etc/fs-master/default-release
if [[ ! -f "$REL/$TAG.tar.gz" || ! -f "$REL/$TAG.tar.gz.sha256" ]]; then
  if [[ -n "${RELEASE_FROM:-}" && -f "$RELEASE_FROM" ]]; then
    install -m 640 -o ploi -g ploi "$RELEASE_FROM" "$REL/$TAG.tar.gz"
    ( cd "$REL" && sha256sum "$TAG.tar.gz" > "$TAG.tar.gz.sha256" && chown ploi:ploi "$TAG.tar.gz.sha256" )
    echo "  publicado $REL/$TAG.tar.gz desde RELEASE_FROM"
  else
    echo "  AVISO: falta $REL/$TAG.tar.gz (+.sha256). Publicar relanzando fs-deploy.yml con ese tag, o repetir con RELEASE_FROM=<ruta a un fs-release.tar.gz>" >&2
    echo "         artefactos recientes: $(ls -1dt "$REL"/*/ 2>/dev/null | head -3 | tr '\n' ' ')" >&2
  fi
fi
trap - ERR
NKEYS="$(grep -c . "$AK" || true)"
cat <<MSG

Instalado. NADA activado: el temporizador sigue parado.
  clave de firma .......... /etc/fs-master/keys/m1.key (root 600; NO mostrada: copiarla al gestor de secretos del panel con 'sudo cat' en consola segura)
  claves SFTP del panel ... /etc/fs-master/authorized_keys ($NKEYS clave(s); si 0: anadir la publica del panel con from= y restrict)
  cola .................... /var/lib/fs-master/sftp/{requests,responses}
  siguiente paso .......... sudo bash $D/verificar.sh   (y, solo con OK del coordinador, systemctl enable --now fs-master-agent.timer)
MSG
