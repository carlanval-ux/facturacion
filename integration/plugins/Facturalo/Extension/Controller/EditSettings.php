<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Facturalo\Lib\Staff;

/**
 * La API (activarla y gestionar sus claves) la controla solo el personal de plataforma:
 * el cliente no ve la pestaña «Claves API» ni el ajuste «Activar API»; tampoco «Secuencias»: la numeración la gobierna la plataforma.
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
            if (isset($this->views['SettingsDefault'])) {
                $this->views['SettingsDefault']->disableColumn('enable_api');
            }
        };
    }
}
