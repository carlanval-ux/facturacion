#!/usr/bin/env bash
# Alta idempotente de un tenant FS. Uso: TENANT=acme-sl [COMPANY=".."] [NIF=..] [CODPAGO=CONT] [SERIE=A] [IMAGE_TAG=local] [SKIP_INSTALL=1] bash provision-tenant.sh
# Datos ficticios, solo entorno de pruebas. Secretos -> $SECRETS_DIR/<slug>/ (nunca se imprimen).
. "$(dirname "$0")/lib.sh"; need_tenant "${1:-}"
mkdir -p "$SDIR"; chmod 700 "$SECRETS_DIR" "$SDIR" 2>/dev/null || true
COMPANY=${COMPANY:-Empresa Ficticia $TENANT SL}
NIF=${NIF:-B$(printf %08d $(( $(printf %s "$TENANT" | cksum | cut -d' ' -f1) % 100000000 )))}
CODPAGO=${CODPAGO:-CONT}; SERIE=${SERIE:-A}
[[ $COMPANY =~ ^[A-Za-z0-9\ .,-]{1,60}$ ]] || die "COMPANY: solo [A-Za-z0-9 .,-]"
[[ $NIF =~ ^[A-Z0-9]{9}$ ]] || die "NIF: 9 caracteres [A-Z0-9]"
[[ $CODPAGO =~ ^[A-Z0-9]{1,10}$ && $SERIE =~ ^[A-Z0-9]{1,4}$ ]] || die "CODPAGO/SERIE no válidos"

if [ ! -f "$SDIR/compose.env" ]; then
  PORT=$(alloc_port)
  { echo "TENANT=$TENANT"; echo "FS_PORT=$PORT"; echo "FS_IMAGE=fs-saas:$IMAGE_TAG"
    echo "DB_ROOT_PASS=$(rand 16)"; echo "ADMIN_PASS=$(rand 12)"; } > "$SDIR/compose.env"
  chmod 600 "$SDIR/compose.env" 2>/dev/null || true
  log "tenant nuevo, puerto $PORT"
fi
load_env
ensure_image "$FS_IMAGE"
dc up -d >/dev/null; wait_db; wait_http

if [ "${SKIP_INSTALL:-0}" = 1 ]; then log "infraestructura lista (SKIP_INSTALL)"; echo "port=$FS_PORT"; exit 0; fi
if installed && [ -s "$SDIR/apikey" ]; then log "ya aprovisionado: nada que hacer"; echo "port=$FS_PORT"; exit 0; fi
if installed; then die "BD con API key pero sin $SDIR/apikey: usar restore o destroy"; fi

# instalación desatendida reutilizando fs-poc/bootstrap.sh (solo se sustituyen empresa y NIF ficticios)
BS=$REPO/deploy/fs-poc/bootstrap.sh
ENC=${COMPANY// /%20}
{ grep -q 'Gestoria%20Ficticia%20SL' "$BS" && grep -q 'B00000000' "$BS"; } || die "bootstrap.sh cambió: revisar sustituciones"
sed "s/Gestoria%20Ficticia%20SL/$ENC/; s/B00000000/$NIF/" "$BS" \
  | FS_URL=$(baseurl) FS_ADMIN_PASS=$ADMIN_PASS FS_DB_PASS=$DB_ROOT_PASS bash

[ "$(dbq_e "SELECT COUNT(*) FROM formaspago WHERE codpago='$CODPAGO'")" = 1 ] || die "codpago $CODPAGO no existe en el tenant"
KEY=$(rand 24); printf %s "$KEY" > "$SDIR/apikey"; chmod 600 "$SDIR/apikey" 2>/dev/null || true
{ sed "s/__KEY__/$KEY/; s/'teleco-bridge-poc'/'teleco-bridge-saas'/; s/'CONT'/'$CODPAGO'/" "$REPO/deploy/fs-poc/provision.sql"
  echo "INSERT IGNORE INTO series (codserie, descripcion) VALUES ('$SERIE','Serie $SERIE');"
  echo "UPDATE settings SET properties = JSON_SET(properties, '\$.codserie', '$SERIE', '\$.site_url', '$(baseurl)') WHERE name = 'default';"
} | dbq facturascripts
installed || die "no se creó la API key"
dc cp fs:/var/www/html/config.php "$SDIR/config.php"; chmod 600 "$SDIR/config.php" 2>/dev/null || true
fs_rebuild || log "AVISO: /api/3 no respondió 200 tras la instalación"
log "aprovisionado: $COMPANY ($NIF) en $(baseurl)"; echo "port=$FS_PORT"
