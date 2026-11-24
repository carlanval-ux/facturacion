<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib;

use FacturaScripts\Core\Cache;

/** Límite de peticiones por IP a la página pública (propio: no comparte contador con el login). */
final class Limite
{
    const MAX = 30;
    const VENTANA = 600;

    /** Cuenta la petición y devuelve false si la IP ha superado el máximo en la ventana. */
    public static function permitir(string $ip, ?int $ahora = null): bool
    {
        $clave = 'aceptacion-rl-' . md5($ip);
        $marcas = Cache::get($clave);
        $marcas = is_array($marcas) ? $marcas : [];
        $ok = self::permitirEn($marcas, $ahora ?? time());
        Cache::set($clave, $marcas);
        return $ok;
    }

    public static function permitirEn(array &$marcas, int $ahora): bool
    {
        $marcas = array_values(array_filter($marcas, fn($t) => $t > $ahora - self::VENTANA));
        $ok = count($marcas) < self::MAX;
        $marcas[] = $ahora;
        return $ok;
    }
}
