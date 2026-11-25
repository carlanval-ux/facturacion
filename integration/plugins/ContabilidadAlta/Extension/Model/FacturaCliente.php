<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Cambios;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Periodos;

/** MAPA-18 (bloqueo de fecha en periodo cerrado) y MAPA-20 (registro de cambios). */
class FacturaCliente
{
    public function saveBefore(): Closure
    {
        return function () {
            if ((!$this->exists() || $this->isDirty('fecha')) && Periodos::cerrada($this->fecha)) {
                Tools::log()->error('fecha-periodo-cerrado', ['%fecha%' => Tools::date($this->fecha)]);
                return false;
            }
            return true;
        };
    }

    public function save(): Closure
    {
        return function () {
            Cambios::registrar('factura', $this->idfactura, $this->idfactura, $this->idfacturarect, 'guardado');
            return true;
        };
    }

    public function delete(): Closure
    {
        return function () {
            Cambios::registrar('factura', $this->idfactura, $this->idfactura, $this->idfacturarect, 'eliminado');
            return true;
        };
    }
}
