<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo;

use FacturaScripts\Core\Template\InitClass;

/** Remesas SEPA (cobro domiciliado). Módulo comercial «sepa». */
final class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditCliente());
    }

    public function uninstall(): void
    {
        // se conservan mandatos y remesas: son datos legales de cobro
    }

    public function update(): void
    {
    }
}
