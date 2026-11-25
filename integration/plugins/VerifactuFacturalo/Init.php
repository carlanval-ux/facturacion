<?php
/**
 * VerifactuFacturalo - VERI*FACTU propio (clean-room, desde la documentación pública de la AEAT). Licencia LGPL-3.0-or-later.
 * Incompatible con el plugin comercial «Verifactu»: si ese está activo, este no engancha nada.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Servicio;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpCertificado;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpConfig;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpEvento;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpRegistro;

final class Init extends InitClass
{
    public function init(): void
    {
        if (Servicio::conflicto()) {
            return; // el plugin comercial manda: nada de registros duplicados ni bloqueos dobles
        }
        $this->loadExtension(new Extension\Model\FacturaCliente());
        $this->loadExtension(new Extension\Controller\EditEmpresa());
        $this->loadExtension(new Extension\Lib\PDF\PDFDocument());
    }

    public function update(): void
    {
        // crea las tablas vfp_*
        new VfpConfig();
        new VfpCertificado();
        new VfpRegistro();
        new VfpEvento();
        if (Servicio::conflicto()) {
            Tools::log()->warning('vfp-conflict');
        }
    }

    public function uninstall(): void
    {
        // se conservan registros y huellas: son un registro legal que no debe perderse al desactivar el plugin
    }
}
