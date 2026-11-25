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

namespace FacturaScripts\Plugins\AutoEnvioFacturas\Lib;

use FacturaScripts\Core\Plugins;
use FacturaScripts\Dinamic\Model\FacturaCliente;

/**
 * Puerta de seguridad Verifactu. Verificado contra el plugin Verifactu
 * oficial v1.1 y v1.21. Matriz de casos:
 *
 *  1. Plugin Verifactu no instalado/activo ............ emitida basta.
 *  2. Ejercicio con vf_mode vacío (sin configurar) .... emitida basta.
 *  3. Ejercicio en modo 'verifactu' ................... solo se envía cuando
 *     el alta está aceptada por la AEAT (verifactuCheckAlta) y no está
 *     anulada ni tiene una anulación en curso.
 *  4. Ejercicio en modo 'no-verifactu' ................ emitida basta, con
 *     un matiz: si existe un alta PENDIENTE de procesar (el estado tenía
 *     activada la opción "Enviar a Verifactu"), se espera a la siguiente
 *     pasada del cron (≤1 minuto) para que el PDF salga ya con su QR. Si no
 *     existe ningún registro de alta (empresa sin configurar, estado sin la
 *     opción de envío, cliente que aún no usa Verifactu), se envía
 *     directamente: nunca va a existir un QR al que esperar.
 *  En los modos 3 y 4, una factura anulada, o con un registro de anulación
 *  aún sin procesar, no se envía nunca.
 *
 * Sin carreras: el registro de alta se crea de forma SÍNCRONA en el onChange
 * del campo idestado del plugin Verifactu (Extension/Model/FacturaCliente.php),
 * en la misma petición que pasa la factura a emitida. Por tanto, cuando el
 * cron ve la factura emitida, el registro pendiente ya existe si es que va a
 * existir.
 *
 * Relación con el QR del PDF (Extension/Lib/PDF/PDFDocument.php):
 * - v1.1: el QR aparece con verifactuCheckAlta() && !verifactuCheckAnulacion().
 * - v1.21: el QR aparece en cuanto la factura tiene CUALQUIER registro y no
 *   está anulada (también con el alta pendiente).
 * La puerta es igual de estricta o más que las dos: en modo verifactu espera
 * a la aceptación de la AEAT para no mandar un QR de un alta que luego se
 * rechace. Así, el email nunca sale sin QR cuando la factura lo va a tener.
 *
 * NO usamos el campo vf_sent porque solo se activa en modo verifactu tras la
 * aceptación de la AEAT, nunca en modo no-verifactu, y no refleja
 * anulaciones. verifactuCheckAlta() cubre además vf_manual_alta (facturas
 * marcadas como enviadas desde un tercero).
 */
class VerifactuGate
{
    public static function canSend(FacturaCliente $invoice): bool
    {
        // 1. sin plugin Verifactu activo, emitida basta
        if (false === Plugins::isEnabled('Verifactu')) {
            return true;
        }

        // 2. ejercicio sin modo Verifactu configurado, emitida basta
        $ejercicio = $invoice->getExercise();
        if (empty($ejercicio->vf_mode)) {
            return true;
        }

        // nunca enviamos facturas anuladas en Verifactu, ni con una anulación
        // en curso (registro de anulación aún sin respuesta o sin procesar)
        if ($invoice->verifactuCheckAnulacion()) {
            return false;
        }
        foreach ($invoice->verifactuGetRegistroFactura() as $registro) {
            if ($registro->event === 'Anulacion' && empty($registro->status)) {
                return false;
            }
        }

        // 3. modo 'verifactu': solo con el alta aceptada por la AEAT (QR en PDF)
        if ($ejercicio->vf_mode === 'verifactu') {
            return $invoice->verifactuCheckAlta();
        }

        // 4. modo 'no-verifactu':
        // alta ya procesada: el PDF ya lleva el QR, enviamos
        if ($invoice->verifactuCheckAlta()) {
            return true;
        }

        // alta pendiente de procesar: esperamos a la siguiente pasada para
        // que el PDF salga con su QR ('Alta' = VerifactuRegistroFactura::EVENT_ALTA)
        foreach ($invoice->verifactuGetRegistroFactura() as $registro) {
            if ($registro->event === 'Alta' && empty($registro->status)) {
                return false;
            }
        }

        // sin ningún registro de alta: nunca habrá QR, emitida basta
        return true;
    }
}
