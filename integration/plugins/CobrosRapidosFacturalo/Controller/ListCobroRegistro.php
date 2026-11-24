<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Cobros;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Modulo;

/** Registro de cobros (quién y cuándo) y anulación de un cobro hecho por error. */
class ListCobroRegistro extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'cobros-register';
        $data['icon'] = 'fa-solid fa-hand-holding-dollar';
        $data['showonmenu'] = Modulo::activo();
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListCobroRegistro', 'CobroRegistro', 'cobros-register', 'fa-solid fa-hand-holding-dollar')
            ->addSearchFields(['codigofactura', 'codcliente', 'nick', 'observaciones', 'motivo', 'lote'])
            ->addOrderBy(['fecha', 'id'], 'date', 2)
            ->addOrderBy(['importe'], 'amount')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            ->setSettings('clickable', false);
        $this->addFilterSelect('ListCobroRegistro', 'accion', 'cobros-action', 'accion', [
            ['code' => '', 'description' => '------'],
            ['code' => 'cobro', 'description' => Tools::trans('cobros-action-pay')],
            ['code' => 'anulacion', 'description' => Tools::trans('cobros-action-cancel')],
        ]);
        $this->addFilterAutocomplete('ListCobroRegistro', 'codcliente', 'customer', 'codcliente', 'Cliente');
        $this->addFilterPeriod('ListCobroRegistro', 'fecha', 'date', 'fecha');

        $this->tab('ListCobroRegistro')->addButton([
            'action' => 'cobros-cancel',
            'color' => 'danger',
            'icon' => 'fa-solid fa-rotate-left',
            'label' => 'cobros-cancel',
            'type' => 'modal',
        ]);
    }

    protected function execPreviousAction($action)
    {
        if ('cobros-cancel' !== $action) {
            return parent::execPreviousAction($action);
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
        $motivo = trim((string)$this->request->input('motivo', ''));
        $hechos = 0;
        foreach ($codes as $id) {
            $error = Cobros::anular($id, $motivo, $this->user->nick);
            if ('' !== $error) {
                Tools::log()->warning($error);
                continue;
            }
            $hechos++;
        }
        if ($hechos > 0) {
            Tools::log()->notice('cobros-cancelled', ['%count%' => $hechos]);
        }
        return true;
    }
}
