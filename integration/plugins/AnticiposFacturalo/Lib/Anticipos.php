<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Calculator;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AnticiposFacturalo\Model\Anticipo;

/** Lógica de anticipos: alta (factura de anticipo cobrada), descuento en la factura final, devolución y borrado. */
final class Anticipos
{
    const ORIGENES = ['PresupuestoCliente' => 'estimation', 'PedidoCliente' => 'order', 'AlbaranCliente' => 'delivery-note'];

    /** @var array<int, Anticipo[]> anticipos descontados en una factura final aún no confirmada (idfactura => anticipos) */
    private static $aplicando = [];

    /** Serie de las facturas de anticipo: la configurada, o ANT si existe, o la predeterminada. */
    public static function serie(): string
    {
        foreach ([(string)Tools::settings('anticipos', 'codserie', ''), 'ANT', (string)Tools::settings('default', 'codserie', '')] as $cod) {
            if ($cod !== '' && (new Serie())->load($cod)) {
                return $cod;
            }
        }
        return '';
    }

    /** Anticipos vigentes (pendientes o aplicados) de un documento: importe ya cobrado a cuenta. */
    public static function vigente(string $modelo, $iddoc): float
    {
        $suma = 0.0;
        foreach (Anticipo::all([Where::eq('modelo', $modelo), Where::eq('iddoc', $iddoc), Where::in('estado', [Anticipo::PENDIENTE, Anticipo::APLICADO])]) as $a) {
            $suma += $a->total;
        }
        return round($suma, 2);
    }

    /** Avisa de los anticipos pendientes de aplicar que cumplan el filtro. */
    public static function avisarPendientes(array $where): void
    {
        $num = 0;
        $total = 0.0;
        foreach (Anticipo::all(array_merge($where, [Where::eq('estado', Anticipo::PENDIENTE)])) as $a) {
            $num++;
            $total += $a->total;
        }
        if ($num > 0) {
            Tools::log()->warning('anticipos-sin-aplicar', ['%num%' => $num, '%total%' => Tools::money($total)]);
        }
    }

    /** Crea la factura de anticipo (emitida y cobrada) y el registro Anticipo. null si algo falla (queda el aviso en el log y se deshace todo). */
    public static function registrar($doc, float $importe, float $porcentaje, string $fecha, string $codpago, string $observaciones, string $nick): ?Anticipo
    {
        $modelo = $doc->modelClassName();
        if (!$doc->exists() || !isset(self::ORIGENES[$modelo])) {
            Tools::log()->warning('record-not-found');
            return null;
        }
        if ($doc->total <= 0) {
            Tools::log()->warning('anticipo-importe-invalido');
            return null;
        }
        if ($importe <= 0 && $porcentaje > 0) {
            $importe = round($doc->total * $porcentaje / 100, 2);
        } elseif ($importe > 0) {
            $porcentaje = round($importe / $doc->total * 100, 2);
        }
        if ($importe <= 0) {
            Tools::log()->warning('anticipo-importe-invalido');
            return null;
        }
        if (round(self::vigente($modelo, $doc->id()) + $importe, 2) > round($doc->total, 2)) {
            Tools::log()->warning('anticipo-supera-total', ['%total%' => Tools::money($doc->total)]);
            return null;
        }

        $sub = Calculator::getSubtotals($doc, $doc->getLines());
        $tramos = [];
        foreach ($sub['iva'] as $t) {
            $tramos[] = ['codimpuesto' => $t['codimpuesto'], 'iva' => $t['iva'], 'neto' => $t['neto']];
        }
        $bases = Reparto::bases($importe, $tramos);
        $cliente = new Cliente();
        $serie = self::serie();
        if (empty($bases) || $serie === '' || !$cliente->load($doc->codcliente)) {
            Tools::log()->warning('anticipo-importe-invalido');
            return null;
        }

        $dia = $fecha !== '' ? Tools::date($fecha) : Tools::date();
        $db = new DataBase();
        $db->connect();
        $db->beginTransaction();

        $f = new FacturaCliente();
        $f->setSubject($cliente);
        $f->idempresa = $doc->idempresa;
        $f->codalmacen = $doc->codalmacen;
        $f->coddivisa = $doc->coddivisa;
        $f->codserie = $serie;
        $f->codpago = $codpago !== '' ? $codpago : $doc->codpago;
        $f->observaciones = trim($observaciones);
        if (!$f->setDate($dia, date(Tools::HOUR_STYLE)) || !$f->save()) {
            $db->rollback();
            return null;
        }

        $texto = Tools::trans('anticipo-linea', ['%doc%' => Tools::trans(self::ORIGENES[$modelo]), '%codigo%' => $doc->codigo]);
        foreach ($bases as $b) {
            $linea = $f->getNewLine(['descripcion' => $texto, 'cantidad' => 1, 'pvpunitario' => $b['base'], 'codimpuesto' => $b['codimpuesto'], 'iva' => $b['iva']]);
            if (!$linea->save()) {
                $db->rollback();
                return null;
            }
        }
        $lineas = $f->getLines();
        if (!Calculator::calculate($f, $lineas, true)) {
            $db->rollback();
            return null;
        }

        // cobro: recibos pagados (el cobro real ya ocurrió: es la señal)
        foreach ($f->getReceipts() as $recibo) {
            $recibo->pagado = true;
            $recibo->fechapago = $dia;
            if ($codpago !== '') {
                $recibo->codpago = $codpago;
            }
            if (!$recibo->save()) {
                $db->rollback();
                return null;
            }
        }

        // emisión por el flujo normal: primer estado no editable y activo (igual que la rectificativa del núcleo); aquí es donde VERI*FACTU registra el alta
        $f->reload();
        foreach ($f->getAvailableStatus() as $estado) {
            if ($estado->editable || !$estado->activo) {
                continue;
            }
            $f->idestado = $estado->idestado;
            if (!$f->save()) {
                $db->rollback();
                return null;
            }
            break;
        }

        $a = new Anticipo();
        $a->codcliente = $doc->codcliente;
        $a->modelo = $modelo;
        $a->iddoc = $doc->id();
        $a->codigodoc = $doc->codigo;
        $a->idfactura = $f->idfactura;
        $a->codserie = $serie;
        $a->importe = $importe;
        $a->porcentaje = $porcentaje;
        $a->neto = $f->neto;
        $a->totaliva = $f->totaliva;
        $a->total = $f->total;
        $a->fecha = $dia;
        $a->codpago = $f->codpago;
        $a->observaciones = trim($observaciones);
        $a->nick = $nick;
        if (!$a->save()) {
            $db->rollback();
            return null;
        }

        $db->commit();
        Tools::log()->notice('anticipo-registrado', ['%codigo%' => $f->codigo, '%total%' => Tools::money($f->total)]);
        if (abs($f->total - $importe) >= 0.01) {
            Tools::log()->warning('anticipo-importe-ajustado', ['%pedido%' => Tools::money($importe), '%total%' => Tools::money($f->total)]);
        }
        return $a;
    }

    /** Hook cloneLines del generador: añade a la factura final las líneas negativas de los anticipos pendientes. false aborta la generación. */
    public static function descontar($origen, $factura, array $lineas): bool
    {
        $modelo = $origen->modelClassName();
        if ($factura->modelClassName() !== 'FacturaCliente' || !isset(self::ORIGENES[$modelo])) {
            return true;
        }
        $ids = [(int)$origen->id()];
        foreach ($lineas as $l) {
            $ids[] = (int)$l->documentColumnValue();
        }
        $pendientes = Anticipo::all([
            Where::eq('modelo', $modelo), Where::in('iddoc', array_values(array_unique($ids))),
            Where::eq('estado', Anticipo::PENDIENTE), Where::eq('codcliente', $factura->codcliente)
        ], ['id' => 'ASC']);
        if (empty($pendientes)) {
            return true;
        }

        $s = Calculator::getSubtotals($factura, $factura->getLines());
        $resto = round($s['neto'] + $s['totaliva'] + $s['totalrecargo'] - $s['totalirpf'] + $s['totalsuplidos'], 2);
        // los descuentos de documento (dtopor1/2) también se aplican a estas líneas: se compensan para que el neto descontado sea exacto
        $factor = (1 - (float)$factura->dtopor1 / 100) * (1 - (float)$factura->dtopor2 / 100);
        if ($factor <= 0) {
            return true;
        }

        foreach ($pendientes as $a) {
            $fa = $a->getFactura();
            if (round($a->total, 2) > $resto) {
                Tools::log()->warning('anticipo-excede', ['%codigo%' => $fa->codigo, '%anticipo%' => Tools::money($a->total), '%resto%' => Tools::money(max(0.0, $resto))]);
                continue;
            }
            $tramos = [];
            foreach ($fa->getLines() as $l) {
                $k = $l->codimpuesto . '|' . $l->iva . '|' . $l->recargo;
                $tramos[$k] = $tramos[$k] ?? ['codimpuesto' => $l->codimpuesto, 'iva' => $l->iva, 'recargo' => $l->recargo, 'base' => 0.0];
                $tramos[$k]['base'] += $l->pvptotal;
            }
            foreach ($tramos as $t) {
                if (abs($t['base']) < 0.005) {
                    continue;
                }
                $n = $factura->getNewLine([
                    'descripcion' => Tools::trans('anticipo-descuento', ['%codigo%' => $fa->codigo, '%fecha%' => $fa->fecha]),
                    'cantidad' => 1, 'pvpunitario' => -$t['base'] / $factor,
                    'codimpuesto' => $t['codimpuesto'], 'iva' => $t['iva'], 'recargo' => $t['recargo']
                ]);
                if (!$n->save()) {
                    return false;
                }
            }
            $resto = round($resto - $a->total, 2);
            self::$aplicando[$factura->id()][] = $a;
        }
        return true;
    }

    /** Hook generateTrue: confirma los anticipos descontados o, si el nuevo documento no es factura, los traslada al nuevo documento. */
    public static function confirmar($origen, $nuevo): void
    {
        $modelo = $origen->modelClassName();
        if (!isset(self::ORIGENES[$modelo])) {
            return;
        }
        if ($nuevo->modelClassName() === 'FacturaCliente') {
            $aplicados = self::$aplicando[$nuevo->id()] ?? [];
            unset(self::$aplicando[$nuevo->id()]);
            foreach ($aplicados as $a) {
                $a->estado = Anticipo::APLICADO;
                $a->idfacturafinal = $nuevo->idfactura;
                $a->save();
            }
            if ($aplicados) {
                Tools::log()->notice('anticipos-aplicados', ['%num%' => count($aplicados), '%resto%' => Tools::money($nuevo->total)]);
            }
            return;
        }
        if (isset(self::ORIGENES[$nuevo->modelClassName()])) {
            foreach (Anticipo::all([Where::eq('modelo', $modelo), Where::eq('iddoc', $origen->id()), Where::eq('estado', Anticipo::PENDIENTE)]) as $a) {
                $a->modelo = $nuevo->modelClassName();
                $a->iddoc = $nuevo->id();
                $a->codigodoc = $nuevo->codigo;
                $a->save();
            }
        }
    }

    public static function olvidar($nuevo): void
    {
        unset(self::$aplicando[$nuevo->id()]);
    }

    /** Hook de guardado de factura: una rectificativa que anula por completo un anticipo pendiente lo marca como devuelto. */
    public static function alGuardarFactura(FacturaCliente $f): void
    {
        if (empty($f->idfacturarect) || empty($f->total)) {
            return;
        }
        $a = new Anticipo();
        if (!$a->loadWhere([Where::eq('idfactura', $f->idfacturarect)]) || $a->estado === Anticipo::DEVUELTO) {
            return;
        }
        if (abs(round($f->total + $a->total, 2)) >= 0.01) {
            Tools::log()->warning('anticipo-rectificativa-parcial', ['%codigo%' => $f->codigorect]);
            return;
        }
        if ($a->estado === Anticipo::APLICADO) {
            $fin = new FacturaCliente();
            $fin->load($a->idfacturafinal);
            Tools::log()->warning('anticipo-aplicado-rectificado', ['%codigo%' => $f->codigorect, '%final%' => $fin->codigo]);
            return;
        }
        $a->estado = Anticipo::DEVUELTO;
        $a->save();
        Tools::log()->notice('anticipo-devuelto-auto', ['%codigo%' => $f->codigorect]);
    }

    /** Hook deleteBefore de factura: no se borra un anticipo aplicado; al borrar una final sus anticipos vuelven a pendiente. */
    public static function alBorrarFactura(FacturaCliente $f): bool
    {
        $a = new Anticipo();
        if ($a->loadWhere([Where::eq('idfactura', $f->idfactura)])) {
            if ($a->estado === Anticipo::APLICADO) {
                Tools::log()->warning('anticipo-borrar-aplicado');
                return false;
            }
            $a->delete();
        }
        foreach (Anticipo::all([Where::eq('idfacturafinal', $f->idfactura)]) as $b) {
            $b->estado = Anticipo::PENDIENTE;
            $b->idfacturafinal = null;
            $b->save();
        }
        return true;
    }
}
