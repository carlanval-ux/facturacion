#!/usr/bin/env bash
# Prueba E2E REAL de FS-002 (datos ficticios, solo tenants test-*). Ejecutar en WSL: bash deploy/fs-saas/test-saas.sh
. "$(dirname "$0")/lib.sh"
S=$FSAAS_DIR; PASS=0; FAILN=0; TENANT=test
chk() { if eval "$2"; then echo "PASS  $1"; PASS=$((PASS+1)); else echo "FALLO $1"; FAILN=$((FAILN+1)); fi; }
t() { TENANT=$1 bash "$S/$2-tenant.sh" "${@:3}"; }
keyof() { cat "$SECRETS_DIR/$1/apikey"; }
portof() { sed -n 's/^FS_PORT=//p' "$SECRETS_DIR/$1/compose.env"; }
code_with() { curl -s -o /dev/null -m 15 -w '%{http_code}' -H "Token: $2" "http://127.0.0.1:$(portof "$1")/api/3"; }
cleanup() { for x in test-a test-b test-a2; do PURGE_BACKUPS=1 bash "$S/destroy-tenant.sh" "$x" >/dev/null 2>&1 || true; done; }
cleanup; trap cleanup EXIT

echo "== 1. provisión de dos tenants"
OUT_A=$(COMPANY="Cliente Ficticio A SL" NIF=B11111111 TENANT=test-a bash "$S/provision-tenant.sh" 2>&1) || { echo "$OUT_A"; echo "FALLO provision test-a"; exit 1; }
OUT_B=$(COMPANY="Cliente Ficticio B SL" NIF=B22222222 CODPAGO=TRANS SERIE=B TENANT=test-b bash "$S/provision-tenant.sh" 2>&1) || { echo "$OUT_B"; echo "FALLO provision test-b"; exit 1; }
KA=$(keyof test-a); KB=$(keyof test-b)
chk "puertos distintos en 8100-8199" '[ "$(portof test-a)" != "$(portof test-b)" ] && [ "$(portof test-a)" -ge 8100 ] && [ "$(portof test-b)" -le 8199 ]'
chk "solo loopback" '[ "$(docker port fs-test-a-fs-1 80/tcp | head -1)" = "127.0.0.1:$(portof test-a)" ]'
chk "provision no imprime secretos" '! printf "%s%s" "$OUT_A" "$OUT_B" | grep -qF -e "$KA" -e "$KB" -e "$(sed -n s/^DB_ROOT_PASS=//p "$SECRETS_DIR/test-a/compose.env")"'
H1=$(sha256sum "$SECRETS_DIR/test-a/compose.env" "$SECRETS_DIR/test-a/apikey" | cut -d' ' -f1 | tr -d '\n')
OUT_R=$(TENANT=test-a bash "$S/provision-tenant.sh" 2>&1) || true
chk "re-provision idempotente (secretos intactos, no reinstala)" '[ "$H1" = "$(sha256sum "$SECRETS_DIR/test-a/compose.env" "$SECRETS_DIR/test-a/apikey" | cut -d" " -f1 | tr -d "\n")" ] && echo "$OUT_R" | grep -q "ya aprovisionado"'
chk "empresa/serie/codpago propios (B)" '[ "$(TENANT=test-b; need_tenant; load_env; dbq_e "SELECT CONCAT(e.nombre,\"|\",e.cifnif,\"|\",JSON_VALUE(s.properties,\"\$.codserie\"),\"|\",JSON_VALUE(s.properties,\"\$.codpago\")) FROM empresas e, settings s WHERE s.name=\"default\" LIMIT 1")" = "Cliente Ficticio B SL|B22222222|B|TRANS" ]'

echo "== 2. aislamiento"
chk "A con su key = 200, B con su key = 200" '[ "$(code_with test-a "$KA")" = 200 ] && [ "$(code_with test-b "$KB")" = 200 ]'
chk "key de A en B = 401/403" '[[ "$(code_with test-b "$KA")" =~ ^40[13]$ ]]'
chk "key de B en A = 401/403" '[[ "$(code_with test-a "$KB")" =~ ^40[13]$ ]]'
chk "volúmenes y redes propios" '[ "$(docker volume ls -q | grep -c "^fs-test-[ab]-")" = 4 ] && [ "$(docker network ls -q --filter name=fs-test-a | wc -l)" = 1 ]'

echo "== 3. factura idempotente en A"
PA=$(portof test-a); U="http://127.0.0.1:$PA/api/3"
CLI=$(curl -s -H "Token: $KA" -d "nombre=Cliente Prueba Ficticio" -d "cifnif=X0000000T" "$U/clientes" | jq -r '.data.codcliente // empty')
chk "cliente creado en A" '[ -n "$CLI" ]'
inv() { curl -s -D /tmp/fsh.$$ -H "Token: $KA" -H "Idempotency-Key: $1" -d "codcliente=$CLI" -d "codserie=A" \
  --data-urlencode 'lineas=[{"descripcion":"Servicio ficticio","cantidad":1,"pvpunitario":100,"codimpuesto":"IVA21"}]' "$U/telecoFacturaCliente"; }
R1=$(inv test-key-0001); COD=$(echo "$R1" | jq -r '.doc.codigo // empty')
chk "factura creada con codigo ($COD)" '[ -n "$COD" ] && ! grep -qi "^idempotent-replay" /tmp/fsh.$$'
chk "cliente de A no existe en B" '[ "$(TENANT=test-b; need_tenant; load_env; dbq_e "SELECT COUNT(*) FROM clientes WHERE cifnif=\"X0000000T\"")" = 0 ]'

echo "== 4. backup de A + monitor"
BK=$(t test-a backup 2>/dev/null | tail -1)
chk "backup con manifest (1 factura, sha256 ok)" '[ "$(jq -r .invoices "$BK/manifest.json")" = 1 ] && (cd "$BK" && sha256sum -c --quiet SHA256SUMS)'
set +e; MON=$(t test-a monitor 2>/dev/null); RC=$?; set -e
chk "monitor A: exit 0 y ok=true" '[ "$RC" = 0 ] && [ "$(echo "$MON" | jq .ok)" = true ]'
echo "$MON" | jq -c '{tenant,containers,api,idempotencia_pending_antiguas,volumes_mb,last_backup_age_h,ok}'
set +e; MONB=$(t test-b monitor 2>/dev/null); RCB=$?; set -e
chk "monitor B (sin copia): exit != 0, backup=false" '[ "$RCB" != 0 ] && [ "$(echo "$MONB" | jq .checks.backup)" = false ]'

echo "== 5. guardas"
set +e
RO=$(TENANT=test-b bash "$S/restore-tenant.sh" "$BK" 2>&1); RCO=$?
RD=$(TENANT=acme bash "$S/destroy-tenant.sh" 2>&1); RCD=$?
set -e
chk "restore sobre tenant con datos rechazado sin flag" '[ "$RCO" != 0 ] && echo "$RO" | grep -q FORCE_OVERWRITE'
chk "destroy rechaza slug no test- (exit 2)" '[ "$RCD" = 2 ]'

echo "== 6. destruir A y restaurar en test-a2"
t test-a destroy
chk "A destruido (sin contenedores ni volúmenes)" '! docker ps -a --format "{{.Names}}" | grep -q "^fs-test-a-" && ! docker volume ls -q | grep -q "^fs-test-a-"'
TENANT=test-a2 bash "$S/restore-tenant.sh" "$BK" 2>&1 | tail -2
K2=$(keyof test-a2); P2=$(portof test-a2); U2="http://127.0.0.1:$P2/api/3"
chk "key de a2 rotada (distinta de A) y válida" '[ "$K2" != "$KA" ] && [ "$(code_with test-a2 "$K2")" = 200 ] && [[ "$(code_with test-a2 "$KA")" =~ ^40[13]$ ]]'
chk "factura $COD existe en a2" '[ "$(curl -s -H "Token: $K2" "$U2/facturaclientes?filter%5Bcodigo%5D=$COD" | jq -r ".[0].codigo")" = "$COD" ]'
KA=$K2; U=$U2
R2=$(inv test-key-0001)
chk "misma Idempotency-Key: replay, mismo codigo" 'grep -qi "^idempotent-replay: true" /tmp/fsh.$$ && [ "$(echo "$R2" | jq -r .doc.codigo)" = "$COD" ]'
chk "sigue habiendo 1 factura" '[ "$(TENANT=test-a2; need_tenant; load_env; dbq_e "SELECT COUNT(*) FROM facturascli")" = 1 ]'
set +e; R3=$(curl -s -H "Token: $K2" -H "Idempotency-Key: test-key-0001" -d "codcliente=$CLI" -d 'lineas=[]' "$U2/telecoFacturaCliente"); set -e
chk "misma clave con cuerpo distinto: 422" 'echo "$R3" | grep -q different-body'

echo "== 7. upgrade (mismo tag) y rollback"
set +e; UP=$(t test-a2 upgrade 2>&1); RCU=$?; set -e
echo "$UP" | tail -2
chk "upgrade simulado OK + healthcheck 200" '[ "$RCU" = 0 ] && [ "$(code_with test-a2 "$K2")" = 200 ]'
set +e; UP=$(FORCE_HEALTH_FAIL=1 t test-a2 upgrade 2>&1); RCU=$?; set -e
echo "$UP" | tail -3
chk "fallo forzado -> rollback automático (exit 3), API 200 y 1 factura" '[ "$RCU" = 3 ] && [ "$(code_with test-a2 "$K2")" = 200 ] && [ "$(TENANT=test-a2; need_tenant; load_env; dbq_e "SELECT COUNT(*) FROM facturascli")" = 1 ]'
MON2=$(t test-a2 backup >/dev/null 2>&1; t test-a2 monitor 2>/dev/null)
chk "monitor a2 final ok" '[ "$(echo "$MON2" | jq .ok)" = true ]'

echo "== 8. limpieza"
cleanup
chk "sin restos test-*" '! docker ps -a --format "{{.Names}}" | grep -q "^fs-test-" && ! docker volume ls -q | grep -q "^fs-test-" && [ -z "$(ls "$SECRETS_DIR" 2>/dev/null | grep ^test-)" ]'
chk "PoC FS-001 intacto" 'docker ps --format "{{.Names}}" | grep -q "^fs-poc-fs-1$"'
echo "RESULTADO: $PASS PASS, $FAILN FALLO"; [ "$FAILN" = 0 ]
