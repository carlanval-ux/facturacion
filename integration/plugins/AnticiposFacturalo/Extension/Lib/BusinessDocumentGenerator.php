<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Extension\Lib;

use Closure;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Anticipos;

/** Al generar documentos a partir de uno con anticipos: la factura final descuenta los anticipos; los demás documentos heredan los anticipos pendientes. */
class BusinessDocumentGenerator
{
    // se ejecuta con las líneas ya clonadas y antes de calcular y emitir: la factura sigue siendo editable (VERI*FACTU registra al emitirla)
    public function cloneLines(): Closure
    {
        return function ($prototype, $newDoc, $lines, $quantity) {
            return Anticipos::descontar($prototype, $newDoc, (array)$lines);
        };
    }

    public function generateTrue(): Closure
    {
        return function ($prototype, $lines, $quantity, $properties, $newDoc, $newLines) {
            Anticipos::confirmar($prototype, $newDoc);
            return true;
        };
    }

    public function generateFalse(): Closure
    {
        return function ($prototype, $lines, $quantity, $properties, $newDoc) {
            Anticipos::olvidar($newDoc);
            return true;
        };
    }
}
