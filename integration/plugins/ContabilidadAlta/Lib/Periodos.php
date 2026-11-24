<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Lib;

use FacturaScripts\Core\Tools;

/**
 * MAPA-18: periodos cerrados. Clarto publica la lista (ajuste facturalo.periodos_cerrados, vía API periodosCerrados);
 * Facturalo no depende de que exista: sin lista no se bloquea nada.
 */
final class Periodos
{
    public const AJUSTE = 'periodos_cerrados';

    public static function lista(): string
    {
        return (string)Tools::settings('facturalo', self::AJUSTE, '');
    }

    public static function cerrada(?string $fecha): bool
    {
        $lista = self::lista();
        return '' !== trim($lista) && Reglas::fechaCerrada($fecha, Reglas::periodos($lista));
    }
}
