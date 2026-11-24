<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib;

use FacturaScripts\Core\Tools;

/** Interruptor comercial del módulo 'cobros' (5 €/mes). Delega en ModuloActivo si la plataforma lo aporta. */
final class Modulo
{
    public const CLAVE = 'cobros';

    public static function activo(): bool
    {
        $clase = '\FacturaScripts\Plugins\Facturalo\Lib\ModuloActivo';
        if (class_exists($clase)) {
            return (bool)$clase::estaActivo(self::CLAVE);
        }
        // shortcut: sin ModuloActivo se lee el ajuste a pelo; activo por defecto (pruebas), cambiar al integrar la clase real.
        $v = Tools::settings('facturalo', 'modulos.' . self::CLAVE);
        return null === $v || '' === $v || filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
