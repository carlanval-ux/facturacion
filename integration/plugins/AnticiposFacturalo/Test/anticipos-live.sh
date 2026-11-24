#!/usr/bin/env bash
# Prueba en vivo de AnticiposFacturalo en teleco-test (NUNCA redtelecom). No imprime secretos.
# Requisito: release con la rama del plugin desplegada, plugin activado (php artisan/UI) y tabla creada (update()).
# Uso: ssh ploi@host 'bash -s' < anticipos-live.sh
# Deja un cliente "Prueba Anticipos", pedidos y facturas de prueba (las facturas emitidas no se borran: es un entorno de pruebas).
set -u
DIR=/srv/fs/teleco-test/current
case "$(readlink -f "$DIR")" in /srv/fs/teleco-test/*) ;; *) echo "no es teleco-test"; exit 2;; esac
PHP=$(mktemp /tmp/ant-XXXX.php); trap 'rm -f "$PHP" /tmp/ant-out.txt' EXIT
cat > "$PHP" <<'PHPEOF'
<?php
use FacturaScripts\Core\Kernel; use FacturaScripts\Core\Plugins; use FacturaScripts\Core\Session; use FacturaScripts\Core\Tools; use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator; use FacturaScripts\Dinamic\Lib\Calculator;
use FacturaScripts\Dinamic\Model\Cliente; use FacturaScripts\Dinamic\Model\PedidoCliente; use FacturaScripts\Dinamic\Model\FacturaCliente; use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Anticipos; use FacturaScripts\Plugins\AnticiposFacturalo\Model\Anticipo;
chdir($argv[1]); define('FS_FOLDER', getcwd());
require FS_FOLDER . '/vendor/autoload.php'; require FS_FOLDER . '/config.php';
date_default_timezone_set(Tools::config('timezone', 'Europe/Madrid'));
Kernel::init(); Plugins::init();
$u = new User(); $u->load('admin'); Session::set('user', $u);
$r = function ($n, $b) { echo ($b ? 'PASS' : 'FAIL') . "  $n\n"; };
$near = fn($a, $b, $d = 0.03) => abs($a - $b) <= $d;
if (!Plugins::isEnabled('AnticiposFacturalo')) { echo "FAIL  plugin AnticiposFacturalo no activado\n"; exit(1); }

$c = new Cliente(); if (!$c->loadWhere([Where::eq('nombre', 'Prueba Anticipos')])) { $c->nombre = 'Prueba Anticipos'; $c->cifnif = 'B00000000'; $c->save(); }
function pedido($c, array $lineas) {
  $p = new PedidoCliente(); $p->setSubject($c); $p->save();
  foreach ($lineas as [$desc, $precio, $imp]) { $l = $p->getNewLine(['descripcion' => $desc, 'cantidad' => 1, 'pvpunitario' => $precio, 'codimpuesto' => $imp]); $l->save(); }
  $ls = $p->getLines(); Calculator::calculate($p, $ls, true); return $p;
}
function final_de($p, $cls = 'FacturaCliente') { $g = new BusinessDocumentGenerator(); $ok = $g->generate($p, $cls); $d = $g->getLastDocs(); return $ok ? end($d) : null; }
$hoy = date('Y-m-d');

echo "== 1. dos anticipos con dos tipos de IVA y factura final\n";
$p = pedido($c, [['Servicio A', 600, 'IVA21'], ['Servicio B', 400, 'IVA10']]);
$r('pedido de 1.166 con IVA 21 y 10 (total ' . $p->total . ')', $near($p->total, 1166, 0.005));
$a1 = Anticipos::registrar($p, 0, 30, $hoy, '', 'señal 30%', 'admin');
$r('anticipo 30% registrado: 349,80', $a1 && $near($a1->total, 349.80, 0.011));
$f1 = $a1 ? $a1->getFactura() : new FacturaCliente();
$r('factura de anticipo en serie ' . Anticipos::serie() . ' (' . $f1->codigo . ')', $f1->codserie === Anticipos::serie());
$r('factura de anticipo con IVA 21 y 10 (2 líneas)', count($f1->getLines()) === 2);
$rec = $f1->getReceipts(); $r('recibos de la factura de anticipo cobrados', !empty($rec) && !array_filter($rec, fn($x) => !$x->pagado));
$r('factura de anticipo emitida (estado no editable) y pagada', !$f1->editable && $f1->pagada);
$a2 = Anticipos::registrar($p, 300, 0, $hoy, '', '', 'admin');
$r('segundo anticipo de 300', $a2 && $near($a2->total, 300, 0.011));
$r('un tercero que supere el total se rechaza', null === Anticipos::registrar($p, 600, 0, $hoy, '', '', 'admin'));
$fin = final_de($p);
$r('factura final generada', null !== $fin);
if ($fin) {
  $desc = array_filter($fin->getLines(), fn($l) => $l->pvptotal < 0);
  $r('final: líneas de descuento negativas (' . count($desc) . ')', count($desc) === 4);
  $r('final: total = 1.166 - 649,80 = 516,20 (total ' . $fin->total . ')', $near($fin->total, 516.20));
  $iva = $fin->totaliva + $a1->totaliva + $a2->totaliva;
  $r('IVA no duplicado: final + anticipos = IVA del pedido 166,00 (' . round($iva, 2) . ')', $near($iva, 166.00));
  $a1->reload(); $a2->reload();
  $r('anticipos aplicados y enlazados a la final', $a1->estado === 'aplicado' && $a2->estado === 'aplicado' && $a1->idfacturafinal == $fin->idfactura);
}

echo "== 2. anticipo en el presupuesto-pedido y traslado a albarán y factura\n";
$p2 = pedido($c, [['Servicio', 1000, 'IVA21']]);
$b1 = Anticipos::registrar($p2, 500, 0, $hoy, '', '', 'admin');
$alb = final_de($p2, 'AlbaranCliente');
$b1->reload();
$r('el anticipo pasa al albarán y sigue pendiente', $alb && $b1->modelo === 'AlbaranCliente' && $b1->iddoc == $alb->idalbaran && $b1->estado === 'pendiente');
$f2 = $alb ? final_de($alb) : null;
$r('factura final desde el albarán descuenta el anticipo (1.210 - 500 = 710; total ' . ($f2->total ?? '-') . ')', $f2 && $near($f2->total, 710.0));

echo "== 3. anticipo anulado con rectificativa antes de la final\n";
$p3 = pedido($c, [['Servicio', 1000, 'IVA21']]);
$d1 = Anticipos::registrar($p3, 200, 0, $hoy, '', '', 'admin');
$fa = $d1->getFactura();
$rect = new FacturaCliente(); $rect->loadFromData($fa->toArray(), FacturaCliente::dontCopyFields()); $rect->codigorect = $fa->codigo; $rect->idfacturarect = $fa->idfactura; $rect->setDate(Tools::date(), date(Tools::HOUR_STYLE));
$okr = $rect->save();
foreach ($fa->getLines() as $l) { $n = $rect->getNewLine($l->toArray()); $n->cantidad = 0 - $l->cantidad; $n->idlinearect = $l->idlinea; $n->save(); }
$ls = $rect->getLines(); Calculator::calculate($rect, $ls, true);
$d1->reload();
$r('rectificativa creada y el anticipo queda devuelto', $okr && $d1->estado === 'devuelto');
$f3 = final_de($p3);
$r('la final ya no descuenta el anticipo devuelto (total 1.210; ' . ($f3->total ?? '-') . ')', $f3 && $near($f3->total, 1210.0));

echo "== 4. anticipo mayor que el total final (el documento baja de importe)\n";
$p4 = pedido($c, [['Servicio', 100, 'IVA21']]);
$e1 = Anticipos::registrar($p4, 100, 0, $hoy, '', '', 'admin');
$ls = $p4->getLines(); $ls[0]->pvpunitario = 30; $ls[0]->save(); $ls = $p4->getLines(); Calculator::calculate($p4, $ls, true); $p4->reload();
$f4 = final_de($p4);
$e1->reload();
$r('la final se genera sin descontar (total ' . ($f4->total ?? '-') . ') y el anticipo sigue pendiente', $f4 && $e1->estado === 'pendiente' && $near($f4->total, 36.30));

echo "== 5. borrado de la final devuelve los anticipos a pendientes\n";
if ($fin) { $codf = $fin->codigo; $ok = $fin->delete(); $a1->reload(); echo 'info  borrar la final: ' . ($ok ? 'permitido' : 'no permitido por FS (normal si no es la última)') . "\n"; if ($ok) { $r('anticipos de nuevo pendientes', $a1->estado === 'pendiente' && empty($a1->idfacturafinal)); } }

echo "== 6. VERI*FACTU\n";
echo 'info  plugin Verifactu activo: ' . (Plugins::isEnabled('Verifactu') ? 'sí' : 'no') . "; anticipo emitido en estado idestado=" . $f1->idestado . " (no editable: " . ($f1->editable ? 'no' : 'sí') . ")\n";
echo 'info  comprueba a mano en Verifactu que la factura de anticipo ' . $f1->codigo . " y la final tienen su registro de alta y que la rectificativa del caso 3 genera el suyo\n";
PHPEOF
php "$PHP" "$DIR" | tee /tmp/ant-out.txt
ok=$(grep -c '^PASS' /tmp/ant-out.txt); ko=$(grep -c '^FAIL' /tmp/ant-out.txt)
echo "RESULTADO: $ok PASS, $ko FAIL"; [ "$ko" -eq 0 ]
