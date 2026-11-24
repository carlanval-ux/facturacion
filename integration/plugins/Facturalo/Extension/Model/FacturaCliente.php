<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Facturalo\Lib\FechaFutura;

/**
 * Rechaza guardar una factura de cliente con fecha posterior a hoy (altas y cambios de fecha) para no alterar la numeración.
 * Un registro existente con fecha futura no se bloquea si no se toca la fecha. Aplica a UI, API, agrupaciones, rectificativas e importaciones (todas pasan por save()).
 */
class FacturaCliente
{
    public function saveBefore(): Closure
    {
        return function () {
            if ((empty($this->id()) || $this->isDirty('fecha')) && FechaFutura::esFutura($this->fecha)) {
                Tools::log()->error('fecha-factura-futura', ['%fecha%' => Tools::date($this->fecha)]);
                return false;
            }
            return true;
        };
    }
}
