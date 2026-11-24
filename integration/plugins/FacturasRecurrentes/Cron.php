<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\FacturasRecurrentes\Lib\Generador;

final class Cron extends CronClass
{
    public function run(): void
    {
        $this->job('generar-recurrentes')->every('5 minutes')->run(function () {
            Generador::procesar();
        });
    }
}
