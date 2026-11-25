#!/usr/bin/env bash
# Instala FS sin asistente en un tenant ya aprovisionado, activa TelecoBridge y crea el token de API con acceso minimo.
# Root, una vez por tenant (idempotente: si FS ya esta instalado no repite). Basado en deploy/fs-poc/bootstrap.sh.
#   fs-bootstrap-tenant <slug>      empresa FICTICIA de prueba; los datos reales se cambian en FS despues
# Secretos: /etc/fs/tenants/<slug>.secrets (root, 600). Copia para el operador de despliegue en FS_SECRETS_COPY_TO (opcional).
set -Eeuo pipefail
SLUG="${1:?slug}"; [[ "$SLUG" =~ ^[a-z0-9]([a-z0-9-]{0,26}[a-z0-9])$ ]] || { echo slug invalido >&2; exit 1; }
CONF="/etc/fs/tenants/$SLUG.conf"; SEC="/etc/fs/tenants/$SLUG.secrets"; BASE="/srv/fs/$SLUG"; DB="fs_${SLUG//-/_}"
DOMAIN="$(grep -m1 -oE 'server_name [^;]+' "/etc/nginx/sites-available/fs-$SLUG.conf" | cut -d' ' -f2)"
DBPASS="$(sed -n 's/^DB_PASS=//p' "$CONF")"; CJ="$(mktemp)"; trap 'rm -f "$CJ"' EXIT
trap 'echo "[fs-bootstrap-tenant] fallo en la linea $LINENO (codigo $?)" >&2' ERR     # antes salia con 1 sin decir nada
# Con TLS ya emitido certbot deja el puerto 80 en un 301: se entra por HTTPS local (reejecutable); sin TLS, por HTTP plano.
if [[ -d "/etc/letsencrypt/live/fs-$SLUG" ]]; then B="https://$DOMAIN"; RES=(-k --resolve "$DOMAIN:443:127.0.0.1"); else B="http://127.0.0.1"; RES=(); fi
c() { curl -s -m 900 "${RES[@]}" -H "Host: $DOMAIN" -c "$CJ" -b "$CJ" "$@"; }
tok() { grep -oE 'multireqtoken(=|" value=")[^"&]+' | head -1 | sed -E 's/.*(=|value=")//'; }
if [[ ! -f "$SEC" ]]; then ( umask 077; printf 'FS_ADMIN=admin\nFS_ADMIN_PASS=%s\nFS_API_KEY=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 24)" > "$SEC" ); fi
. "$SEC"
if c "$B/" | grep -qi "installer"; then
  RESP=$(c -X POST "$B/" -d "unattended=1&fs_db_host=localhost&fs_db_name=$DB&fs_db_user=$DB&fs_db_pass=$DBPASS&fs_db_port=3306&fs_db_type=mysql&fs_initial_user=$FS_ADMIN&fs_initial_pass=$FS_ADMIN_PASS&fs_lang=es_ES&fs_timezone=Europe/Madrid")
  if ! echo "$RESP" | grep -q OK; then
    echo "instalacion de FS fallida. Avisos: $(echo "$RESP" | grep -iE 'alert|error|denied|fail' | grep -vE 'function|\$\(' | sed 's/<[^>]*>//g' | tr -s ' ' | head -c 800)" >&2
    echo "mysql: $(MYSQL_PWD="$DBPASS" mysql -u"$DB" -hlocalhost "$DB" -e 'select 1' 2>&1 | head -c 200)" >&2; exit 1
  fi
fi
T=$(c "$B/login" | tok); T=$(c -L --data-urlencode "multireqtoken=$T" -d "action=login&fsNick=$FS_ADMIN&fsPassword=$FS_ADMIN_PASS" "$B/login" | tok)
if c "$B/Wizard" | grep -qi 'step1'; then
  R=$(c --data-urlencode "multireqtoken=$T" -d "action=step1&codpais=ESP&empresa=Gestoria%20Ficticia%20SL&direccion=Calle%20Falsa%201&ciudad=Madrid&provincia=Madrid&codpostal=28001&email=pruebas@example.invalid" "$B/Wizard"); T=$(echo "$R" | tok)
  H=$(mktemp); c -D "$H" -o /dev/null --data-urlencode "multireqtoken=$T" -d "action=step2&cifnif=B00000000&tipoidfiscal=CIF&regimeniva=General&defaultplan=1&invoice_start_number=1" "$B/Wizard"
  T=$(grep -i '^refresh' "$H" | sed -E 's/.*multireqtoken=//' | tr -d '\r\n'); rm -f "$H"
  c -o /dev/null "$B/Wizard?action=step3&multireqtoken=$T"
fi
# FS_EXTRA_PLUGINS (opcional, separados por espacio): plugins adicionales solo para este tenant; ver integration/plugins/PLUGINS-GRATUITOS.md
for PL in TelecoBridge Facturalo ${FS_EXTRA_PLUGINS:-}; do T=$(c "$B/AdminPlugins" | tok); c -o /dev/null --data-urlencode "multireqtoken=$T" -d "action=enable&plugin=$PL" "$B/AdminPlugins"; done
mysql -uroot "$DB" <<SQL
UPDATE settings SET properties = JSON_SET(properties, '\$.enable_api', true, '\$.codpago', 'CONT') WHERE name = 'default';
DELETE FROM api_keys WHERE description = 'teleco-bridge';
INSERT INTO api_keys (apikey, creationdate, description, enabled, fullaccess, nick) VALUES ('$FS_API_KEY', CURDATE(), 'teleco-bridge', 1, 0, '$FS_ADMIN');
SET @id = LAST_INSERT_ID();
INSERT INTO api_access (idapikey, resource, allowget, allowpost, allowput, allowdelete) VALUES
 (@id,'clientes',1,1,1,0),(@id,'facturaclientes',1,0,0,0),(@id,'telecoFacturaCliente',0,1,0,0),
 (@id,'crearFacturaRectificativaCliente',0,1,0,0),(@id,'pagarFacturaCliente',0,1,0,0),(@id,'exportarFacturaCliente',1,0,0,0),(@id,'productos',1,1,1,0);
SQL
rm -rf "$BASE"/shared/MyFiles/Tmp/FileCache/* 2>/dev/null || true       # FS cachea ajustes y api_keys
[[ -d "/etc/letsencrypt/live/fs-$SLUG" ]] || certbot --nginx -d "$DOMAIN" --cert-name "fs-$SLUG" --non-interactive --agree-tos --register-unsafely-without-email --redirect >&2 || echo "AVISO: TLS no emitido (DNS/80 abierto?)" >&2
[[ -n "${SUDO_USER:-}" && -d "/home/$SUDO_USER/fs-install" ]] && install -m 600 -o "$SUDO_USER" "$SEC" "/home/$SUDO_USER/fs-install/$SLUG.secrets"   # copia para quien lanza el script con sudo
echo "[fs-$SLUG] FS instalado, TelecoBridge activo, token creado (secretos en $SEC)"
