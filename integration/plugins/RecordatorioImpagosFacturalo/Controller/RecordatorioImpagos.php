<?php
/**
 * RecordatorioImpagosFacturalo - Panel: ajustes, recibos pendientes, historial y bajas.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\ModuloActivo;
use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\Recordatorios;

class RecordatorioImpagos extends PanelController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'unpaid-reminders';
        $data['icon'] = 'fa-solid fa-bell';
        return $data;
    }

    protected function createViews(): void
    {
        $this->setTemplate('EditSettings');

        $this->addEditView('EditRecordatorioImpagos', 'Settings', 'unpaid-reminders-settings', 'fa-solid fa-gear')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);

        $this->addListView('ListImpagoPendiente', 'ReciboCliente', 'unpaid-receipts', 'fa-solid fa-hourglass-half')
            ->addSearchFields(['codigofactura', 'codcliente'])
            ->addOrderBy(['vencimiento'], 'expiration', 1)
            ->addOrderBy(['importe'], 'amount')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            ->setSettings('clickable', false);
        $this->addButton('ListImpagoPendiente', [
            'action' => 'impagos-send-now',
            'color' => 'warning',
            'icon' => 'fa-solid fa-paper-plane',
            'label' => 'send-reminder-now',
            'confirm' => true,
        ]);

        $this->addListView('ListImpagoRecordatorio', 'ImpagoRecordatorio', 'reminder-history', 'fa-solid fa-clock-rotate-left')
            ->addSearchFields(['codigofactura', 'codcliente', 'email', 'mensaje'])
            ->addOrderBy(['fecha'], 'date', 2)
            ->addOrderBy(['codigofactura'], 'code')
            ->addFilterSelect('resultado', 'result', 'resultado', [
                ['code' => '', 'description' => '------'],
                ['code' => 'enviado', 'description' => 'enviado'],
                ['code' => 'error', 'description' => 'error'],
                ['code' => 'sin-email', 'description' => 'sin-email'],
                ['code' => 'omitido', 'description' => 'omitido'],
            ])
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            ->setSettings('clickable', false);

        $this->addListView('ListImpagoBaja', 'ImpagoBaja', 'unsubscribed-customers', 'fa-solid fa-user-slash')
            ->addSearchFields(['codcliente'])
            ->addOrderBy(['fecha'], 'date', 2)
            ->setSettings('btnNew', false)
            ->setSettings('clickable', false);
    }

    protected function execPreviousAction($action)
    {
        if ($action !== 'impagos-send-now') {
            return parent::execPreviousAction($action);
        }

        if (false === $this->validateFormToken()) {
            return true;
        } elseif (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }

        $codes = $this->request->request->getArray('codes');
        if (empty($codes) || false === is_array($codes)) {
            Tools::log()->warning('no-selected-item');
            return true;
        }

        $ok = 0;
        foreach ($codes as $code) {
            $recibo = new ReciboCliente();
            if (false === $recibo->load($code)) {
                continue;
            }
            $motivo = Recordatorios::enviarAhora($recibo, $this->user->nick);
            if ('' === $motivo) {
                $ok++;
                continue;
            }
            Tools::log()->warning('reminder-not-sent', [
                '%code%' => $recibo->codigofactura,
                '%reason%' => Tools::lang()->trans($motivo),
            ]);
        }
        if ($ok > 0) {
            Tools::log()->notice('reminders-sent', ['%count%' => $ok]);
        }
        return true;
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'EditRecordatorioImpagos':
                $view->loadData(Recordatorios::GRUPO);
                $view->model->name = Recordatorios::GRUPO;
                if (false === ModuloActivo::estaActivo(Recordatorios::MODULO)) {
                    Tools::log()->warning('impagos-module-inactive');
                }
                break;

            case 'ListImpagoPendiente':
                $view->loadData('', [Where::eq('pagado', false)]);
                break;

            case 'ListImpagoRecordatorio':
            case 'ListImpagoBaja':
                $view->loadData();
                break;
        }
    }
}
