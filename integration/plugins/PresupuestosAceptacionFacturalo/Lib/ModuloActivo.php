<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib;

use FacturaScripts\Core\Tools;

/**
 * Módulo comercial (5 €/mes): se activa con el ajuste facturalo.modulos.<clave> (1/0).
 * Sin ajuste: activo en pruebas; en producción (FACTURALO_MODULOS_ESTRICTO=1) inactivo hasta que el staff lo active.
 */
final class ModuloActivo
{
    public static function estaActivo(string $clave): bool
    {
        $valor = Tools::settings('facturalo', 'modulos.' . $clave);
        return self::interpretar($valor, getenv('FACTURALO_MODULOS_ESTRICTO') === '1');
    }

    public static function interpretar($valor, bool $estricto): bool
    {
        if (null === $valor || '' === $valor) {
            return !$estricto;
        }
        return in_array(strtolower(trim((string)$valor)), ['1', 'true', 'si', 'sí', 'on', 'yes'], true);
    }
}
