<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\CuentaBanco;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Model\CobroRegistro;

/**
 * Cobros y anulaciones sobre ReciboCliente. Todo el efecto contable (pago, asiento, estado de la
 * factura) lo hace el propio modelo de recibos al cambiar 'pagado'; aquí solo se prepara el recibo.
 */
final class Cobros
{
    /** @return ReciboCliente[] pendientes (no pagados, importe > 0) de las facturas dadas, por vencimiento */
    public static function pendientesDeFacturas(array $idfacturas): array
    {
        $ids = array_values(array_filter(array_map('intval', $idfacturas)));
        if (empty($ids)) {
            return [];
        }
        return ReciboCliente::all(
            [Where::in('idfactura', $ids), Where::eq('pagado', false), Where::gt('importe', 0)],
            ['vencimiento' => 'ASC', 'idrecibo' => 'ASC']
        );
    }

    /** @return ReciboCliente[] solo los pendientes de los ids dados */
    public static function pendientesDeRecibos(array $idrecibos): array
    {
        $ids = array_values(array_filter(array_map('intval', $idrecibos)));
        if (empty($ids)) {
            return [];
        }
        return ReciboCliente::all(
            [Where::in('idrecibo', $ids), Where::eq('pagado', false), Where::gt('importe', 0)],
            ['vencimiento' => 'ASC', 'idrecibo' => 'ASC']
        );
    }

    /** Pasa una fecha de formulario (Y-m-d o d-m-Y) a d-m-Y, o null si no es válida. */
    public static function fecha(string $texto): ?string
    {
        $ts = strtotime($texto);
        return false === $ts || '' === trim($texto) ? null : date('d-m-Y', $ts);
    }

    /**
     * Cobra recibos en una sola operación (mismo método y fecha). Todo o nada.
     *
     * @param array<int,float> $importes idrecibo => importe a cobrar (puede ser menor que el recibo: cobro parcial)
     * @return string clave de traducción del error ('' si fue bien); en $lote queda el id de la operación
     */
    public static function cobrar(array $importes, string $fecha, string $codpago, string $codcuenta, string $obs, string $nick, string &$lote = ''): string
    {
        if (false === Modulo::activo()) {
            return 'cobros-module-inactive';
        }
        $f = self::fecha($fecha);
        if (null === $f) {
            return 'cobros-bad-date';
        } elseif (strtotime($f) > strtotime(date('Y-m-d'))) {
            return 'cobros-future-date';
        }
        $forma = new FormaPago();
        if (empty($importes) || false === $forma->load($codpago)) {
            return 'cobros-nothing-to-pay';
        }
        if ('' !== $codcuenta && false === (new CuentaBanco())->load($codcuenta)) {
            return 'cobros-bad-account';
        }

        $dec = (int)Tools::settings('default', 'decimals', 2);
        $db = new DataBase();
        $db->connect();
        $db->beginTransaction();
        $lote = 'C' . date('ymdHis') . substr(bin2hex(random_bytes(2)), 0, 4);

        foreach ($importes as $idrecibo => $importe) {
            $error = self::cobrarRecibo((int)$idrecibo, round((float)$importe, $dec), $f, $codpago, $codcuenta, $obs, $nick, $lote, $dec);
            if ('' !== $error) {
                $db->rollback();
                return $error;
            }
        }

        $db->commit();
        return '';
    }

    /** Anula un cobro registrado: el recibo vuelve a pendiente (FS genera su pago de reversión). */
    public static function anular(int $idregistro, string $motivo, string $nick): string
    {
        if (false === Modulo::activo()) {
            return 'cobros-module-inactive';
        } elseif ('' === trim($motivo)) {
            return 'cobros-reason-required';
        }
        $reg = new CobroRegistro();
        if (false === $reg->load($idregistro) || 'cobro' !== $reg->accion || $reg->anulado) {
            return 'cobros-cant-cancel';
        }
        $recibo = new ReciboCliente();
        $dec = (int)Tools::settings('default', 'decimals', 2);
        // el recibo debe seguir pagado y tal como se cobró: si alguien lo tocó después, no se anula a ciegas
        if (false === $recibo->load($reg->idrecibo) || false === (bool)$recibo->pagado
            || round((float)$recibo->importe, $dec) !== round((float)$reg->importe, $dec)) {
            return 'cobros-cant-cancel';
        }

        $db = new DataBase();
        $db->connect();
        $db->beginTransaction();

        $recibo->pagado = false;
        $recibo->fechapago = null;
        $recibo->nick = $nick;
        $recibo->observaciones = trim((string)$recibo->observaciones . "\n"
            . Tools::trans('cobros-cancel-note', ['%date%' => Tools::date(), '%reason%' => $motivo]));
        $reg->anulado = true;
        $log = new CobroRegistro();
        $log->nick = $nick;
        $log->accion = 'anulacion';
        $log->lote = $reg->lote;
        $log->idrecibo = $reg->idrecibo;
        $log->idfactura = $reg->idfactura;
        $log->codigofactura = $reg->codigofactura;
        $log->codcliente = $reg->codcliente;
        $log->importe = $reg->importe;
        $log->fechacobro = $reg->fechacobro;
        $log->codpago = $reg->codpago;
        $log->codcuenta = $reg->codcuenta;
        $log->motivo = $motivo;

        if (false === $recibo->save() || false === $reg->save() || false === $log->save()) {
            $db->rollback();
            return 'record-save-error';
        }
        $db->commit();
        return '';
    }

    private static function cobrarRecibo(int $idrecibo, float $importe, string $fecha, string $codpago, string $codcuenta, string $obs, string $nick, string $lote, int $dec): string
    {
        $recibo = new ReciboCliente();
        if (false === $recibo->load($idrecibo) || $recibo->pagado) {
            return 'cobros-receipt-not-pending';
        }
        $pendiente = round((float)$recibo->importe, $dec);
        if ($importe <= 0 || $importe > $pendiente) {
            return 'cobros-amount-exceeds'; // control de no pasarse del importe
        }

        // cobro parcial: el recibo se queda con lo cobrado y se crea otro por el resto, mismo vencimiento
        if ($importe < $pendiente) {
            $factura = new FacturaCliente();
            if (false === $factura->load($recibo->idfactura)) {
                return 'record-not-found';
            }
            $numero = 1;
            foreach ($factura->getReceipts() as $r) {
                $numero = max($numero, (int)$r->numero + 1);
            }
            $resto = $factura->getNewReceipt($numero, ['importe' => round($pendiente - $importe, $dec)]);
            $resto->vencimiento = $recibo->vencimiento;
            $resto->codpago = $recibo->codpago;
            $resto->disableInvoiceUpdate(true);
            if (false === $resto->save()) {
                return 'record-save-error';
            }
            $recibo->importe = $importe;
        }

        $recibo->pagado = true;
        $recibo->fechapago = $fecha;
        $recibo->codpago = $codpago;
        $recibo->nick = $nick;
        $recibo->observaciones = trim((string)$recibo->observaciones . "\n" . $obs . ('' === $codcuenta ? '' : ' [' . $codcuenta . ']'));
        if (false === $recibo->save()) {
            return 'record-save-error';
        }

        $log = new CobroRegistro();
        $log->nick = $nick;
        $log->accion = 'cobro';
        $log->lote = $lote;
        $log->idrecibo = $recibo->idrecibo;
        $log->idfactura = $recibo->idfactura;
        $log->codigofactura = $recibo->codigofactura;
        $log->codcliente = $recibo->codcliente;
        $log->importe = $importe;
        $log->fechacobro = $fecha;
        $log->codpago = $codpago;
        $log->codcuenta = $codcuenta;
        $log->observaciones = $obs;
        return $log->save() ? '' : 'record-save-error';
    }
}
