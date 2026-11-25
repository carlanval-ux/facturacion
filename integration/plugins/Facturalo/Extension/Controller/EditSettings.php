<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Facturalo\Lib\Staff;

/**
 * La API (activarla y gestionar sus claves) y las secuencias las controla solo el personal de plataforma:
 * el cliente no ve las pestañas «Claves API» ni «Secuencias» ni el ajuste «Activar API».
 */
class EditSettings
{
    public function createViews(): Closure
    {
        return function () {
            if (Staff::isStaff($this->user->nick ?? null)) {
                return;
            }
            unset($this->views['ListApiKey'], $this->views['ListSecuenciaDocumento']);
        };
    }

    /** Las columnas de la vista se cargan aqui, no en createViews. */
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ('SettingsDefault' === $viewName && false === Staff::isStaff($this->user->nick ?? null)) {
                $view->disableColumn('enable-api');
            }
        };
    }
}
