<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Lib\AjaxForms\SalesFooterHTML;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Aceptacion;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Mod\AceptacionBotones;

final class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\ListPresupuestoCliente());
        SalesFooterHTML::addMod(new AceptacionBotones());
    }

    public function update(): void
    {
        // estado "Aceptado" de los presupuestos (no editable, sin generar documento)
        try {
            Aceptacion::estadoAceptado();
        } catch (\Throwable $e) {
            // se crea al primer uso
        }
    }

    public function uninstall(): void
    {
    }
}
