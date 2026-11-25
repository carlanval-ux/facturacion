<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Facturalo\Lib\UnicaEmpresa;

/** Una sola empresa por tenant: aplica a UI, API e importaciones (todo pasa por save()/delete()). */
class Empresa
{
    public function saveBefore(): Closure
    {
        return function () {
            if (empty($this->id()) && UnicaEmpresa::bloqueaAlta()) {
                Tools::log()->error('facturalo-empresa-unica');
                return false;
            }
            return true;
        };
    }

    public function deleteBefore(): Closure
    {
        return function () {
            if (UnicaEmpresa::bloqueaBaja()) {
                Tools::log()->error('facturalo-empresa-unica-baja');
                return false;
            }
            return true;
        };
    }
}
