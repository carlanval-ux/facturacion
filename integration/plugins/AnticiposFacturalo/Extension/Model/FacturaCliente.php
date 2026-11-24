<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Extension\Model;

use Closure;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Anticipos;

/** Rectificar una factura de anticipo la marca como devuelta; no se borra un anticipo aplicado; al borrar la final sus anticipos vuelven a pendientes. */
class FacturaCliente
{
    public function save(): Closure
    {
        return function () {
            Anticipos::alGuardarFactura($this);
            return true;
        };
    }

    public function deleteBefore(): Closure
    {
        return function () {
            return Anticipos::alBorrarFactura($this);
        };
    }
}
