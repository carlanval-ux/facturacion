<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Extension\Controller\EditFacturaCliente;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Extension\Controller\ListFacturaCliente;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Modulo;

class Init extends InitClass
{
    public function init(): void
    {
        // sin el módulo contratado no se cuelga ningún botón
        if (Modulo::activo()) {
            $this->loadExtension(new ListFacturaCliente());
            $this->loadExtension(new EditFacturaCliente());
        }
    }

    public function uninstall(): void
    {
        // se conserva el registro de auditoría
    }

    public function update(): void
    {
    }
}
