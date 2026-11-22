<?php
// Valores por defecto de un cliente que factura de verdad (QA 2026-11-23): sin ellos una linea libre sale con IVA 0 y un servicio no se puede vender sin stock.
namespace FacturaScripts\Plugins\Facturalo\Lib;

use FacturaScripts\Core\Model\Impuesto;
use FacturaScripts\Core\Tools;

final class Defaults
{
    const MARKER = 'facturalo_defaults';

    /** Aplica los defectos una sola vez por instalacion; devuelve true si ha cambiado algo. No pisa lo que el cliente ya haya elegido. */
    public static function apply(): bool
    {
        if (Tools::settings('default', self::MARKER) === '1') {
            return false;
        }
        $iva = new Impuesto();
        if (false === $iva->load('IVA21')) {
            return false; // instalacion aun sin datos: se reintenta en la siguiente peticion
        }
        if (empty(Tools::settings('default', 'codimpuesto'))) {
            Tools::settingsSet('default', 'codimpuesto', 'IVA21');
        }
        Tools::settingsSet('default', 'ventasinstock', true);
        Tools::settingsSet('default', self::MARKER, '1');
        return Tools::settingsSave();
    }
}
