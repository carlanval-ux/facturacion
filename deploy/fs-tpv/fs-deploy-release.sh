#!/usr/bin/env bash
# Activa una release de FacturaScripts para un tenant. Root en el host; se instala como /usr/local/sbin/fs-deploy-release
# (install-host.sh). Mismo modelo que tpv-deploy-release: checksum -> desempaquetar -> enlaces compartidos -> copia de la BD
# -> cambio atomico de `current` -> Plugins::deploy -> comprobacion de salud; si falla, vuelve a la release anterior.
#   fs-deploy-release <slug> <artefacto.tar.gz>      (el .sha256 debe estar al lado)
# Disposicion: /srv/fs/<slug>/{releases/<sello>/, shared/{config.php,MyFiles}, backups/, current -> releases/<sello>}
set -Eeuo pipefail
SLUG="${1:?slug}"; ART="$(readlink -f "${2:?artefacto}")"; KEEP="${FS_KEEP_RELEASES:-5}"
[[ "$SLUG" =~ ^[a-z0-9]([a-z0-9-]{0,26}[a-z0-9])$ ]] || { echo "slug invalido" >&2; exit 1; }
U="fs-$SLUG"; BASE="/srv/fs/$SLUG"; DB="fs_${SLUG//-/_}"; SH="$BASE/shared"
[[ -d "$BASE/releases" ]] || { echo "tenant sin aprovisionar: $BASE" >&2; exit 1; }
[[ -f "$ART" && -f "$ART.sha256" ]] || { echo "falta artefacto o .sha256" >&2; exit 1; }
( cd "$(dirname "$ART")" && sha256sum -c "$(basename "$ART").sha256" >/dev/null ) || { echo "checksum NO coincide" >&2; exit 1; }
PHP="$(grep -E '^TPV_PHP_VERSION=' /etc/tpv/host.conf 2>/dev/null | tail -1 | cut -d= -f2- || true)"; PHP="${PHP:-8.5}"
as() { sudo -u "$U" "$@"; }
PREV="$(readlink -f "$BASE/current" 2>/dev/null || true)"; R="$BASE/releases/$(date -u +%Y%m%d%H%M%S)"
trap 'echo "fallo antes del cambio: se descarta $R" >&2; rm -rf "$R"' ERR

mkdir -p "$R" "$SH" && tar -xzf "$ART" -C "$R" --strip-components=1 && chown -R "$U:$U" "$R"
rm -rf "$R/Dinamic" "$R/MyFiles" "$R/config.php"        # Dinamic se regenera; config.php y MyFiles viven en shared
# primera vez tras la instalacion de FS: config.php y MyFiles estan en la release vieja; se mueven a shared
if [[ -n "$PREV" ]]; then
  for f in config.php MyFiles; do [[ -e "$SH/$f" || -L "$PREV/$f" || ! -e "$PREV/$f" ]] || as mv "$PREV/$f" "$SH/$f"; done
  for f in config.php MyFiles; do [[ -e "$SH/$f" && ! -L "$PREV/$f" ]] && as ln -s "$SH/$f" "$PREV/$f" || true; done
fi
[[ -d "$SH/MyFiles" ]] || as mkdir -p "$SH/MyFiles"
as ln -s "$SH/MyFiles" "$R/MyFiles"; [[ -e "$SH/config.php" ]] && as ln -s "$SH/config.php" "$R/config.php"

[[ -e "$SH/config.php" ]] && mysqldump -uroot --single-transaction --routines "$DB" | gzip > "$BASE/backups/pre-$(basename "$R").sql.gz"
trap - ERR
SOCK_HOST="$(grep -m1 -oE 'server_name [^;]+' "/etc/nginx/sites-available/fs-$SLUG.conf" | cut -d' ' -f2)"
revertir() { echo "fallo tras el cambio: vuelvo a ${PREV:-ninguna}" >&2; [[ -n "$PREV" ]] && as ln -sfn "$PREV" "$BASE/current"; systemctl reload "php$PHP-fpm" || true; exit 1; }
as ln -sfn "$R" "$BASE/current"; systemctl reload "php$PHP-fpm"
rm -rf "$SH"/MyFiles/Tmp/FileCache/* 2>/dev/null || true
curl -fsSk -m 300 --resolve "$SOCK_HOST:443:127.0.0.1" "https://$SOCK_HOST/deploy" >/dev/null || revertir        # Plugins::deploy
code="$(curl -sk -o /dev/null -m 30 -w "%{http_code}" --resolve "$SOCK_HOST:443:127.0.0.1" "https://$SOCK_HOST/api/3")"
[[ "$code" =~ ^(200|401|403)$ ]] || { echo "salud: HTTP $code" >&2; revertir; }
ls -1dt "$BASE"/releases/*/ | tail -n +$((KEEP+1)) | xargs -r rm -rf
echo "[fs-$SLUG] release $(basename "$R") activa (anterior: $(basename "${PREV:-ninguna}"))"
