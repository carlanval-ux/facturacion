<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Vista;

/** Base de las extensiones de EditPresupuestoCliente, EditPedidoCliente y EditAlbaranCliente (en una extensión todos los métodos deben devolver un Closure). */
abstract class BaseDocumento
{
    public function createViews(): Closure
    {
        return function () {
            Vista::busqueda($this->addListView(Vista::TAB, 'Anticipo', 'anticipos', 'fa-solid fa-hand-holding-dollar'));
            Vista::ajustar($this, true);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            Vista::cargar($this, $viewName, $view, true);
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            // validateFormToken es protegido: se llama aquí y solo para la acción propia
            return Vista::accion($this, (string)$action, 'new-anticipo' !== $action || $this->validateFormToken());
        };
    }
}
