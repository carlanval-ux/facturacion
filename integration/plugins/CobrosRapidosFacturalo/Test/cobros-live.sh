#!/usr/bin/env bash
# Prueba en vivo de CobrosRapidosFacturalo en teleco-test (NUNCA redtelecom). No imprime secretos.
# Uso: ssh ploi@host 'bash -s' < cobros-live.sh   (con el plugin ya desplegado en teleco-test)
# Cobro único, parcial, lote, anulación, fecha futura y exceso de importe. Limpia al final (borra sus facturas de prueba).
set -u
DIR=/srv/fs/teleco-test/current
PHP=$(mktemp /tmp/cobros-XXXX.php); trap 'rm -f "$PHP"' EXIT
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
if (!Plugins::isEnabled('CobrosRapidosFacturalo')) { Plugins::enable('CobrosRapidosFacturalo'); Plugins::deploy(); echo "INFO  plugin activado; vuelve a lanzar el script\n"; exit(0); }
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Dinamic\Model\{Cliente, FacturaCliente, FormaPago, ReciboCliente, PagoCliente};
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Cobros;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Model\CobroRegistro;
$ok = 0; $ko = 0;
function chk($n, $c) { global $ok, $ko; echo ($c ? "PASS  " : "FAIL  ") . $n . "\n"; $c ? $ok++ : $ko++; }

$fp = FormaPago::all([], [], 0, 1)[0] ?? null;
if (!$fp) { echo "FAIL  no hay formas de pago\n"; exit(1); }
$c = new Cliente(); $c->nombre = $c->razonsocial = 'ZZ cobros test ' . bin2hex(random_bytes(2)); $c->save();
$mk = function ($importe) use ($c, $fp) {
    $f = new FacturaCliente(); $f->setSubject($c); $f->codpago = $fp->codpago; $f->save();
    $l = $f->getNewLine(); $l->descripcion = 'ZZ cobro'; $l->cantidad = 1; $l->pvpunitario = $importe; $l->save();
    $ls = $f->getLines(); Calculator::calculate($f, $ls, true); $f->reload();
    return $f;
};
$hoy = date('d-m-Y'); $nick = 'cobros-test'; $lote = '';
$f1 = $mk(100); $f2 = $mk(200); $f3 = $mk(50);
$r = fn($f) => $f->getReceipts();
chk('cada factura tiene un recibo pendiente', count($r($f1)) === 1 && !$r($f1)[0]->pagado);

echo "== 1) cobro único\n";
$r1 = $r($f1)[0];
chk('cobra', '' === Cobros::cobrar([$r1->idrecibo => (float)$r1->importe], $hoy, $fp->codpago, '', 'test', $nick, $lote));
$f1->reload(); chk('factura pagada', (bool)$f1->pagada);
chk('recibo pagado con fecha de hoy', $r($f1)[0]->pagado && strtotime($r($f1)[0]->fechapago) === strtotime($hoy));
chk('1 pago creado', PagoCliente::count([Where::eq('idrecibo', $r1->idrecibo)]) === 1);
chk('registro de auditoría', CobroRegistro::count([Where::eq('idrecibo', $r1->idrecibo), Where::eq('accion', 'cobro'), Where::eq('nick', $nick)]) === 1);

echo "== 2) parcial\n";
$r2 = $r($f2)[0];
chk('cobra 80 de 200', '' === Cobros::cobrar([$r2->idrecibo => 80.0], $hoy, $fp->codpago, '', 'parcial', $nick, $lote));
$f2->reload(); $rs = $r($f2);
chk('2 recibos: 80 pagado + 120 pendiente', count($rs) === 2 && round($rs[0]->importe, 2) === 80.0 && $rs[0]->pagado && round($rs[1]->importe, 2) === 120.0 && !$rs[1]->pagado);
chk('factura sigue pendiente', !$f2->pagada);
chk('no se pasa del importe (121 > 120)', 'cobros-amount-exceeds' === Cobros::cobrar([$rs[1]->idrecibo => 121.0], $hoy, $fp->codpago, '', '', $nick, $lote));
chk('fecha futura rechazada', 'cobros-future-date' === Cobros::cobrar([$rs[1]->idrecibo => 10.0], date('d-m-Y', strtotime('+2 days')), $fp->codpago, '', '', $nick, $lote));
chk('nada cambió tras rechazos', !$r($f2)[1]->pagado && round($r($f2)[1]->importe, 2) === 120.0);

echo "== 3) lote (resto de f2 + f3), todo o nada\n";
$rs = $r($f2); $r3 = $r($f3)[0];
chk('lote con un importe inválido no cobra nada', 'cobros-amount-exceeds' === Cobros::cobrar([$rs[1]->idrecibo => 120.0, $r3->idrecibo => 999.0], $hoy, $fp->codpago, '', '', $nick, $lote) && !$r($f2)[1]->pagado);
chk('lote ok', '' === Cobros::cobrar([$rs[1]->idrecibo => 120.0, $r3->idrecibo => 50.0], $hoy, $fp->codpago, '', 'lote', $nick, $lote));
$f2->reload(); $f3->reload();
chk('f2 y f3 pagadas', (bool)$f2->pagada && (bool)$f3->pagada);
chk('mismo lote en el registro', CobroRegistro::count([Where::eq('lote', $lote)]) === 2);

echo "== 4) anulación\n";
$reg = CobroRegistro::all([Where::eq('idrecibo', $r1->idrecibo), Where::eq('accion', 'cobro')])[0];
chk('sin motivo no anula', 'cobros-reason-required' === Cobros::anular($reg->id, ' ', $nick));
chk('anula con motivo', '' === Cobros::anular($reg->id, 'cobrada por error', $nick));
$f1->reload();
chk('recibo vuelve a pendiente y factura impagada', !$r($f1)[0]->pagado && !$f1->pagada);
chk('pago de reversión (saldo 0)', abs(array_sum(array_map(fn($p) => $p->importe, PagoCliente::all([Where::eq('idrecibo', $r1->idrecibo)])))) < 0.005);
chk('registro: cobro marcado y fila de anulación', CobroRegistro::count([Where::eq('idrecibo', $r1->idrecibo), Where::eq('anulado', true)]) === 1 && CobroRegistro::count([Where::eq('idrecibo', $r1->idrecibo), Where::eq('accion', 'anulacion')]) === 1);
chk('no se anula dos veces', 'cobros-cant-cancel' === Cobros::anular($reg->id, 'otra vez', $nick));

echo "== 5) limpieza\n";
foreach ([$f1, $f2, $f3] as $f) { foreach ($r($f) as $rec) { CobroRegistro::deleteWhere([Where::eq('idrecibo', $rec->idrecibo)]); } }
foreach ([$f1, $f2, $f3] as $f) { $f->reload(); if (!$f->delete()) { echo "WARN  no se pudo borrar {$f->codigo} (queda de prueba)\n"; } }
$c->delete();
echo "RESULTADO  PASS=$ok FAIL=$ko\n";
exit($ko ? 1 : 0);
PHPEOF
php "$PHP" "$DIR"
