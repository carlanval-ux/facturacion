<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Periodos;

/** MAPA-18: bloqueo de fecha en periodo cerrado (compras). */
class FacturaProveedor
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
}
