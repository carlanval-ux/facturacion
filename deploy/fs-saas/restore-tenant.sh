#!/usr/bin/env bash
# Restaura en tenant nuevo o vacío: TENANT=x bash restore-tenant.sh <ruta_copia>. Si el tenant tiene datos exige FORCE_OVERWRITE=1.
# Por defecto rota admin y API key (secretos nuevos en SECRETS_DIR). KEEP_CREDENTIALS=1 los conserva (rollback de upgrade).
. "$(dirname "$0")/lib.sh"
if [ $# -ge 2 ]; then need_tenant "$1"; B=$2; else need_tenant; B=${1:-${BACKUP_PATH:-}}; fi
[ -f "$B/manifest.json" ] || die "uso: TENANT=x $0 <ruta_copia>  (o: $0 <tenant> <ruta_copia>)"
(cd "$B" && sha256sum -c --quiet SHA256SUMS) || die "sha256 NO coincide: copia corrupta, no se restaura"
if [ ! -f "$SDIR/compose.env" ]; then SKIP_INSTALL=1 bash "$FSAAS_DIR/provision-tenant.sh" "$TENANT" >/dev/null; fi
load_env; dc up -d >/dev/null; wait_db; wait_http
N=$(dbq_e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='facturascripts'")
[[ $N =~ ^[0-9]+$ ]] || die "no se pudo comprobar si el tenant tiene datos"
if [ "$N" -gt 0 ]; then
  [ "${FORCE_OVERWRITE:-0}" = 1 ] || die "el tenant $TENANT tiene datos ($N tablas): se requiere FORCE_OVERWRITE=1"
  log "FORCE_OVERWRITE: se sustituye la BD existente"
fi
fs_ensure_config
printf "DROP DATABASE IF EXISTS facturascripts; CREATE DATABASE facturascripts;" | dbq
zcat "$B/db.sql.gz" | dbq facturascripts
dc exec -T fs sh -c 'find /var/www/html/MyFiles -mindepth 1 -delete'
dc exec -T fs tar -C /var/www/html/MyFiles -xzf - < "$B/myfiles.tar.gz"
dc exec -T fs chown -R www-data:www-data /var/www/html/MyFiles
if [ "${KEEP_CREDENTIALS:-0}" != 1 ]; then
  KEY=$(rand 24); printf %s "$KEY" > "$SDIR/apikey"; chmod 600 "$SDIR/apikey" 2>/dev/null || true
  HASH=$(dc exec -T -e P="$ADMIN_PASS" fs php -r 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);')
  printf "UPDATE api_keys SET apikey='%s' WHERE description='teleco-bridge-saas'; UPDATE users SET password='%s' WHERE nick='admin';" "$KEY" "$HASH" | dbq facturascripts
fi
printf "UPDATE settings SET properties = JSON_SET(properties, '\$.site_url', '%s') WHERE name='default';" "$(baseurl)" | dbq facturascripts
fs_rebuild || die "tenant restaurado pero /api/3 no responde 200"
log "restaurado desde $B ($(jq -r .invoices "$B/manifest.json") facturas en la copia)"
