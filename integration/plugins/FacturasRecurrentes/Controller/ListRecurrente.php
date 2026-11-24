<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Where;

class ListRecurrente extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'recurring-documents';
        $data['icon'] = 'fa-solid fa-rotate';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListRecurrente', 'Recurrente', 'recurring-templates', 'fa-solid fa-rotate')
            ->addSearchFields(['nombre', 'codcliente', 'codproveedor'])
            ->addOrderBy(['proxima'], 'recurring-next')
            ->addOrderBy(['nombre'], 'name')
            ->addFilterCheckbox('activo', 'recurring-active', 'activo');
        $this->addView('ListRecurrenteGrupo', 'Recurrente', 'recurring-groups', 'fa-solid fa-layer-group')
            ->addSearchFields(['nombre'])
            ->addOrderBy(['nombre'], 'name');
    }

    protected function loadData($viewName, $view)
    {
        $view->loadData('', [Where::eq('esgrupo', $viewName === 'ListRecurrenteGrupo')]);
    }
}
