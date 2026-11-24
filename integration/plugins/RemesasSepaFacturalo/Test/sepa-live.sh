#!/usr/bin/env bash
# Prueba en vivo de RemesasSepaFacturalo en teleco-test (NUNCA producción) por API REST y web; sin CLI de PHP.
# Uso: ssh ploi@host 'bash -s' < sepa-live.sh
#   Opcional (pantallas web, PDF, XML, crear remesa):  WEB_NICK=<admin de pruebas> WEB_PASS=<clave>
#   Opcional (remesa->cobro->reversión): IDRECIBO=<recibo PENDIENTE de una factura de PRUEBA no registrada en Verifactu>
# Lee FS_API_KEY de ~/fs-install/teleco-test.secrets. No imprime IBAN completos, claves ni tokens. Limpia lo que crea.
set -u
H=${H:-facturalo.redtelecom.app}
SECRETS=~/fs-install/teleco-test.secrets
[ -f "$SECRETS" ] && . "$SECRETS"
: "${FS_API_KEY:?falta FS_API_KEY en $SECRETS}"
ok=0; ko=0; chk() { if [ "$2" = "1" ]; then echo "PASS  $1"; ok=$((ok+1)); else echo "FAIL  $1"; ko=$((ko+1)); fi; }
R() { curl -s --resolve $H:443:127.0.0.1 "$@"; }
API() { m=$1; p=$2; shift 2; R -X "$m" -H "Token: $FS_API_KEY" "https://$H/api/3/$p" "$@"; }
code() { m=$1; p=$2; shift 2; R -o /dev/null -w "%{http_code}" -X "$m" -H "Token: $FS_API_KEY" "https://$H/api/3/$p" "$@"; }
jget() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; $d=is_array($d)&&isset($d[0])&&!isset($d[$k])?$d[0]:$d; echo is_array($d)&&isset($d[$k])?(is_bool($d[$k])?($d[$k]?"1":"0"):$d[$k]):"";' "$1"; }
HOY=$(date +%d-%m-%Y)
IBAN_OK=ES7921000813610123456789
CREO_ACREEDOR=0

echo "== API: recursos del módulo"
for r in acreedorsepas mandatosepas remesasepas remesaseparecibos; do
  chk "API $r responde 200" $([ "$(code GET "$r?limit=1")" = 200 ] && echo 1 || echo 0)
done

echo "== acreedor"
ACR=$(API GET "acreedorsepas?filter\[idempresa\]=1&limit=1" | jget idacreedor)
if [ -z "$ACR" ]; then
  ACR=$(API POST acreedorsepas -d idempresa=1 -d "nombre=Empresa de prueba" -d aci=ES98ZZZ12345678A -d iban=ES9121000418450200051332 -d plazofrst=5 -d plazorcur=1 -d plazob2b=1 | jget idacreedor)
  CREO_ACREEDOR=1
fi
chk "acreedor disponible" $([ -n "$ACR" ] && echo 1 || echo 0)
chk "acreedor con ACI invalido se rechaza" $([ "$(code POST acreedorsepas -d idempresa=999 -d nombre=X -d aci=1234 -d iban=ES9121000418450200051332)" != 200 ] && echo 1 || echo 0)

echo "== cliente y mandato"
COD=$(API POST clientes -d "nombre=SEPA QA" -d cifnif=B00000000 | jget codcliente)
chk "cliente de prueba creado" $([ -n "$COD" ] && echo 1 || echo 0)
MAN=$(API POST mandatosepas -d codcliente="$COD" -d iban=$IBAN_OK -d fechafirma="$HOY" -d tipo=CORE -d secuencia=FRST -d estado=activo | jget idmandato)
chk "mandato con IBAN valido guardado" $([ -n "$MAN" ] && echo 1 || echo 0)
REF=$(API GET "mandatosepas/$MAN" | jget referencia)
chk "referencia generada (<=35)" $([ -n "$REF" ] && [ ${#REF} -le 35 ] && echo 1 || echo 0)
chk "mandato con IBAN malo se rechaza" $([ "$(code POST mandatosepas -d codcliente="$COD" -d iban=ES0000000000000000000000 -d fechafirma="$HOY" -d tipo=CORE -d secuencia=FRST -d estado=activo)" != 200 ] && echo 1 || echo 0)
chk "mandato con tipo invalido se rechaza" $([ "$(code POST mandatosepas -d codcliente="$COD" -d iban=$IBAN_OK -d fechafirma="$HOY" -d tipo=XX -d secuencia=FRST -d estado=activo)" != 200 ] && echo 1 || echo 0)

WEBOK=0; JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
if [ -n "${WEB_NICK:-}" ] && [ -n "${WEB_PASS:-}" ]; then
  echo "== web (sesión de $WEB_NICK)"
  T=$(R -c "$JAR" "https://$H/login" | grep -o 'name="multireqtoken" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
  R -b "$JAR" -c "$JAR" -o /dev/null -X POST "https://$H/login" --data-urlencode "fsNick=$WEB_NICK" --data-urlencode "fsPassword=$WEB_PASS" -d action=login -d "multireqtoken=$T"
  P=$(R -b "$JAR" "https://$H/ListRemesaSepa")
  if echo "$P" | grep -q "ListReciboPendienteSepa"; then WEBOK=1; fi
  chk "pantalla ListRemesaSepa carga con sus pestañas" $WEBOK
  chk "ListRemesaSepa sin la palabra FacturaScripts" $(echo "$P" | grep -qi "facturascripts" && echo 0 || echo 1)
  if [ $WEBOK = 1 ]; then
    PDF=$(R -b "$JAR" "https://$H/EditMandatoSepa?code=$MAN&action=sepa-mandato-pdf" | head -c 4)
    chk "PDF del mandato (%PDF)" $([ "$PDF" = "%PDF" ] && echo 1 || echo 0)
    chk "ficha del mandato carga" $([ "$(R -b "$JAR" -o /dev/null -w '%{http_code}' "https://$H/EditMandatoSepa?code=$MAN")" = 200 ] && echo 1 || echo 0)
    chk "ficha del cliente carga con pestaña de mandatos" $(R -b "$JAR" "https://$H/EditCliente?code=$COD" | grep -q ListMandatoSepa && echo 1 || echo 0)
  fi
fi

if [ "$WEBOK" = 1 ] && [ -n "${IDRECIBO:-}" ]; then
  echo "== remesa de extremo a extremo (recibo $IDRECIBO)"
  RCL=$(API GET "reciboclientes/$IDRECIBO" | jget codcliente)
  # el mandato de prueba pasa al cliente del recibo
  API PUT "mandatosepas/$MAN" -d codcliente="$RCL" >/dev/null
  T=$(R -b "$JAR" "https://$H/ListRemesaSepa" | grep -o 'name="multireqtoken" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
  R -b "$JAR" -o /dev/null -X POST "https://$H/ListRemesaSepa" -d action=sepa-crear-remesa -d activetab=ListReciboPendienteSepa -d "codes[]=$IDRECIBO" -d "multireqtoken=$T"
  LIN=$(API GET "remesaseparecibos?filter\[idrecibo\]=$IDRECIBO&limit=1")
  LID=$(echo "$LIN" | jget id); REM=$(echo "$LIN" | jget idremesa)
  chk "remesa creada con el recibo" $([ -n "$LID" ] && echo 1 || echo 0)
  if [ -n "$LID" ]; then
    XML=$(R -b "$JAR" "https://$H/EditRemesaSepa?code=$REM&action=sepa-xml")
    chk "XML pain.008.001.02 descargable" $(echo "$XML" | grep -q 'pain.008.001.02' && echo 1 || echo 0)
    chk "XML con SeqTp y MndtId" $(echo "$XML" | grep -q '<SeqTp>' && echo "$XML" | grep -q "<MndtId>$REF" && echo 1 || echo 0)
    chk "devolucion sin motivo rechazada" $([ "$(code PUT "remesaseparecibos/$LID" -d estado=devuelto)" != 200 ] && echo 1 || echo 0)
    chk "cobro confirmado" $([ "$(code PUT "remesaseparecibos/$LID" -d estado=cobrado)" = 200 ] && echo 1 || echo 0)
    chk "recibo marcado pagado" $([ "$(API GET "reciboclientes/$IDRECIBO" | jget pagado)" = 1 ] && echo 1 || echo 0)
    chk "remesa cerrada" $([ "$(API GET "remesasepas/$REM" | jget estado)" = cerrada ] && echo 1 || echo 0)
    # reversión
    API PUT "reciboclientes/$IDRECIBO" -d pagado=0 >/dev/null
    API DELETE "remesaseparecibos/$LID" >/dev/null; API DELETE "remesasepas/$REM" >/dev/null
    chk "recibo vuelve a pendiente" $([ "$(API GET "reciboclientes/$IDRECIBO" | jget pagado)" = 0 ] && echo 1 || echo 0)
  fi
fi

echo "== limpieza"
[ -n "$MAN" ] && API DELETE "mandatosepas/$MAN" >/dev/null
[ -n "$COD" ] && API DELETE "clientes/$COD" >/dev/null
[ "$CREO_ACREEDOR" = 1 ] && [ -n "$ACR" ] && API DELETE "acreedorsepas/$ACR" >/dev/null
echo "RESULT ok=$ok ko=$ko"
[ "$ko" = 0 ]
