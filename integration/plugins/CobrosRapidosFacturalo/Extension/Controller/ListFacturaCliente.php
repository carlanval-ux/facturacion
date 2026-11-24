<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;

/** Botón 'Marcar como cobrada' en el listado de facturas (y en su pestaña de recibos). */
class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            $botones = ['ListFacturaCliente' => 'cobros-open-invoices', 'ListReciboCliente' => 'cobros-open-receipts'];
            foreach ($botones as $vista => $accion) {
                if (isset($this->views[$vista])) {
                    $this->tab($vista)->addButton([
                        'action' => $accion,
                        'color' => 'success',
                        'icon' => 'fa-solid fa-hand-holding-dollar',
                        'label' => 'cobros-mark-paid',
                    ]);
                }
            }
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ('cobros-open-invoices' !== $action && 'cobros-open-receipts' !== $action) {
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
            $param = 'cobros-open-invoices' === $action ? 'facturas' : 'recibos';
            $this->redirect('CobrarFacturas?' . $param . '=' . implode(',', $codes));
            return false;
        };
    }
}
