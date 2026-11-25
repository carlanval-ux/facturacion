<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Lib;

/** Reglas puras (sin base de datos): fáciles de probar. */
final class Reglas
{
    public const PATRON = '{SERIE}{EJE}-{0NUM}';
    public const LONGNUMERO = 6;
    private const PATRONES_FS_DEFECTO = ['{EJE}{SERIE}{0NUM}', 'F{EJE}{SERIE}{NUM}'];

    /** MAPA-22: códigos de cliente/proveedor alfanuméricos, 1-10 (Clarto no admite `_+.-`). */
    public static function codigoValido(?string $codigo): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9]{1,10}$/', (string)$codigo);
    }

    /** Subcuenta a la longitud del ejercicio: cuenta + ceros (Clarto: cuenta.padEnd(L,'0')). */
    public static function subcuenta(string $cuenta, int $long): string
    {
        return str_pad($cuenta, $long, '0');
    }

    /**
     * MAPA-21: campos de una secuencia de facturas de cliente. Siempre sin huecos y sin copiar la fecha.
     * El patrón solo se impone en una secuencia nueva y cuando es vacío o el de FS por defecto (no se pisa uno elegido a propósito).
     */
    public static function secuenciaFactura(array $s, bool $esNueva): array
    {
        $s['usarhuecos'] = false;
        $s['mantenerfecha'] = false;
        if ($esNueva) {
            $patron = trim((string)($s['patron'] ?? ''));
            if ('' === $patron || in_array($patron, self::PATRONES_FS_DEFECTO, true)) {
                $s['patron'] = self::PATRON;
                $s['longnumero'] = self::LONGNUMERO;
            }
        }
        return $s;
    }

    /**
     * MAPA-18: interpreta la lista de periodos cerrados. Elementos separados por coma o salto de línea:
     * `2026-T1` (trimestre), `2026` (año), `2026-03` (mes) o `2026-01-01..2026-03-31` (rango).
     * Devuelve [[desde,hasta],…] en Y-m-d, y en `$errores` lo que no se entiende.
     */
    public static function periodos(string $lista, array &$errores = []): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($lista), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (1 === preg_match('/^(\d{4})-T([1-4])$/i', $p, $m)) {
                $mes = ((int)$m[2] - 1) * 3 + 1;
                $out[] = [sprintf('%s-%02d-01', $m[1], $mes), date('Y-m-t', strtotime(sprintf('%s-%02d-01', $m[1], $mes + 2)))];
            } elseif (1 === preg_match('/^(\d{4})$/', $p, $m)) {
                $out[] = [$m[1] . '-01-01', $m[1] . '-12-31'];
            } elseif (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p, $m)) {
                $out[] = [$p . '-01', date('Y-m-t', strtotime($p . '-01'))];
            } elseif (1 === preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $p, $m) && $m[1] <= $m[2]) {
                $out[] = [$m[1], $m[2]];
            } else {
                $errores[] = $p;
            }
        }
        return $out;
    }

    /** @param array $periodos resultado de periodos(); $fecha en cualquier formato que entienda strtotime. */
    public static function fechaCerrada(?string $fecha, array $periodos): bool
    {
        $ts = strtotime((string)$fecha);
        if (false === $ts || '' === trim((string)$fecha)) {
            return false;
        }
        $f = date('Y-m-d', $ts);
        foreach ($periodos as [$desde, $hasta]) {
            if ($f >= $desde && $f <= $hasta) {
                return true;
            }
        }
        return false;
    }
}
