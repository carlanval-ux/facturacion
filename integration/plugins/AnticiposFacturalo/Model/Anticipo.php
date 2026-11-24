<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Dinamic\Model\FacturaCliente;

/** Anticipo cobrado a cuenta de un presupuesto, pedido o albarán de cliente; su factura de anticipo es una FacturaCliente normal. */
class Anticipo extends ModelClass
{
    use ModelTrait;

    const PENDIENTE = 'pendiente';
    const APLICADO = 'aplicado';
    const DEVUELTO = 'devuelto';

    public $id;
    public $codcliente;
    public $modelo;
    public $iddoc;
    public $codigodoc;
    public $idfactura;
    public $idfacturafinal;
    public $codserie;
    public $importe;
    public $porcentaje;
    public $neto;
    public $totaliva;
    public $total;
    public $estado;
    public $fecha;
    public $codpago;
    public $observaciones;
    public $nick;

    public function clear(): void
    {
        parent::clear();
        $this->estado = self::PENDIENTE;
        $this->importe = $this->porcentaje = $this->neto = $this->totaliva = $this->total = 0.0;
    }

    public function getFactura(): FacturaCliente
    {
        $f = new FacturaCliente();
        $f->load($this->idfactura);
        return $f;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'anticipos';
    }
}
