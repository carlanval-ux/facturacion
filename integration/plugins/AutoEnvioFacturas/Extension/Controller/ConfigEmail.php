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
 * Extensión del controlador del núcleo ConfigEmail (Administrador → Emails).
 * Cuelga dentro de esa misma página dos pestañas:
 * - "Envío de facturas por email": los ajustes del plugin (grupo de settings
 * propio 'autoenviofacturas', sin acoplarse al grupo 'email' del core).
 * - "Historial de envíos": el log de envíos, con botones para reintentar
 *   envíos fallidos y reenviar facturas ya enviadas.
 */

namespace FacturaScripts\Plugins\AutoEnvioFacturas\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AutoEnvioFacturas\Lib\AutoInvoiceMailer;
use FacturaScripts\Plugins\AutoEnvioFacturas\Model\EnvioFacturaEmail;

class ConfigEmail
{
    /**
     * Se ejecuta después del createViews() del core (vía pipe).
     * $this es la instancia de ConfigEmail (PanelController).
     */
    public function createViews(): Closure
    {
        return function () {
            // Pestaña de ajustes: EditView sobre el modelo Settings.
            // El grupo de almacenamiento se fija en loadData() con model->name.
            $this->addEditView('EditAutoEnvioFacturas', 'Settings', 'invoice-email-sending', 'fa-solid fa-envelope')
                ->setSettings('btnNew', false)
                ->setSettings('btnDelete', false);

            // Pestaña de historial: ListView de solo lectura sobre el log.
            // Sin botón Nuevo y sin filas clicables (no hay controlador de edición).
            $this->addListView('ListEnvioFacturaEmail', 'EnvioFacturaEmail', 'sending-history', 'fa-solid fa-clock-rotate-left')
                ->addSearchFields(['codigo', 'email', 'mensaje'])
                ->addOrderBy(['fecha'], 'date', 2)
                ->addOrderBy(['codigo'], 'code')
                ->addFilterSelect('resultado', 'result', 'resultado', [
                    ['code' => '', 'description' => '------'],
                    ['code' => 'enviado', 'description' => 'enviado'],
                    ['code' => 'error', 'description' => 'error'],
                    ['code' => 'sin-email', 'description' => 'sin-email'],
                    ['code' => 'esperando-verifactu', 'description' => 'esperando-verifactu'],
                    ['code' => 'programado', 'description' => 'programado'],
                    ['code' => 'reenvio', 'description' => 'reenvio'],
                ])
                ->setSettings('btnNew', false)
                ->setSettings('clickable', false);

            // Botones sobre las filas marcadas. No envían nada al momento:
            // dejan la fila lista para la siguiente pasada del cron.
            $this->addButton('ListEnvioFacturaEmail', [
                'action' => 'autoenvio-retry',
                'color' => 'warning',
                'icon' => 'fa-solid fa-rotate-right',
                'label' => 'retry-sending',
            ]);
            $this->addButton('ListEnvioFacturaEmail', [
                'action' => 'autoenvio-resend',
                'color' => 'info',
                'icon' => 'fa-solid fa-paper-plane',
                'label' => 'resend-invoice',
                'confirm' => true,
            ]);
        };
    }

    /**
     * Acciones de los botones del historial (vía pipeFalse, después de las del core).
     */
    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action !== 'autoenvio-retry' && $action !== 'autoenvio-resend') {
                return true;
            }

            if (false === $this->validateFormToken()) {
                return true;
            } elseif (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
                return true;
            }

            $codes = $this->request->request->getArray('codes');
            if (empty($codes) || false === is_array($codes)) {
                Tools::log()->warning('no-selected-item');
                return true;
            }

            $done = 0;
            foreach ($codes as $code) {
                $log = new EnvioFacturaEmail();
                if (false === $log->load($code)) {
                    continue;
                }

                $problem = $action === 'autoenvio-retry' ?
                    AutoInvoiceMailer::requestRetry($log, $this->user->nick) :
                    AutoInvoiceMailer::requestResend($log, $this->user->nick);
                if ('' === $problem) {
                    $done++;
                    continue;
                }

                Tools::log()->warning('autoenvio-not-applied', ['%code%' => $log->codigo, '%reason%' => $problem]);
            }

            if ($done > 0) {
                Tools::log()->notice('autoenvio-requests-applied', ['%count%' => $done]);
            }
            return true;
        };
    }

    /**
     * Se ejecuta después del loadData() del core (vía pipeFalse).
     * Solo actúa sobre nuestras vistas; el resto lo maneja el core.
     */
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            switch ($viewName) {
                case 'EditAutoEnvioFacturas':
                    // Cargamos/guardamos siempre contra el grupo de settings propio.
                    $view->loadData('autoenviofacturas');
                    $view->model->name = 'autoenviofacturas';
                    break;

                case 'ListEnvioFacturaEmail':
                    $view->loadData();
                    break;
            }
        };
    }
}
