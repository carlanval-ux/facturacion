<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Vista;

/** Pestaña de anticipos del cliente (solo consulta). */
class EditCliente
{
    public function createViews(): Closure
    {
        return function () {
            Vista::busqueda($this->addListView(Vista::TAB, 'Anticipo', 'anticipos', 'fa-solid fa-hand-holding-dollar'));
            Vista::ajustar($this, false);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            Vista::cargar($this, $viewName, $view, false);
        };
    }
}
