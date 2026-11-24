<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Lib;

/** Cálculo puro de fechas de periodicidad (sin base de datos). */
final class Recurrencia
{
    /** Fecha en formato interno Y-m-d (acepta d-m-Y o Y-m-d). */
    public static function iso(?string $fecha): ?string
    {
        if (empty($fecha)) {
            return null;
        }
        $t = strtotime(str_replace('/', '-', $fecha));
        return $t ? date('Y-m-d', $t) : null;
    }

    /** Fecha en el formato de los modelos (d-m-Y). */
    public static function fmt(?string $iso): ?string
    {
        return $iso ? date('d-m-Y', strtotime($iso)) : null;
    }

    /**
     * Siguiente fecha tras $desde. En meses se conserva el día de $inicio (31 ene → 28/29 feb → 31 mar),
     * sin desbordar al mes siguiente. Devuelve null en modo manual.
     */
    public static function siguiente(string $desde, int $cada, string $unidad, ?string $inicio = null): ?string
    {
        $cada = max(1, $cada);
        $d = new \DateTimeImmutable(self::iso($desde));
        switch ($unidad) {
            case 'dias':
                return $d->modify('+' . $cada . ' days')->format('Y-m-d');
            case 'semanas':
                return $d->modify('+' . $cada . ' weeks')->format('Y-m-d');
            case 'meses':
                $dia = (int)(new \DateTimeImmutable(self::iso($inicio ?: $desde)))->format('j');
                $primero = $d->modify('first day of this month')->modify('+' . $cada . ' months');
                $max = (int)$primero->format('t');
                return $primero->setDate((int)$primero->format('Y'), (int)$primero->format('n'), min($dia, $max))->format('Y-m-d');
        }
        return null;
    }

    /** Fecha del documento: la prevista o la de hoy según la opción, y nunca posterior a hoy. */
    public static function fechaDocumento(string $prevista, string $hoy, string $opcion): string
    {
        $f = $opcion === 'hoy' ? $hoy : $prevista;
        return min($f, $hoy);
    }
}
