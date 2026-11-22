#!/usr/bin/env bash
# FacturaScripts por tenant en el servidor PHP del TPV (rt-tpv-prod-01: Ploi, nginx + PHP-FPM 8.5, MySQL 8.4).
# Mismo modelo que retail-pos/docs/tpv/staging/tenant-provision.sh (ADR-0002): usuario de sistema, pool PHP-FPM, BD y
# usuario MySQL propios, vhost propio. NO SE HA EJECUTADO en ningun servidor: requiere autorizacion del OWNER (produccion
# del TPV) y validar antes FS en PHP 8.5 (probado solo en 8.3). Uso (root, idempotente):
#   FS_ADMIN_EMAIL=.. ./provision-fs-tenant.sh <slug> <hostname> <fs-release.tar.gz>
# Entorno fiscal: en TEST no se instala certificado real; VERI*FACTU a AEAT produccion sigue bloqueado por Teleco.
set -Eeuo pipefail
SLUG="${1:?slug (a-z0-9-)}"; DOMAIN="${2:?hostname}"; ARTIFACT="$(readlink -f "${3:?artefacto FS .tar.gz}")"
[[ "$SLUG" =~ ^[a-z0-9]([a-z0-9-]{0,26}[a-z0-9])$ ]] || { echo "slug invalido" >&2; exit 1; }
[[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || { echo "hostname invalido" >&2; exit 1; }
[[ -f "$ARTIFACT" ]] || { echo "artefacto no encontrado" >&2; exit 1; }

PHP="${FS_PHP_VERSION:-$(grep -E '^TPV_PHP_VERSION=' /etc/tpv/host.conf 2>/dev/null | tail -1 | cut -d= -f2- || true)}"; PHP="${PHP:-8.5}"
WEB_USER="$(grep -E '^TPV_WEB_USER=' /etc/tpv/host.conf 2>/dev/null | tail -1 | cut -d= -f2- || true)"; WEB_USER="${WEB_USER:-www-data}"
U="fs-$SLUG"; DB="fs_${SLUG//-/_}"; BASE="/srv/fs/$SLUG"; CONF="/etc/fs/tenants/$SLUG.conf"
POOL="/etc/php/$PHP/fpm/pool.d/fs-$SLUG.conf"; VHOST="/etc/nginx/sites-available/fs-$SLUG.conf"; SOCK="/run/php/fs-$SLUG.sock"
CREATED=()
rollback() { for x in "${CREATED[@]:-}"; do case "$x" in
  user:*) userdel "${x#user:}" 2>/dev/null || true;; tree:*) rm -rf "${x#tree:}";; pool:*|vhost:*|conf:*) rm -f "${x#*:}";;
  db:*) mysql -uroot -e "DROP DATABASE IF EXISTS \`${x#db:}\`; DROP USER IF EXISTS '${x#db:}'@'localhost';" || true;; esac; done; }
trap 'echo "fallo: deshaciendo lo creado en esta ejecucion" >&2; rollback' ERR

# 1) usuario y arbol (lo ya existente nunca se borra)
id "$U" >/dev/null 2>&1 || { useradd --system --home "$BASE" --shell /usr/sbin/nologin "$U"; CREATED+=("user:$U"); }
[[ -d "$BASE" ]] || { install -d -o "$U" -g "$U" -m 750 "$BASE" "$BASE/tmp" "$BASE/backups" "$BASE/releases" "$BASE/shared"; CREATED+=("tree:$BASE"); }

# 2) BD y usuario MySQL con minimo privilegio sobre su propia BD
install -d -m 700 /etc/fs/tenants
if [[ ! -f "$CONF" ]]; then
  PASS="$(openssl rand -hex 24)"; ( umask 077; printf 'DB_PASS=%s\n' "$PASS" > "$CONF" ); CREATED+=("conf:$CONF")
else PASS="$(sed -n 's/^DB_PASS=//p' "$CONF")"; fi
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 CREATE USER IF NOT EXISTS '$DB'@'localhost' IDENTIFIED BY '$PASS';
 GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,DROP,INDEX,REFERENCES,LOCK TABLES,CREATE TEMPORARY TABLES ON \`$DB\`.* TO '$DB'@'localhost';"

# 3) codigo: primera release en releases/<sello>; `current` es un symlink. Las actualizaciones las hace fs-deploy-release
if [[ ! -e "$BASE/current" ]]; then
  R="$BASE/releases/$(date -u +%Y%m%d%H%M%S)-initial"
  sudo -u "$U" mkdir -p "$R" && sudo -u "$U" tar -xzf "$ARTIFACT" -C "$R" --strip-components=1
  sudo -u "$U" ln -sfn "$R" "$BASE/current"
fi

# 4) pool PHP-FPM propio, encerrado en su arbol
cat > "$POOL" <<EOF
[fs-$SLUG]
user = $U
group = $U
listen = $SOCK
listen.owner = $WEB_USER
listen.group = $WEB_USER
listen.mode = 0660
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[open_basedir] = $BASE/:/usr/share/php/
php_admin_value[upload_tmp_dir] = $BASE/tmp
php_admin_value[sys_temp_dir] = $BASE/tmp
php_admin_value[session.save_path] = $BASE/tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,popen
; PHP 8.5: FS emite avisos de imagedestroy() que corrompen los PDF si se muestran
php_admin_flag[display_errors] = off
php_admin_value[error_reporting] = E_ALL & ~E_DEPRECATED
php_admin_flag[log_errors] = on
EOF
[[ -f "$POOL" ]] && CREATED+=("pool:$POOL")

# 5) vhost: la API de FS (/api/3) se expone solo a Teleco; el panel de FS, no (acceso por la UI de Teleco)
cat > "$VHOST" <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $BASE/current;
    index index.php;
    client_max_body_size 16m;
    disable_symlinks if_not_owner from=\$document_root;
    add_header X-Content-Type-Options "nosniff" always;
    location ^~ /MyFiles/ { internal; }                 # los ficheros solo salen por FS (token firmado)
    location ~ ^/(Core|Dinamic|Test|node_modules|vendor)/.*\.php\$ { deny all; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_pass unix:$SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known) { deny all; }
}
EOF
ln -sf "$VHOST" "/etc/nginx/sites-enabled/fs-$SLUG.conf"; CREATED+=("vhost:$VHOST")
php-fpm"$PHP" -t && nginx -t && systemctl reload "php$PHP-fpm" nginx

# 6) FS cachea los ajustes en MyFiles/Tmp/FileCache: tras tocar la BD (api_keys, enable_api) hay que vaciarla
rm -rf "$BASE"/current/MyFiles/Tmp/FileCache/* 2>/dev/null || true

# 7) bootstrap de FS (plugin TelecoBridge, token de API con acceso minimo, modo prueba): igual que deploy/fs-poc/bootstrap.sh
echo "[fs-$SLUG] listo en $DOMAIN (TLS con certbot y bootstrap de FS: pasos manuales documentados en README.md)"
