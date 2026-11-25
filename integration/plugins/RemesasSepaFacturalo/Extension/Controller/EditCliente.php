<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\MascaraIban;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\ModuloActivo;

/** Pestaña «Mandatos SEPA» en la ficha del cliente. */
class EditCliente
{
    public function createViews(): Closure
    {
        return function () {
            if (!ModuloActivo::estaActivo('sepa')) {
                return;
            }
            $this->addListView('ListMandatoSepa', 'MandatoSepa', 'sepa-mandates', 'fa-solid fa-file-signature')
                ->setSettings('btnDelete', false);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'ListMandatoSepa') {
                return;
            }
            $view->loadData('', [Where::eq('codcliente', $this->getViewModelValue($this->getMainViewName(), 'codcliente'))]);
            MascaraIban::aplicar($view);
        };
    }
}
