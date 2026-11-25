<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib;

/** Estados del embudo: enviado, visto, aceptado, rechazado, caducado (+ invalidado si se editó el presupuesto). */
final class Embudo
{
    const ENVIADO = 'enviado';
    const VISTO = 'visto';
    const ACEPTADO = 'aceptado';
    const RECHAZADO = 'rechazado';
    const CADUCADO = 'caducado';
    const INVALIDADO = 'invalidado';

    /** Estado efectivo de un registro; $hashActual = huella del presupuesto ahora (null si no se conoce). */
    public static function estado(array $fila, int $ahora, ?string $hashActual = null): string
    {
        $e = (string)($fila['estado'] ?? '');
        if (in_array($e, [self::ACEPTADO, self::RECHAZADO, self::INVALIDADO], true)) {
            return $e;
        }
        if (null !== $hashActual && false === hash_equals((string)($fila['hashdoc'] ?? ''), $hashActual)) {
            return self::INVALIDADO;
        }
        if ($ahora > (int)($fila['caduca'] ?? 0)) {
            return self::CADUCADO;
        }
        return $e === self::VISTO ? self::VISTO : self::ENVIADO;
    }

    /** Solo se puede aceptar/rechazar una vez, y solo mientras el enlace está vivo. */
    public static function resoluble(string $estadoEfectivo): bool
    {
        return in_array($estadoEfectivo, [self::ENVIADO, self::VISTO], true);
    }
}
