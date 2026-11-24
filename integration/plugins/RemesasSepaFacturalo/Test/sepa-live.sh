#!/usr/bin/env bash
# Prueba en vivo de RemesasSepaFacturalo en teleco-test (NUNCA producción). No imprime IBAN completos.
# Uso: ssh ploi@host 'bash -s' < sepa-live.sh
#      IDRECIBO=123 ssh ploi@host 'bash -s' < ...   (opcional: recibo PENDIENTE de una factura de PRUEBA no registrada en
#      Verifactu, para probar remesa -> cobro -> reversión). Sin IDRECIBO solo se prueba lo que no toca recibos.
# Crea un cliente SEPAQA, un mandato y (si no existe) un acreedor de prueba; lo borra todo al terminar.
set -u
DIR=${DIR:-/srv/fs/teleco-test/current}
PHP=$(mktemp /tmp/sepa-XXXX.php); trap 'rm -f "$PHP"' EXIT
ok=0; ko=0
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

use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\Iban;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\MandatoPdf;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloActivo;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\RemesaService;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\SepaXml;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\AcreedorSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\MandatoSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\RemesaSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\RemesaSepaRecibo;

$n = 0; $f = 0;
function t(string $nombre, bool $c): void { global $n, $f; echo ($c ? 'PASS  ' : 'FAIL  ') . $nombre . "\n"; $c ? $n++ : $f++; }

t('modulo activo por defecto', ModuloActivo::estaActivo('sepa'));
t('IBAN valido / invalido', Iban::esValido('ES9121000418450200051332') && !Iban::esValido('ES9121000418450200051333'));

$idempresa = (int)Tools::settings('default', 'idempresa', 1);
$creoAcreedor = false;
$a = AcreedorSepa::findWhere([Where::eq('idempresa', $idempresa)]);
if (!$a) {
    $a = new AcreedorSepa();
    $a->idempresa = $idempresa; $a->nombre = 'Empresa de prueba'; $a->aci = 'ES98ZZZ12345678A';
    $a->ibannuevo = 'ES9121000418450200051332';
    t('acreedor guardado', $a->save());
    $creoAcreedor = true;
}
t('acreedor: sin faltas', AcreedorSepa::faltas($idempresa) === []);

$c = new Cliente(); $c->nombre = 'SEPA QA'; $c->cifnif = 'B00000000';
$cc = Cliente::findWhere([Where::eq('nombre', 'SEPA QA')]);
if ($cc) { $c = $cc; } else { t('cliente de prueba creado', $c->save()); }
$cod = $c->codcliente;

$m = new MandatoSepa();
$m->codcliente = $cod; $m->ibannuevo = 'ES7921000813610123456789';
t('mandato con IBAN valido guarda', $m->save());
t('mandato: referencia generada', !empty($m->referencia) && strlen($m->referencia) <= 35);
t('mandato: IBAN enmascarado', strpos(Iban::formato($m->iban), '*') !== false && strpos(Iban::formato($m->iban), '0123456789') === false);
$m2 = new MandatoSepa(); $m2->codcliente = $cod; $m2->ibannuevo = 'ES0000000000000000000000';
t('mandato con IBAN malo se rechaza', !$m2->save());
$pdf = (new MandatoPdf())->generar($m, false);
t('PDF del mandato generado', strncmp($pdf, '%PDF', 4) === 0 && strlen($pdf) > 1500);
[$r0, $e0] = RemesaService::crear([], null, 'qa');
t('remesa sin recibos rechazada', $r0 === null && $e0 !== []);

$idr = (int)getenv('IDRECIBO');
if ($idr > 0) {
    $rec = new ReciboCliente();
    if ($rec->load($idr) && !$rec->pagado) {
        $m->codcliente = $rec->codcliente; $m->save();
        [$rem, $err] = RemesaService::crear([$idr], null, 'qa');
        t('remesa creada' . ($err ? ' (' . implode(',', $err) . ')' : ''), $rem !== null);
        if ($rem) {
            t('XML guardado y valido', SepaXml::validarXml($rem->xml) === []);
            t('recibo ya no es remesable', RemesaService::crear([$idr], null, 'qa')[0] === null);
            $l = RemesaSepaRecibo::findWhere([Where::eq('idremesa', $rem->idremesa)]);
            $l->estado = 'devuelto'; t('devolucion sin motivo rechazada', !$l->save());
            $l = RemesaSepaRecibo::find($l->id); $l->estado = 'cobrado';
            t('cobro confirmado', $l->save());
            $rec->load($idr); t('recibo marcado pagado', (bool)$rec->pagado);
            t('remesa cerrada', RemesaSepa::find($rem->idremesa)->estado === 'cerrada');
            // reversión
            $rec->pagado = false; $rec->save();
            RemesaSepaRecibo::deleteWhere([Where::eq('idremesa', $rem->idremesa)]);
            $rem->delete();
        }
    } else { t('IDRECIBO existe y esta pendiente', false); }
}

// limpieza
foreach (MandatoSepa::all([Where::eq('codcliente', $cod)]) as $x) { $x->delete(); }
$c->delete();
if ($creoAcreedor) { $a->delete(); }
echo "RESULT $n $f\n";
exit($f ? 1 : 0);
PHPEOF
echo "== php -l"
for f in $(find "$DIR/Plugins/RemesasSepaFacturalo" -name '*.php'); do php -l "$f" >/dev/null 2>&1 && ok=$((ok+1)) || { echo "FAIL  php -l $f"; ko=$((ko+1)); }; done
echo "php -l: $ok ok, $ko con error"
if [ -f "$DIR/Plugins/RemesasSepaFacturalo/Data/pain.008.001.02.xsd" ]; then echo "XSD oficial presente: se valida contra el esquema"; else echo "AVISO: no hay Data/pain.008.001.02.xsd; solo validación estructural"; fi
echo "== pruebas en vivo"
php "$PHP" "$DIR"
