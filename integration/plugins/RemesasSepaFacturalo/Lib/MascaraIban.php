<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

/** Rellena el IBAN enmascarado en las filas/modelo de una vista antes de pintarla. */
final class MascaraIban
{
    public static function aplicar($view): void
    {
        foreach ((array)($view->cursor ?? []) as $fila) {
            $fila->ibanmask = Iban::formato($fila->iban);
        }
        if (isset($view->model) && is_object($view->model)) {
            $view->model->ibanmask = Iban::formato($view->model->iban);
        }
    }
}
