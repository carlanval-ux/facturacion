<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\MascaraIban;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloSepaTrait;

/** Datos del acreedor SEPA de una empresa: ACI, cuenta de cargo y plazos de presentación. */
class EditAcreedorSepa extends EditController
{
    use ModuloSepaTrait;

    public function getModelClassName(): string
    {
        return 'AcreedorSepa';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'sepa-creditor';
        $data['icon'] = 'fa-solid fa-landmark';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function execPreviousAction($action)
    {
        return $this->moduloBloqueado() ? false : parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);
        if ($viewName === $this->getMainViewName()) {
            MascaraIban::aplicar($view);
        }
    }
}
