<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;

/**
 * Botón 'Marcar como cobrada' en la ficha de la factura. La cabecera del documento es una vista HTML sin
 * botones de acción, así que va en la pestaña de recibos de la propia ficha (se marcan los recibos a cobrar).
 */
class EditFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            if (isset($this->views['ListReciboCliente'])) {
                $this->tab('ListReciboCliente')->addButton([
                    'action' => 'cobros-open-receipts',
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
            if ('cobros-open-receipts' !== $action) {
                return true;
            } elseif (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
                return true;
            } elseif (false === $this->validateFormToken()) {
                return true;
            }
            $codes = array_filter(array_map('intval', $this->request->request->getArray('codes')));
            if (empty($codes)) {
                Tools::log()->warning('no-selected-item');
                return true;
            }
            $this->redirect('CobrarFacturas?recibos=' . implode(',', $codes));
            return false;
        };
    }
}
