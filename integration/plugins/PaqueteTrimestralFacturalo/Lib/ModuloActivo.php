<?php
namespace FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib;

use FacturaScripts\Core\Tools;

/**
 * Módulos comerciales de Facturalo. Ajuste `facturalo.modulos.<clave>`:
 * si no existe, el módulo está ACTIVO (por defecto); solo un valor explícito de apagado lo desactiva.
 */
final class ModuloActivo
{
    const CLAVE = 'trimestral';

    public static function estaActivo(string $clave = self::CLAVE): bool
    {
        $valor = Tools::settings('facturalo', 'modulos.' . $clave, null);
        if (null === $valor || '' === $valor) {
            return true;
        }
        return !in_array(strtolower(trim((string)$valor)), ['0', 'false', 'off', 'no', 'inactivo'], true);
    }
}
