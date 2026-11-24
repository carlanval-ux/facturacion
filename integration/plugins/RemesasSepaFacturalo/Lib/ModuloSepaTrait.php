<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use FacturaScripts\Core\Tools;

/** Bloqueo comercial: si el módulo `sepa` no está contratado, las pantallas redirigen al inicio. */
trait ModuloSepaTrait
{
    protected function moduloBloqueado(): bool
    {
        if (ModuloActivo::estaActivo('sepa')) {
            return false;
        }
        Tools::log()->warning('sepa-module-inactive');
        $this->redirect('Dashboard');
        return true;
    }

    /** Traduce un error de RemesaService/SepaXml («clave» o «clave: detalle»). */
    protected function errorSepa(string $e): string
    {
        $partes = explode(': ', $e, 2);
        return Tools::trans($partes[0]) . (isset($partes[1]) ? ': ' . $partes[1] : '');
    }
}
