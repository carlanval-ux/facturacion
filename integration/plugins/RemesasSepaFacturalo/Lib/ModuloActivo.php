<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use FacturaScripts\Core\Tools;

/**
 * Interruptor comercial del módulo. Lee el ajuste facturalo.modulos.<clave>;
 * mientras no exista, el módulo está activo (entorno de pruebas).
 */
final class ModuloActivo
{
    public static function estaActivo(string $clave): bool
    {
        $valor = Tools::settings('facturalo', 'modulos.' . $clave, null);
        if ($valor === null || $valor === '') {
            return true;
        }
        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }
}
