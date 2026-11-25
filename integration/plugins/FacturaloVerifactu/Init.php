<?php
namespace FacturaScripts\Plugins\FacturaloVerifactu;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Controller\EditEmpresa;
use FacturaScripts\Dinamic\Controller\EditFacturaCliente;

class Init extends InitClass
{
    public function init(): void
    {
        EditEmpresa::addExtension(new Extension\Controller\EditEmpresa());
        EditFacturaCliente::addExtension(new Extension\Controller\EditFacturaCliente());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
    }
}
