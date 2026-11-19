<?php
namespace FacturaScripts\Plugins\TelecoBridge;

use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;

class Init extends InitClass
{
    public function init(): void
    {
        ApiRoot::addCustomResource('telecoFacturaCliente');
        Kernel::addRoute('/api/3/telecoFacturaCliente', '\FacturaScripts\Dinamic\Controller\ApiTelecoFacturaCliente');
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        new \FacturaScripts\Dinamic\Model\TelecoIdempotencia(); // crea la tabla si falta
    }
}
