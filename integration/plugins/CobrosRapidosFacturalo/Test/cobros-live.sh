#!/usr/bin/env bash
# Prueba en vivo de CobrosRapidosFacturalo en teleco-test (NUNCA redtelecom). No imprime secretos.
# Uso: ssh ploi@host 'bash -s' < cobros-live.sh
# Facturas y cliente por API REST (el CLI como ploi no puede escribir MyFiles); el cobro/anulación por los
# controladores web con sesión de un admin de prueba; el CLI solo LEE (estado de recibos/pagos/registro).
# Limpia usuario y clave de prueba. Las facturas/cobros de prueba (cliente 'ZZ cobros') quedan como evidencia de auditoría.
set -u
H=facturalo.redtelecom.app
DIR=/srv/fs/teleco-test/current
S=$(openssl rand -hex 3)
NICK="cbtest$S"; PASS="Cb$(openssl rand -hex 6)a1"; KEY=$(openssl rand -hex 16)
J=$(mktemp); PHP=$(mktemp /tmp/cb-XXXX.php); trap 'rm -f "$J" "$PHP"' EXIT
ok=0; ko=0
chk() { if [ "$2" = "1" ]; then echo "PASS  $1"; ok=$((ok+1)); else echo "FAIL  $1"; ko=$((ko+1)); fi; }

cat > "$PHP" <<'PHPEOF'
<?php
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
chdir($argv[1]); define('FS_FOLDER', getcwd());
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
date_default_timezone_set(Tools::config('timezone', 'Europe/Madrid'));
Kernel::init(); Plugins::init();
switch ($argv[2]) {
    case 'plugin': // 1 si CobrosRapidosFacturalo está activo
        echo Plugins::isEnabled('CobrosRapidosFacturalo') ? '1' : '0';
        break;
    case 'user': // nick pass key
        $u = new FacturaScripts\Dinamic\Model\User();
        $u->nick = $argv[3]; $u->email = $argv[3] . '@example.invalid'; $u->enabled = true; $u->admin = true;
        if (!$u->setPassword($argv[4]) || !$u->save()) { fwrite(STDERR, "no se pudo crear\n"); exit(1); }
        $k = new FacturaScripts\Dinamic\Model\ApiKey();
        $k->nick = $argv[3]; $k->apikey = $argv[5]; $k->enabled = true; $k->fullaccess = true; $k->description = 'cobros-live';
        if (!$k->save()) { fwrite(STDERR, "no se pudo crear la clave\n"); exit(1); }
        break;
    case 'recibos': // idfactura -> "idrecibo|importe|pagado|fechapago" por línea
        foreach (FacturaScripts\Dinamic\Model\ReciboCliente::all([Where::eq('idfactura', $argv[3])], ['numero' => 'ASC', 'idrecibo' => 'ASC']) as $r) {
            echo $r->idrecibo . '|' . round($r->importe, 2) . '|' . ($r->pagado ? 1 : 0) . '|' . $r->fechapago . "\n";
        }
        break;
    case 'pagada': // idfactura
        $f = new FacturaScripts\Dinamic\Model\FacturaCliente(); $f->load($argv[3]); echo $f->pagada ? '1' : '0';
        break;
    case 'saldo': // idrecibo -> suma de pagos (cobro + reversión)
        $t = 0; foreach (FacturaScripts\Dinamic\Model\PagoCliente::all([Where::eq('idrecibo', $argv[3])]) as $p) { $t += $p->importe; }
        echo round($t, 2) . '|' . FacturaScripts\Dinamic\Model\PagoCliente::count([Where::eq('idrecibo', $argv[3])]);
        break;
    case 'registro': // idrecibo accion -> "id|anulado|nick|lote" por línea
        foreach (FacturaScripts\Plugins\CobrosRapidosFacturalo\Model\CobroRegistro::all([Where::eq('idrecibo', $argv[3]), Where::eq('accion', $argv[4])], ['id' => 'ASC']) as $g) {
            echo $g->id . '|' . ($g->anulado ? 1 : 0) . '|' . $g->nick . '|' . $g->lote . "\n";
        }
        break;
    case 'cleanup': // nick key
        $u = new FacturaScripts\Dinamic\Model\User(); if ($u->load($argv[3])) { $u->delete(); }
        foreach (FacturaScripts\Dinamic\Model\ApiKey::all([Where::eq('apikey', $argv[4])]) as $k) { $k->delete(); }
        break;
}
PHPEOF
php_() { php "$PHP" "$DIR" "$@"; }
R() { curl -s --resolve $H:443:127.0.0.1 "$@"; }
api() { R -H "Token: $KEY" "$@"; }
tok() { R -b "$J" "$1" | grep -o 'name="multireqtoken" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
hoy=$(date +%d-%m-%Y); hoyiso=$(date +%Y-%m-%d); fut=$(date -d '+3 days' +%Y-%m-%d)
jid() { grep -o '"idfactura":"\?[0-9]*' | head -1 | grep -o '[0-9]*$'; }

[ "$(php_ plugin)" = "1" ] || { echo "FAIL  plugin CobrosRapidosFacturalo no activo"; exit 1; }
php_ user "$NICK" "$PASS" "$KEY" || exit 1

echo "== 0) sesión y datos (API)"
t=$(R "https://$H/login" | grep -o 'name="multireqtoken" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
c=$(R -c "$J" -o /dev/null -w "%{http_code}" -X POST "https://$H/login" -d action=login --data-urlencode "multireqtoken=$t" --data-urlencode "fsNick=$NICK" --data-urlencode "fsPassword=$PASS")
chk "login admin de prueba" $([ "$c" = "302" ] && echo 1 || echo 0)
CL=$(api -X POST "https://$H/api/3/clientes" -d "nombre=ZZ cobros $S" -d "razonsocial=ZZ cobros $S" -d "cifnif=00000000T" -d "tipoidfiscal=DNI" | grep -o '"codcliente":"[^"]*"' | head -1 | sed 's/.*:"//;s/"$//')
chk "cliente creado ($CL)" $([ -n "$CL" ] && echo 1 || echo 0)
FP=$(api "https://$H/api/3/formapagos?limit=1" | grep -o '"codpago":"[^"]*"' | head -1 | sed 's/.*:"//;s/"$//')
chk "forma de pago ($FP)" $([ -n "$FP" ] && echo 1 || echo 0)
mk() { api -X POST "https://$H/api/3/crearFacturaCliente" -d "codcliente=$CL" -d "codpago=$FP" --data-urlencode "lineas=[{\"descripcion\":\"ZZ cobro\",\"cantidad\":1,\"pvpunitario\":$1}]" | jid; }
F1=$(mk 100); F2=$(mk 200); F3=$(mk 50)
chk "3 facturas creadas ($F1 $F2 $F3)" $([ -n "$F1" ] && [ -n "$F2" ] && [ -n "$F3" ] && echo 1 || echo 0)
[ -n "$F1" ] && [ -n "$F2" ] && [ -n "$F3" ] || { php_ cleanup "$NICK" "$KEY"; echo "RESULTADO: $ok PASS, $ko FAIL"; exit 1; }
R1=$(php_ recibos "$F1"); A1=$(echo "$R1" | cut -d"|" -f2); chk "F1: un recibo pendiente (importe con IVA $A1)" $([ "$(echo "$R1" | wc -l)" = "1" ] && echo "$R1" | grep -q "|$A1|0|" && echo 1 || echo 0)
ID1=${R1%%|*}

echo "== 1) la ventana de cobro y el botón"
P=$(R -b "$J" "https://$H/CobrarFacturas?facturas=$F1")
chk "CobrarFacturas?facturas= -> pinta el recibo y el formulario" $(echo "$P" | grep -q "name=\"importe\[$ID1\]\"" && echo "$P" | grep -q 'name="codpago"' && echo 1 || echo 0)
L=$(R -b "$J" "https://$H/ListFacturaCliente"); chk "ListFacturaCliente con botón cobros-open-invoices" $(echo "$L" | grep -q 'cobros-open-invoices' && echo 1 || echo 0)
E=$(R -b "$J" "https://$H/EditFacturaCliente?code=$F1"); chk "ficha: pestaña de recibos con botón cobros-open-receipts" $(echo "$E" | grep -q "cobros-open-receipts" && echo 1 || echo 0)
T=$(tok "https://$H/ListFacturaCliente")
c=$(R -b "$J" -D - -o /dev/null -X POST "https://$H/ListFacturaCliente" -d action=cobros-open-invoices --data-urlencode "multireqtoken=$T" -d "codes[]=$F1")
chk "acción del listado redirige (cabecera Refresh) a CobrarFacturas" $(echo "$c" | grep -q 'CobrarFacturas?facturas=' && echo 1 || echo 0)

echo "== 2) cobro único"
T=$(tok "https://$H/CobrarFacturas?facturas=$F1")
cobrar() { # facturas fecha total importes... (resto: -d extra)
  f=$1; fecha=$2; shift 2
  R -b "$J" -o /dev/null -X POST "https://$H/CobrarFacturas" -d action=cobrar --data-urlencode "multireqtoken=$T" -d "facturas=$f" -d "fecha=$fecha" -d "codpago=$FP" -d "observaciones=live" "$@"
}
cobrar "$F1" "$hoyiso" -d "importe[$ID1]=$A1"
chk "F1 pagada" $([ "$(php_ pagada "$F1")" = "1" ] && echo 1 || echo 0)
chk "recibo pagado con fecha de hoy" $(php_ recibos "$F1" | grep -q "^$ID1|$A1|1|$hoy" && echo 1 || echo 0)
chk "saldo de pagos 100 con 1 pago" $([ "$(php_ saldo "$ID1")" = "$A1|1" ] && echo 1 || echo 0)
chk "auditoría con el nick" $(php_ registro "$ID1" cobro | grep -q "|0|$NICK|" && echo 1 || echo 0)

echo "== 3) parcial y controles"
R2=$(php_ recibos "$F2"); ID2=${R2%%|*}; A2=$(echo "$R2" | cut -d"|" -f2); OVER2=$(awk "BEGIN{print $A2+1}"); REST2=$(awk "BEGIN{print $A2-80}")
T=$(tok "https://$H/CobrarFacturas?facturas=$F2")
cobrar "$F2" "$fut" -d "importe[$ID2]=10"
chk "fecha futura rechazada (nada cobrado)" $(php_ recibos "$F2" | grep -q "^$ID2|$A2|0|" && echo 1 || echo 0)
T=$(tok "https://$H/CobrarFacturas?facturas=$F2")
cobrar "$F2" "$hoyiso" -d "importe[$ID2]=$OVER2"
chk "importe más que el recibo rechazado" $(php_ recibos "$F2" | grep -q "^$ID2|$A2|0|" && echo 1 || echo 0)
T=$(tok "https://$H/CobrarFacturas?facturas=$F2")
cobrar "$F2" "$hoyiso" -d "importe[$ID2]=80"
RS=$(php_ recibos "$F2")
chk "parcial: 80 pagado + $REST2 pendiente" $([ "$(echo "$RS" | wc -l)" = "2" ] && echo "$RS" | sed -n 1p | grep -q "|80|1|" && echo "$RS" | sed -n 2p | grep -q "|$REST2|0|" && echo 1 || echo 0)
chk "F2 sigue impagada" $([ "$(php_ pagada "$F2")" = "0" ] && echo 1 || echo 0)
ID2B=$(echo "$RS" | sed -n 2p | cut -d'|' -f1)

echo "== 4) lote (resto de F2 + F3 con total repartido)"
R3=$(php_ recibos "$F3"); ID3=${R3%%|*}; A3=$(echo "$R3" | cut -d"|" -f2); TOT=$(awk "BEGIN{print $REST2+$A3}"); TOTX=$(awk "BEGIN{print $TOT+1}")
T=$(tok "https://$H/CobrarFacturas?facturas=$F2,$F3")
cobrar "$F2,$F3" "$hoyiso" -d "total=$TOTX"
chk "total total de más rechazado" $(php_ recibos "$F3" | grep -q "^$ID3|$A3|0|" && echo 1 || echo 0)
T=$(tok "https://$H/CobrarFacturas?facturas=$F2,$F3")
cobrar "$F2,$F3" "$hoyiso" -d "total=$TOT"
chk "F2 y F3 pagadas" $([ "$(php_ pagada "$F2")" = "1" ] && [ "$(php_ pagada "$F3")" = "1" ] && echo 1 || echo 0)
LT1=$(php_ registro "$ID2B" cobro | cut -d'|' -f4); LT2=$(php_ registro "$ID3" cobro | cut -d'|' -f4)
chk "mismo lote en ambos registros ($LT1)" $([ -n "$LT1" ] && [ "$LT1" = "$LT2" ] && echo 1 || echo 0)

echo "== 5) anulación"
REG=$(php_ registro "$ID1" cobro | cut -d'|' -f1)
T=$(tok "https://$H/ListCobroRegistro")
R -b "$J" -o /dev/null -X POST "https://$H/ListCobroRegistro" -d action=cobros-cancel --data-urlencode "multireqtoken=$T" -d "codes[]=$REG" -d "motivo= "
chk "sin motivo no anula" $([ "$(php_ pagada "$F1")" = "1" ] && echo 1 || echo 0)
T=$(tok "https://$H/ListCobroRegistro")
R -b "$J" -o /dev/null -X POST "https://$H/ListCobroRegistro" -d action=cobros-cancel --data-urlencode "multireqtoken=$T" -d "codes[]=$REG" -d "motivo=cobrada por error"
chk "F1 impagada y recibo pendiente" $([ "$(php_ pagada "$F1")" = "0" ] && php_ recibos "$F1" | grep -q "^$ID1|$A1|0|" && echo 1 || echo 0)
chk "pago de reversión: saldo 0 con 2 pagos" $([ "$(php_ saldo "$ID1")" = "0|2" ] && echo 1 || echo 0)
chk "cobro marcado anulado y fila de anulación" $(php_ registro "$ID1" cobro | grep -q "^$REG|1|" && [ -n "$(php_ registro "$ID1" anulacion)" ] && echo 1 || echo 0)
T=$(tok "https://$H/ListCobroRegistro")
R -b "$J" -o /dev/null -X POST "https://$H/ListCobroRegistro" -d action=cobros-cancel --data-urlencode "multireqtoken=$T" -d "codes[]=$REG" -d "motivo=otra vez"
chk "no se anula dos veces (1 sola anulación)" $([ "$(php_ registro "$ID1" anulacion | wc -l)" = "1" ] && echo 1 || echo 0)

echo "== 6) limpieza"
php_ cleanup "$NICK" "$KEY"
echo "(quedan las facturas ZZ cobros $S: $F1 $F2 $F3, como evidencia)"
echo "RESULTADO: $ok PASS, $ko FAIL"; [ $ko -eq 0 ]
