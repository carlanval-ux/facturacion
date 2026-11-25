# Funciones comunes FS-002. Se ejecuta con bash dentro de WSL (o Linux). Nunca imprime secretos.
set -euo pipefail
umask 077
FSAAS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO=$(cd "$FSAAS_DIR/../.." && pwd)
winpath() { local p=${1//\\//}; if [[ $p =~ ^([A-Za-z]):/(.*)$ ]]; then echo "/mnt/${BASH_REMATCH[1],,}/${BASH_REMATCH[2]}"; else echo "$p"; fi; }
SECRETS_DIR=$(winpath "${SECRETS_DIR:-D:/temp/fs-saas-secrets}")
BACKUP_DIR=$(winpath "${BACKUP_DIR:-D:/temp/fs-saas-backups}")
IMAGE_TAG=${IMAGE_TAG:-local}

die() { echo "ERROR: $*" >&2; exit 1; }
log() { echo "[$(date +%H:%M:%S)] ${TENANT:-fs-saas}: $*" >&2; }

need_tenant() {
  TENANT=${TENANT:-${1:-}}
  [[ $TENANT =~ ^[a-z0-9-]{3,30}$ ]] || die "TENANT debe cumplir [a-z0-9-]{3,30}"
  SDIR=$SECRETS_DIR/$TENANT; PROJ=fs-$TENANT
}
load_env() { [ -f "$SDIR/compose.env" ] || die "tenant $TENANT no existe (sin $SDIR/compose.env)"; set -a; . "$SDIR/compose.env"; set +a; }
dc() { docker compose -p "$PROJ" -f "$FSAAS_DIR/docker-compose.tenant.yml" --env-file "$SDIR/compose.env" "$@"; }
# SQL por stdin contra la BD del tenant (la contraseña root la lee el propio contenedor de su entorno)
dbq() { dc exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -B "$@"' sh "$@"; }
dbq_e() { printf '%s' "$1" | dbq facturascripts 2>/dev/null; }
rand() { openssl rand -hex "${1:-16}"; }
baseurl() { echo "http://127.0.0.1:$FS_PORT"; }

alloc_port() {
  local h p i; h=$(( $(printf %s "$TENANT" | cksum | cut -d' ' -f1) % 100 ))
  for i in $(seq 0 99); do
    p=$((8100 + (h + i) % 100))
    grep -qs "^FS_PORT=$p\$" "$SECRETS_DIR"/*/compose.env && continue
    ss -ltn 2>/dev/null | grep -q ":$p " && continue
    echo "$p"; return
  done
  die "sin puertos libres en 8100-8199"
}
ensure_image() {
  local img=$1
  if [ "${FORCE_BUILD:-0}" = 1 ] || ! docker image inspect "$img" >/dev/null 2>&1; then
    log "construyendo imagen $img"
    docker build -q -f "$REPO/deploy/fs-poc/Dockerfile" -t "$img" "$REPO" >/dev/null
  fi
}
wait_db() { local i; for i in $(seq 1 90); do dbq_e 'SELECT 1' >/dev/null && return 0; sleep 2; done; die "BD no responde"; }
wait_http() { local i; for i in $(seq 1 60); do curl -s -o /dev/null -m 5 "$(baseurl)/" && return 0; sleep 2; done; die "FS no responde en $(baseurl)"; }
# código HTTP de /api/3 con el token del tenant (o el pasado como $1)
api_code() { curl -s -o /dev/null -m 120 -w '%{http_code}' -H "Token: ${1:-$(cat "$SDIR/apikey")}" "$(baseurl)/api/3" || true; }
installed() { [ "$(dbq_e "SELECT COUNT(*) FROM api_keys WHERE description='teleco-bridge-saas'" || echo 0)" -ge 1 ] 2>/dev/null; }

# config.php y .htaccess los escribe la instalación DENTRO del contenedor (no están en volumen): al recrearlo se pierden y FS vuelve al instalador.
# Se conserva una copia de config.php en $SDIR (contiene secretos). Sin copia (restore en tenant nuevo) se regenera con el instalador desatendido.
fs_ensure_config() {
  if ! dc exec -T fs test -f /var/www/html/config.php </dev/null; then
    if [ -s "$SDIR/config.php" ]; then
      dc cp "$SDIR/config.php" fs:/var/www/html/config.php
    else
      curl -s -m 300 -X POST "$(baseurl)/" --data-urlencode "fs_db_pass=$DB_ROOT_PASS" \
        -d "unattended=1&fs_db_host=db&fs_db_name=facturascripts&fs_db_user=root&fs_db_port=3306&fs_db_type=mysql&fs_initial_user=admin&fs_lang=es_ES&fs_timezone=Europe/Madrid" \
        --data-urlencode "fs_initial_pass=$ADMIN_PASS" | grep -q OK || die "el instalador desatendido no generó config.php"
    fi
  fi
  dc exec -T fs sh -c 'chown www-data:www-data config.php; [ -f .htaccess ] || cp htaccess-sample .htaccess; chown www-data:www-data .htaccess' </dev/null
  [ -s "$SDIR/config.php" ] || { dc cp fs:/var/www/html/config.php "$SDIR/config.php"; chmod 600 "$SDIR/config.php" 2>/dev/null || true; }
}
# reconstruye Dinamic (plugins) y migra tablas. El directorio deploy/ de la imagen colisiona con la ruta /deploy de FS (301): se retira del contenedor
fs_rebuild() {
  fs_ensure_config
  dc exec -T fs sh -c 'rm -rf Dinamic MyFiles/Tmp MyFiles/routes.json deploy integration' </dev/null
  curl -s -o /dev/null -m 600 "$(baseurl)/deploy"
  local i; for i in $(seq 1 10); do [ "$(api_code)" = 200 ] && return 0; sleep 5; done
  return 1
}
