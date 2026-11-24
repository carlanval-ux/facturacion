<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Extension\Model;

use Closure;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Cambios;

/** MAPA-20: un cobro, vencimiento o estado de recibo cambia → queda registrado. */
class ReciboCliente
{
    public function save(): Closure
    {
        return function () {
            Cambios::registrar('recibo', $this->idrecibo, $this->idfactura, null, 'guardado');
            return true;
        };
    }

    public function delete(): Closure
    {
        return function () {
            Cambios::registrar('recibo', $this->idrecibo, $this->idfactura, null, 'eliminado');
            return true;
        };
    }
}
