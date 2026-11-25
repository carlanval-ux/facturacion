<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Lib;

use FacturaScripts\Core\Model\Settings;

/**
 * MAPA-18: periodos cerrados. Clarto publica la lista (ajuste facturalo.periodos_cerrados, vía API periodosCerrados);
 * Facturalo no depende de que exista: sin lista no se bloquea nada.
 */
final class Periodos
{
    public const AJUSTE = 'periodos_cerrados';

    public static function lista(): string
    {
        // se lee de la base de datos y no de la caché de ajustes: una caché obsoleta (p. ej. otro proceso sin permiso
        // para borrarla) dejaría pasar fechas ya cerradas. Una consulta por guardado de factura.
        $s = new Settings();
        return $s->load('facturalo') ? (string)$s->getProperty(self::AJUSTE) : '';
    }

    public static function cerrada(?string $fecha): bool
    {
        $lista = self::lista();
        return '' !== trim($lista) && Reglas::fechaCerrada($fecha, Reglas::periodos($lista));
    }
}
