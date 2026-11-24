<?php
/**
 * AutoEnvioFacturas - Envía automáticamente por email las facturas emitidas (compatible con Verifactu). Nunca envía bocetos y, en ejercicios en modo Verifactu, solo envía facturas ya registradas en Verifactu.
 *
 * Copyright (C) 2026 TI53, SLU - Antonio Martín Buitrago Laguna <antoniomartin@ti53.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @author    Antonio Martín Buitrago Laguna <antoniomartin@ti53.es>
 * @copyright 2026 TI53, SLU
 * @license   LGPL-3.0-or-later
 */

/**
 * Envía automáticamente por email las facturas emitidas.
 */

namespace FacturaScripts\Plugins\AutoEnvioFacturas;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\AutoEnvioFacturas\Lib\AutoInvoiceMailer;

class Cron extends CronClass
{
    public function run(): void
    {
        // El plugin Verifactu exige el cron del sistema cada minuto, así que
        // este trabajo también corre cada minuto: en cuanto la AEAT acepta el
        // alta (QR disponible), el email sale en la siguiente pasada.
        //
        // IMPORTANTE: withoutOverlapping se llama CON nuestro propio nombre
        // (auto-exclusión: nunca dos envíos en paralelo). Sin argumentos
        // bloquearía este trabajo mientras cualquier otro job estuviera en
        // ejecución, y con los jobs de Verifactu corriendo cada 60-90
        // segundos este trabajo casi nunca llegaría a ejecutarse.
        $this->job('auto-envio-facturas')
            ->every('60 seconds')
            ->withoutOverlapping('auto-envio-facturas')
            ->run(function () {
                AutoInvoiceMailer::run();
            });
    }
}
