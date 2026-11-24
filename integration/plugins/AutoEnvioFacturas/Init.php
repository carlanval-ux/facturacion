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

namespace FacturaScripts\Plugins\AutoEnvioFacturas;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Plugins\AutoEnvioFacturas\Extension\Controller\ConfigEmail as ConfigEmailExtension;

class Init extends InitClass
{
    public function init(): void
    {
        // Colgamos los ajustes y el historial dentro de Administrador → Emails.
        $this->loadExtension(new ConfigEmailExtension());
    }

    public function uninstall(): void
    {
        // conservamos la tabla de log y los ajustes por si se reinstala
    }

    public function update(): void
    {
        $this->setDefaultSettings();
        $this->createEmailNotification();
    }

    private function createEmailNotification(): void
    {
        // Notificación editable por el usuario desde Administrador → Emails → Notificaciones
        $notification = new EmailNotification();
        if ($notification->load('autoenviofacturas-factura')) {
            return;
        }

        $notification->name = 'autoenviofacturas-factura';
        $notification->subject = 'Su factura {code}';
        $notification->body = 'Estimado/a {name},<br/><br/>'
            . 'Le adjuntamos su factura {code}, de fecha {date}, por importe de {total}.<br/><br/>'
            . 'Forma de pago: {payment_method}.<br/><br/>'
            . 'Gracias por su confianza.<br/>'
            . '{company}';
        $notification->enabled = true;
        $notification->save();
    }

    private function setDefaultSettings(): void
    {
        // Valor por defecto de cada ajuste que aún no exista: en una instalación
        // nueva se crean todos y al actualizar solo los nuevos, sin tocar los
        // que el usuario ya tenía.
        $defaults = [
            'enabled' => false,
            // por defecto, no tocar facturas anteriores a la activación del plugin
            'fromdate' => Tools::date(),
            'maxemails' => 25,
            'delayminutes' => 1,
            'rectificativas' => true,
            'cco' => '',
        ];

        $changed = false;
        foreach ($defaults as $key => $value) {
            if (null !== Tools::settings('autoenviofacturas', $key)) {
                continue;
            }

            Tools::settingsSet('autoenviofacturas', $key, $value);
            $changed = true;
        }

        if ($changed) {
            Tools::settingsSave();
        }
    }
}
