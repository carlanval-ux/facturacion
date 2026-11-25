<?php
namespace FacturaScripts\Plugins\Facturalo\Lib;

/** Protección de fecha: una factura de cliente no puede llevar fecha posterior a hoy (compara solo la fecha, en la zona horaria configurada de FS). */
final class FechaFutura
{
    /** $hoy solo para pruebas; por defecto la fecha de hoy en la zona horaria de PHP (FS la fija desde su configuración, Europe/Madrid por defecto). */
    public static function esFutura(?string $fecha, ?string $hoy = null): bool
    {
        $ts = strtotime((string)$fecha);
        if (false === $ts || '' === trim((string)$fecha)) {
            return false; // sin fecha válida decide el resto de validaciones del modelo
        }
        return date('Y-m-d', $ts) > date('Y-m-d', strtotime((string)$hoy) ?: time());
    }
}
