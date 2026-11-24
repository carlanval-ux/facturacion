<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use DateTimeImmutable;

/** Días hábiles TARGET2: lunes a viernes salvo 1-ene, Viernes Santo, Lunes de Pascua, 1-may, 25 y 26-dic. */
final class DiasHabiles
{
    public static function esFestivo(DateTimeImmutable $d): bool
    {
        $md = $d->format('m-d');
        if (in_array($md, ['01-01', '05-01', '12-25', '12-26'], true)) {
            return true;
        }
        $pascua = self::pascua((int)$d->format('Y'));
        $ymd = $d->format('Y-m-d');
        return $ymd === $pascua->modify('-2 days')->format('Y-m-d')
            || $ymd === $pascua->modify('+1 day')->format('Y-m-d');
    }

    public static function esHabil(DateTimeImmutable $d): bool
    {
        return (int)$d->format('N') < 6 && !self::esFestivo($d);
    }

    /** Si no es hábil, el siguiente día hábil. */
    public static function siguienteHabil(DateTimeImmutable $d): DateTimeImmutable
    {
        while (!self::esHabil($d)) {
            $d = $d->modify('+1 day');
        }
        return $d;
    }

    /** Suma $n días hábiles (n >= 0) a partir de $d. */
    public static function sumar(DateTimeImmutable $d, int $n): DateTimeImmutable
    {
        while ($n > 0) {
            $d = $d->modify('+1 day');
            if (self::esHabil($d)) {
                $n--;
            }
        }
        return $d;
    }

    /** Domingo de Pascua (algoritmo gregoriano anónimo). */
    public static function pascua(int $y): DateTimeImmutable
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mes, $dia));
    }
}
