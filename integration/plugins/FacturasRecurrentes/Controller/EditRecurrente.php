<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\FacturasRecurrentes\Lib\Generador;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\Recurrente;

class EditRecurrente extends EditController
{
    public function getModelClassName(): string
    {
        return 'Recurrente';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'recurring-template';
        $data['icon'] = 'fa-solid fa-rotate';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');
        $this->tab('EditRecurrente')->addButton([
            'action' => 'generar-ahora',
            'confirm' => 'true',
            'icon' => 'fa-solid fa-bolt',
            'label' => 'recurring-generate-now',
        ]);
        $this->addEditListView('EditRecurrenteLinea', 'RecurrenteLinea', 'lines', 'fa-solid fa-list');
        $this->addListView('ListRecurrenteMiembro', 'Recurrente', 'recurring-members', 'fa-solid fa-users')
            ->addOrderBy(['nombre'], 'name');
        $this->addListView('ListRecurrenteHistorial', 'RecurrenteHistorial', 'recurring-history', 'fa-solid fa-clock-rotate-left')
            ->addOrderBy(['idhistorial'], 'date', 2);
        $this->setSettings('ListRecurrenteMiembro', 'btnNew', false);
        $this->setSettings('ListRecurrenteHistorial', 'btnNew', false);
        $this->setSettings('ListRecurrenteHistorial', 'btnDelete', false);
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'generar-ahora') {
            $r = new Recurrente();
            if (false === $r->load($this->request->input('code', $this->request->query('code')))) {
                return true;
            }
            $miembros = $r->esgrupo ? Recurrente::all([Where::eq('idgrupo', $r->idrecurrente), Where::eq('activo', true)], [], 0, 0) : [$r];
            foreach ($miembros as $m) {
                $res = Generador::generarAhora($m);
                Tools::log()->{$res === 'ok' ? 'notice' : 'warning'}($res === 'ok' ? 'recurring-generated' : 'recurring-generate-failed');
            }
            return true;
        }
        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        $id = $this->mainTabModelValue('idrecurrente');
        switch ($viewName) {
            case 'EditRecurrenteLinea':
                $view->loadData('', [Where::eq('idrecurrente', $id)], ['idlinea' => 'ASC']);
                break;
            case 'ListRecurrenteMiembro':
                $view->loadData('', [Where::eq('idgrupo', $id)]);
                break;
            case 'ListRecurrenteHistorial':
                $view->loadData('', [Where::eq('idrecurrente', $id)]);
                break;
            default:
                parent::loadData($viewName, $view);
        }
    }
}
