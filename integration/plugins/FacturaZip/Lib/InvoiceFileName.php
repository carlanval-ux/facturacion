<?php

namespace FacturaScripts\Plugins\FacturaZip\Lib;

use FacturaScripts\Dinamic\Model\FacturaCliente;

final class InvoiceFileName
{
    public static function pdf(FacturaCliente $invoice): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $invoice->codigo);
        return trim((string)$name, '._') . '.pdf';
    }
}
