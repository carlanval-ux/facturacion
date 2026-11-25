#!/usr/bin/env bash
# Actualización controlada: TENANT=x bash upgrade-tenant.sh [NUEVO_TAG]   (mismo tag = recreación simulada, FORCE_BUILD=1 reconstruye)
# copia previa -> nueva imagen -> migración (rebuild) -> healthcheck -> rollback automático (imagen + BD + MyFiles) si falla.
# FORCE_HEALTH_FAIL=1 fuerza el fallo del healthcheck (prueba del rollback).
. "$(dirname "$0")/lib.sh"; need_tenant "${1:-}"; load_env
NEW_TAG=${2:-${FS_IMAGE#fs-saas:}}; OLD_IMAGE=$FS_IMAGE; NEW_IMAGE=fs-saas:$NEW_TAG
PRE_INV=$(dbq_e 'SELECT COUNT(*) FROM facturascli')
BK=$(bash "$FSAAS_DIR/backup-tenant.sh" "$TENANT" | tail -1); log "copia previa: $BK"
ensure_image "$NEW_IMAGE"

healthy() {
  [ "${FORCE_HEALTH_FAIL:-0}" = 1 ] && [ "$1" = new ] && return 1
  [ "$(api_code)" = 200 ] && [ "$(dbq_e 'SELECT COUNT(*) FROM facturascli')" = "$PRE_INV" ]
}
set_image() { sed -i "s|^FS_IMAGE=.*|FS_IMAGE=$1|" "$SDIR/compose.env"; FS_IMAGE=$1; }

set_image "$NEW_IMAGE"
if dc up -d --no-deps --force-recreate fs >/dev/null && wait_http && fs_rebuild && healthy new; then
  log "upgrade OK -> $NEW_IMAGE"; exit 0
fi
log "FALLO tras actualizar: rollback a $OLD_IMAGE y a la copia $BK"
set_image "$OLD_IMAGE"
dc up -d --no-deps --force-recreate fs >/dev/null; wait_http
FORCE_OVERWRITE=1 KEEP_CREDENTIALS=1 bash "$FSAAS_DIR/restore-tenant.sh" "$TENANT" "$BK"
healthy old || die "rollback hecho pero el healthcheck sigue fallando: intervención manual"
log "rollback OK"; exit 3
