<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\MandatoPdf;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\MascaraIban;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloSepaTrait;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\MandatoSepa;

/** Ficha del mandato SEPA con descarga del documento PDF para firmar. */
class EditMandatoSepa extends EditController
{
    use ModuloSepaTrait;

    public function getModelClassName(): string
    {
        return 'MandatoSepa';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'sepa-mandate';
        $data['icon'] = 'fa-solid fa-file-signature';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function execPreviousAction($action)
    {
        if ($this->moduloBloqueado()) {
            return false;
        }
        if ($action === 'sepa-mandato-pdf') {
            $m = new MandatoSepa();
            if ($m->load((string)$this->request->queryOrInput('code'))) {
                $this->setTemplate(false);
                // el IBAN completo en el documento solo para administradores
                $this->response->pdf((new MandatoPdf())->generar($m, (bool)$this->user->admin), 'mandato-' . $m->idmandato . '.pdf');
                return false;
            }
        }
        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);
        if ($viewName === $this->getMainViewName()) {
            MascaraIban::aplicar($view);
            if ($view->model->exists()) {
                $view->addButton([
                    'action' => 'sepa-mandato-pdf',
                    'color' => 'info',
                    'icon' => 'fa-solid fa-file-pdf',
                    'label' => 'sepa-mandate-pdf',
                    'type' => 'action',
                ]);
            }
        }
    }
}
