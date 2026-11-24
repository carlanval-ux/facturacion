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

use FacturaScripts\Core\DataSrc\FormasPago;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\CuentaBanco;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use Throwable;

/**
 * Marcadores que se sustituyen en el asunto y el texto de la notificación
 * autoenviofacturas-factura. Cada marcador es siempre un texto (vacío si no
 * hay dato), para que nunca quede un {marcador} sin sustituir en el email.
 */
class InvoiceMailParams
{
    /**
     * Lista de marcadores disponibles (para la ayuda de la pestaña de ajustes
     * y la documentación). El orden es el de la documentación.
     */
    const MARKERS = [
        'name', 'email', 'code', 'date', 'total', 'company',
        'payment_method', 'due_date', 'bank_account',
    ];

    public static function build(FacturaCliente $invoice, string $email): array
    {
        $company = $invoice->getCompany();
        $paymentMethod = static::paymentMethod($invoice);

        return [
            'name' => (string)($invoice->nombrecliente ?? ''),
            'email' => $email,
            'code' => (string)$invoice->codigo,
            'date' => (string)$invoice->fecha,
            'total' => Tools::money($invoice->total, $invoice->coddivisa ?? ''),
            'company' => (string)($company->nombrecorto ?? $company->nombre ?? ''),
            'payment_method' => (string)($paymentMethod->descripcion ?? ''),
            'due_date' => static::dueDate($invoice, $paymentMethod),
            'bank_account' => static::bankAccount($invoice, $paymentMethod),
        ];
    }

    /**
     * Forma de pago asignada a la factura (vacía si no tiene o no existe).
     */
    protected static function paymentMethod(FacturaCliente $invoice): FormaPago
    {
        if (empty($invoice->codpago)) {
            return new FormaPago();
        }

        return FormasPago::get($invoice->codpago);
    }

    /**
     * Vencimiento: el del primer recibo pendiente de pago; si todos están
     * pagados, el del primer recibo; si no hay recibos, el que calcula la
     * forma de pago a partir de la fecha de la factura.
     */
    protected static function dueDate(FacturaCliente $invoice, FormaPago $paymentMethod): string
    {
        try {
            $receipts = $invoice->getReceipts();
        } catch (Throwable $exc) {
            $receipts = [];
        }

        $first = '';
        $firstPending = '';
        foreach ($receipts as $receipt) {
            if (empty($receipt->vencimiento)) {
                continue;
            }
            if ('' === $first || strtotime($receipt->vencimiento) < strtotime($first)) {
                $first = $receipt->vencimiento;
            }
            if (false === (bool)$receipt->pagado
                && ('' === $firstPending || strtotime($receipt->vencimiento) < strtotime($firstPending))) {
                $firstPending = $receipt->vencimiento;
            }
        }

        if ('' !== $firstPending) {
            return Tools::date($firstPending);
        }
        if ('' !== $first) {
            return Tools::date($first);
        }
        if ($paymentMethod->exists() && false === empty($invoice->fecha)) {
            return $paymentMethod->getExpiration($invoice->fecha);
        }

        return (string)$invoice->fecha;
    }

    /**
     * Cuenta bancaria para el pago:
     * - forma de pago domiciliada: la cuenta del cliente donde se cargará el
     *   recibo, censurada (ES91 XXXX XXXX XXXX XXXX 1332), la principal o la primera;
     * - resto: el IBAN completo de la cuenta de la empresa asociada a la forma
     *   de pago (por ejemplo, para una transferencia).
     * Vacío si no hay cuenta.
     */
    protected static function bankAccount(FacturaCliente $invoice, FormaPago $paymentMethod): string
    {
        if (false === $paymentMethod->exists()) {
            return '';
        }

        if ($paymentMethod->domiciliado) {
            $client = $invoice->getSubject();
            if (false === $client->exists()) {
                return '';
            }

            $selected = null;
            foreach ($client->getBankAccounts() as $account) {
                if (empty($account->iban)) {
                    continue;
                }
                if (null === $selected || $account->principal) {
                    $selected = $account;
                }
                if ($account->principal) {
                    break;
                }
            }
            return null === $selected ? '' : $selected->getIban(true, true);
        }

        if (empty($paymentMethod->codcuentabanco)) {
            return '';
        }

        $bank = new CuentaBanco();
        if (false === $bank->load($paymentMethod->codcuentabanco)) {
            return '';
        }

        return $bank->getIban(true);
    }
}
