<?php
/**
 * RecordatorioImpagosFacturalo - Cron: reclamación automática de recibos pendientes.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\Recordatorios;

class Cron extends CronClass
{
    public function run(): void
    {
        // Cada 10 minutos: el horario de envío y la idempotencia los controla el motor.
        // withoutOverlapping con nuestro propio nombre: nunca dos pasadas a la vez.
        $this->job('recordatorio-impagos')
            ->every('10 minutes')
            ->withoutOverlapping('recordatorio-impagos')
            ->run(function () {
                Recordatorios::run();
            });
    }
}
