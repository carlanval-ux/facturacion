<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\MascaraIban;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloActivo;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloSepaTrait;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\RemesaService;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\AcreedorSepa;

/** Cobro domiciliado: remesas, mandatos, recibos pendientes y datos del acreedor. */
class ListRemesaSepa extends ListController
{
    use ModuloSepaTrait;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'sepa-direct-debit';
        $data['icon'] = 'fa-solid fa-building-columns';
        $data['showonmenu'] = ModuloActivo::estaActivo('sepa');
        return $data;
    }

    protected function createViews()
    {
        $this->createRemesas();
        $this->createPendientes();
        $this->createMandatos();
        $this->createAcreedores();
    }

    private function createRemesas(): void
    {
        $v = 'ListRemesaSepa';
        $this->addView($v, 'RemesaSepa', 'sepa-remittances', 'fa-solid fa-file-invoice-dollar')
            ->addSearchFields(['codigo'])
            ->addOrderBy(['fecha'], 'date', 2)
            ->addOrderBy(['total'], 'total')
            ->setSettings('btnNew', false);
        $this->addFilterSelectWhere($v, 'status', [
            ['label' => Tools::trans('all'), 'where' => []],
            ['label' => Tools::trans('sepa-status-generada'), 'where' => [Where::eq('estado', 'generada')]],
            ['label' => Tools::trans('sepa-status-cerrada'), 'where' => [Where::eq('estado', 'cerrada')]],
            ['label' => Tools::trans('sepa-status-anulada'), 'where' => [Where::eq('estado', 'anulada')]],
        ]);
    }

    private function createPendientes(): void
    {
        $v = 'ListReciboPendienteSepa';
        $this->addView($v, 'ReciboCliente', 'sepa-pending-receipts', 'fa-solid fa-dollar-sign')
            ->addSearchFields(['codigofactura', 'codcliente'])
            ->addOrderBy(['vencimiento'], 'expiration', 1)
            ->addOrderBy(['importe'], 'amount')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->tab($v)->addButton([
            'action' => 'sepa-crear-remesa',
            'color' => 'success',
            'confirm' => 'true',
            'icon' => 'fa-solid fa-file-export',
            'label' => 'sepa-create-remittance',
            'type' => 'action',
        ]);
    }

    private function createMandatos(): void
    {
        $this->addView('ListMandatoSepa', 'MandatoSepa', 'sepa-mandates', 'fa-solid fa-file-signature')
            ->addSearchFields(['referencia', 'codcliente'])
            ->addOrderBy(['idmandato'], 'code', 2)
            ->addOrderBy(['fechafirma'], 'sepa-signature-date')
            ->addFilterSelectWhere('status', [
                ['label' => Tools::trans('all'), 'where' => []],
                ['label' => Tools::trans('sepa-status-activo'), 'where' => [Where::eq('estado', 'activo')]],
                ['label' => Tools::trans('sepa-status-revocado'), 'where' => [Where::eq('estado', 'revocado')]],
            ]);
    }

    private function createAcreedores(): void
    {
        $this->addView('ListAcreedorSepa', 'AcreedorSepa', 'sepa-creditor', 'fa-solid fa-landmark')
            ->addSearchFields(['nombre', 'aci'])
            ->addOrderBy(['idempresa'], 'company');
    }

    protected function execPreviousAction($action)
    {
        if ($this->moduloBloqueado()) {
            return false;
        }
        if ($action === 'sepa-crear-remesa') {
            $this->crearRemesaAction();
        }
        return parent::execPreviousAction($action);
    }

    private function crearRemesaAction(): void
    {
        if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }
        $codes = (array)$this->request->request->getArray('codes');
        if (empty($codes)) {
            Tools::log()->warning('no-selected-item');
            return;
        }

        [$remesa, $errores] = RemesaService::crear($codes, null, (string)$this->user->nick);
        if (null === $remesa) {
            foreach ($errores as $e) {
                Tools::log()->warning($this->errorSepa($e));
            }
            return;
        }
        Tools::log()->notice('sepa-remittance-created');
        $this->redirect($remesa->url('edit'));
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'ListReciboPendienteSepa':
                $view->loadData('', RemesaService::filtroPendientes());
                // aviso si faltan datos del acreedor de la empresa
                foreach (AcreedorSepa::faltas((int)Tools::settings('default', 'idempresa', 1)) as $falta) {
                    Tools::log()->warning($falta);
                }
                break;

            case 'ListMandatoSepa':
            case 'ListAcreedorSepa':
                parent::loadData($viewName, $view);
                MascaraIban::aplicar($view);
                break;

            default:
                parent::loadData($viewName, $view);
        }
    }
}
