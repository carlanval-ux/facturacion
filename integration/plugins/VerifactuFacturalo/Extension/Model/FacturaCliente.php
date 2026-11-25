<?php
/**
 * VerifactuFacturalo - Al emitir la factura genera su registro de alta; una vez registrada se bloquean los cambios que alteran el registro y el borrado.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Servicio;

class FacturaCliente
{

    public function onInsert(): Closure
    {
        return function () {
            Servicio::registrarSiProcede($this);
        };
    }

    public function onUpdate(): Closure
    {
        return function () {
            Servicio::registrarSiProcede($this);
        };
    }

    public function saveBefore(): Closure
    {
        return function () {
            if (empty($this->idfactura) || false === $this->exists()) {
                return true;
            }
            foreach (Servicio::CAMPOS_BLOQUEADOS as $campo) {
                if ($this->isDirty($campo) && Servicio::bloqueada((int)$this->idfactura)) {
                    Tools::log()->error('vfp-invoice-locked', ['%code%' => $this->codigo, '%field%' => $campo]);
                    return false;
                }
            }
            return true;
        };
    }

    public function deleteBefore(): Closure
    {
        return function () {
            if (!empty($this->idfactura) && Servicio::bloqueada((int)$this->idfactura)) {
                Tools::log()->error('vfp-invoice-locked-delete', ['%code%' => $this->codigo]);
                return false;
            }
            return true;
        };
    }
}
