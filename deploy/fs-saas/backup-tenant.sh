#!/usr/bin/env bash
# Copia consistente: TENANT=x [BACKUP_DIR=..] [KEEP=7] bash backup-tenant.sh  -> imprime la ruta de la copia (última línea)
. "$(dirname "$0")/lib.sh"; need_tenant "${1:-}"; load_env
KEEP=${KEEP:-7}; [[ $KEEP =~ ^[0-9]+$ && $KEEP -ge 1 ]] || die "KEEP >= 1"
D=$BACKUP_DIR/$TENANT/$(date -u +%Y%m%dT%H%M%SZ); P=$D.partial; mkdir -p "$P"
dc exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers facturascripts' | gzip > "$P/db.sql.gz"
gzip -t "$P/db.sql.gz"; zcat "$P/db.sql.gz" | tail -1 | grep -q 'Dump completed' || die "volcado incompleto"
dc exec -T fs tar -C /var/www/html/MyFiles --exclude=./Tmp -czf - . > "$P/myfiles.tar.gz"
gzip -t "$P/myfiles.tar.gz"
(cd "$P" && sha256sum db.sql.gz myfiles.tar.gz > SHA256SUMS)
VER=$(dc exec -T fs php -r 'require "/var/www/html/vendor/autoload.php"; echo FacturaScripts\Core\Kernel::version();' 2>/dev/null || echo unknown)
TABLES=$(dbq_e "SELECT table_name FROM information_schema.tables WHERE table_schema='facturascripts' ORDER BY 1" | jq -R . | jq -s .)
INV=$(dbq_e 'SELECT COUNT(*) FROM facturascli' || echo 0)
H1=$(grep db.sql.gz "$P/SHA256SUMS" | cut -d' ' -f1); H2=$(grep myfiles "$P/SHA256SUMS" | cut -d' ' -f1)
jq -n --arg t "$TENANT" --arg d "$(date -u +%FT%TZ)" --arg v "$VER" --arg img "$FS_IMAGE" --argjson tables "$TABLES" \
  --argjson inv "$INV" --arg h1 "$H1" --arg h2 "$H2" \
  '{tenant:$t,date:$d,fs_version:$v,image:$img,tables:$tables,table_count:($tables|length),invoices:$inv,sha256:{"db.sql.gz":$h1,"myfiles.tar.gz":$h2}}' > "$P/manifest.json"
mv "$P" "$D"
# retención: conserva las KEEP más recientes de este tenant
ls -1d "$BACKUP_DIR/$TENANT"/*/ 2>/dev/null | sort | head -n -"$KEEP" | while read -r old; do rm -rf "$old"; done
log "copia OK ($(du -sh "$D" | cut -f1))"; echo "$D"
