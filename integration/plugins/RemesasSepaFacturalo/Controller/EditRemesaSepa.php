<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloSepaTrait;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\RemesaService;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\RemesaSepa;

/**
 * Remesa: descarga del fichero, confirmación de cobros y devoluciones (se editan en las líneas),
 * exportación y anulación.
 */
class EditRemesaSepa extends PanelController
{
    use ModuloSepaTrait;

    public function getModelClassName(): string
    {
        return 'RemesaSepa';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'sepa-remittance';
        $data['icon'] = 'fa-solid fa-file-invoice-dollar';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews()
    {
        $this->addEditView('EditRemesaSepa', 'RemesaSepa', 'sepa-remittance', 'fa-solid fa-file-invoice-dollar')
            ->setSettings('btnDelete', false)
            ->setSettings('btnSave', false);
        $this->addEditListView('EditRemesaSepaRecibo', 'RemesaSepaRecibo', 'sepa-remittance-receipts', 'fa-solid fa-list')
            ->setSettings('btnDelete', false)
            ->setSettings('btnNew', false);
    }

    protected function execPreviousAction($action)
    {
        if ($this->moduloBloqueado()) {
            return false;
        }
        $remesa = new RemesaSepa();
        $code = (string)$this->request->queryOrInput('code');
        if (in_array($action, ['sepa-xml', 'sepa-csv', 'sepa-anular'], true) && $remesa->load($code)) {
            switch ($action) {
                case 'sepa-xml':
                    return $this->descargar($remesa->xml, 'application/xml', $remesa->codigo . '.xml');
                case 'sepa-csv':
                    return $this->descargar(RemesaService::csv($remesa), 'text/csv; charset=UTF-8', $remesa->codigo . '.csv');
                case 'sepa-anular':
                    if (false === $this->validateFormToken() || false === $this->permissions->allowUpdate) {
                        return true;
                    }
                    if (RemesaService::anular($remesa)) {
                        Tools::log()->notice('sepa-remittance-cancelled');
                    } else {
                        Tools::log()->warning('sepa-cannot-cancel');
                    }
                    return true;
            }
        }
        return parent::execPreviousAction($action);
    }

    private function descargar(string $contenido, string $tipo, string $nombre): bool
    {
        $this->setTemplate(false);
        $this->response->headers->set('Content-Type', $tipo);
        $this->response->headers->set('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) . '"');
        $this->response->setContent($contenido);
        return false;
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'EditRemesaSepa':
                $view->loadData($this->request->query('code', $this->request->input('idremesa')));
                if ($view->model->exists()) {
                    foreach ([
                        ['sepa-xml', 'fa-solid fa-file-code', 'sepa-download-xml', 'info'],
                        ['sepa-csv', 'fa-solid fa-file-csv', 'sepa-export-csv', 'secondary'],
                    ] as [$accion, $icono, $etiqueta, $color]) {
                        $view->addButton(['action' => $accion, 'icon' => $icono, 'label' => $etiqueta, 'color' => $color, 'type' => 'action']);
                    }
                    if ($view->model->estado === 'generada') {
                        $view->addButton([
                            'action' => 'sepa-anular', 'icon' => 'fa-solid fa-ban', 'label' => 'sepa-cancel-remittance',
                            'color' => 'danger', 'confirm' => 'true', 'type' => 'action',
                        ]);
                    }
                }
                break;

            case 'EditRemesaSepaRecibo':
                $view->loadData('', [Where::eq('idremesa', $this->getViewModelValue('EditRemesaSepa', 'idremesa'))], ['id' => 'ASC']);
                break;
        }
    }
}
