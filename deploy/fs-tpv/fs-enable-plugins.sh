#!/usr/bin/env bash
# Activa plugins de FS en un tenant ya instalado, por la pantalla AdminPlugins y SIEMPRE por HTTPS local (--resolve + -k) o HTTP si aun no hay TLS.
# Lo usa el paso `plugins` del agente MASTER (instalar-todo.sh lo instala como /usr/local/sbin/fs-enable-plugins). Root. Idempotente:
# activar un plugin ya activo no cambia nada. El agente comprueba despues MyFiles/plugins.json; este script solo sale != 0 si no puede iniciar sesion.
#   fs-enable-plugins <slug> <Plugin> [<Plugin>...]
set -Eeuo pipefail
SLUG="${1:?slug}"; shift; [[ $# -gt 0 ]] || { echo "faltan plugins" >&2; exit 2; }
[[ "$SLUG" =~ ^[a-z0-9]([a-z0-9-]{0,26}[a-z0-9])$ ]] || { echo slug invalido >&2; exit 1; }
for PL in "$@"; do [[ "$PL" =~ ^[A-Za-z][A-Za-z0-9]{1,60}$ ]] || { echo "plugin invalido: $PL" >&2; exit 1; }; done
SEC="/etc/fs/tenants/$SLUG.secrets"; BASE="/srv/fs/$SLUG"
DOMAIN="$(grep -m1 -oE 'server_name [^;]+' "/etc/nginx/sites-available/fs-$SLUG.conf" | cut -d' ' -f2)"
[[ -f "$SEC" && -n "$DOMAIN" ]] || { echo "sin secretos o vhost de $SLUG" >&2; exit 1; }
. "$SEC"
CJ="$(mktemp)"; trap 'rm -f "$CJ"' EXIT; trap 'echo "[fs-enable-plugins] fallo en la linea $LINENO" >&2' ERR
if [[ -d "/etc/letsencrypt/live/fs-$SLUG" ]]; then B="https://$DOMAIN"; RES=(-k --resolve "$DOMAIN:443:127.0.0.1"); else B="http://127.0.0.1"; RES=(); fi
c() { curl -s -m 900 "${RES[@]}" -H "Host: $DOMAIN" -c "$CJ" -b "$CJ" "$@"; }
tok() { grep -oE 'multireqtoken(=|" value=")[^"&]+' | head -1 | sed -E 's/.*(=|value=")//'; }
T=$(c "$B/login" | tok)
[[ -n "$T" ]] || { echo "sin token en $B/login (HTTP $(c -o /dev/null -w '%{http_code}' "$B/login"))" >&2; exit 1; }
T=$(c -L --data-urlencode "multireqtoken=$T" -d "action=login&fsNick=$FS_ADMIN&fsPassword=$FS_ADMIN_PASS" "$B/login" | tok)
[[ -n "$T" ]] || { echo "inicio de sesion rechazado" >&2; exit 1; }
for PASS in 1 2; do                                   # 2 pasadas: un plugin con dependencias activa en la segunda lo que falto en la primera
  for PL in "$@"; do
    T=$(c "$B/AdminPlugins" | tok)
    c -o /dev/null --data-urlencode "multireqtoken=$T" -d "action=enable&plugin=$PL" "$B/AdminPlugins" || true
  done
done
rm -rf "$BASE"/shared/MyFiles/Tmp/FileCache/* 2>/dev/null || true
echo "[fs-$SLUG] activacion pedida: $*"
