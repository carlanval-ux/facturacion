<?php
/**
 * VerifactuFacturalo - Cron: registra las facturas emitidas que falten y envía/reintenta los registros pendientes. Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Servicio;

class Cron extends CronClass
{
    public function run(): void
    {
        $this->job('verifactu-facturalo')
            ->every('5 minutes')
            ->withoutOverlapping('verifactu-facturalo')
            ->run(function () {
                Servicio::cron();
            });
    }
}
