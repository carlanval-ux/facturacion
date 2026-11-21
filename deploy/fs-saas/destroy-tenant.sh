#!/usr/bin/env bash
# SOLO tenants test-*: elimina contenedores, volúmenes y secretos. PURGE_BACKUPS=1 borra también sus copias.
. "$(dirname "$0")/lib.sh"; need_tenant "${1:-}"
[[ $TENANT == test-* ]] || { echo "ERROR: destroy-tenant solo acepta slugs test-*" >&2; exit 2; }
if [ -f "$SDIR/compose.env" ]; then dc down -v --remove-orphans >/dev/null 2>&1 || true; fi
rm -rf "$SDIR"
[ "${PURGE_BACKUPS:-0}" = 1 ] && rm -rf "${BACKUP_DIR:?}/$TENANT"
log "destruido"
