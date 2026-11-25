<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Facturalo\Lib\UnicaEmpresa;

/** Oculta el boton «Nuevo» del listado de empresas (una sola empresa por tenant). */
class ListEmpresa
{
    public function createViews(): Closure
    {
        return function () {
            if (UnicaEmpresa::bloqueaAlta()) {
                $this->setSettings('ListEmpresa', 'btnNew', false);
            }
        };
    }
}
