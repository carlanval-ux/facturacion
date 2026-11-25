<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Lib;

use FacturaScripts\Core\Tools;

/** Módulo comercial (5 €/mes): lee el ajuste facturalo.modulos.<clave>. Sin valor = activo (pruebas); la plataforma pone 0 a quien no lo contrata. */
final class ModuloActivo
{
    public static function estaActivo(string $clave): bool
    {
        $v = Tools::settings('facturalo', 'modulos.' . $clave, null);
        if ($v === null || $v === '') {
            return true;
        }
        return !in_array(strtolower((string)$v), ['0', 'false', 'no', 'off'], true);
    }
}
