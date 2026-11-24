<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;

/** Botón 'Marcar como cobrada' en la ficha de la factura. */
class EditFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            if (isset($this->views['EditFacturaCliente'])) {
                $this->tab('EditFacturaCliente')->addButton([
                    'action' => 'cobros-open-invoice',
                    'color' => 'success',
                    'icon' => 'fa-solid fa-hand-holding-dollar',
                    'label' => 'cobros-mark-paid',
                ]);
            }
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ('cobros-open-invoice' !== $action) {
                return true;
            } elseif (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
                return true;
            } elseif (false === $this->validateFormToken()) {
                return true;
            }
            $id = (int)$this->request->inputOrQuery('code', 0);
            if ($id <= 0) {
                Tools::log()->warning('no-selected-item');
                return true;
            }
            $this->redirect('CobrarFacturas?facturas=' . $id);
            return false;
        };
    }
}
