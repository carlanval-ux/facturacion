<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Anticipos;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\ModuloActivo;

/** Anticipos de todos los clientes (filtra por cliente, estado y fecha) y ajuste de la serie de las facturas de anticipo. */
class ListAnticipo extends ListController
{
    const VISTA = 'ListAnticipo';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'anticipos';
        $data['icon'] = 'fa-solid fa-hand-holding-dollar';
        $data['showonmenu'] = ModuloActivo::estaActivo('anticipos');
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        if (false === ModuloActivo::estaActivo('anticipos')) {
            parent::privateCore($response, $user, $permissions);
            Tools::log()->warning('modulo-no-activo');
            $this->redirect('Dashboard');
            return;
        }
        parent::privateCore($response, $user, $permissions);
    }

    protected function createViews()
    {
        $this->addView(self::VISTA, 'Anticipo', 'anticipos', 'fa-solid fa-hand-holding-dollar')
            ->addSearchFields(['codigodoc', 'observaciones'])
            ->addOrderBy(['fecha', 'id'], 'date', 2);

        $this->addFilterAutocomplete(self::VISTA, 'codcliente', 'customer', 'codcliente', 'clientes', 'codcliente', 'nombre');
        $this->addFilterSelectWhere(self::VISTA, 'estado', [
            ['label' => Tools::trans('anticipo-pendiente'), 'where' => [Where::eq('estado', 'pendiente')]],
            ['label' => Tools::trans('anticipo-aplicado'), 'where' => [Where::eq('estado', 'aplicado')]],
            ['label' => Tools::trans('anticipo-devuelto'), 'where' => [Where::eq('estado', 'devuelto')]]
        ], 'anticipo-estado');
        $this->addFilterPeriod(self::VISTA, 'fecha', 'date', 'fecha');

        $this->setSettings(self::VISTA, 'btnNew', false);
        $this->setSettings(self::VISTA, 'btnDelete', false);
        $this->tab(self::VISTA)->addButton(['type' => 'modal', 'action' => 'ajustes', 'icon' => 'fa-solid fa-cog', 'label' => 'anticipo-ajustes']);
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'ajustes') {
            $cod = (string)$this->request->input('codserie', '');
            if (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
            } elseif ($this->validateFormToken()) {
                if ($cod === '' || false === (new Serie())->load($cod)) {
                    Tools::log()->warning('record-not-found');
                } else {
                    Tools::settingsSet('anticipos', 'codserie', $cod);
                    Tools::settingsSave();
                    Tools::log()->notice('record-updated-correctly');
                }
            }
        }
        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);
        if ($viewName === self::VISTA) {
            $view->model->codserie = Anticipos::serie(); // valor mostrado en el modal de ajustes
            Anticipos::avisarPendientes([]);
        }
    }
}
