<?php
/**
 * VerifactuFacturalo - Huella (hash SHA-256 encadenado) de los registros de facturación.
 * Especificación pública AEAT «Veri-Factu_especificaciones_huella_hash_registros» (cadena «Campo=valor&...», UTF-8, SHA-256, hex en mayúsculas).
 * Licencia LGPL-3.0-or-later. Sin dependencias de FacturaScripts (testeable en solitario).
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Lib;

class Huella
{
    /** Huella de un registro de alta. $fecha en DD-MM-AAAA; importes ya formateados («12.35»). */
    public static function alta(string $nif, string $numSerie, string $fecha, string $tipo, string $cuotaTotal, string $importeTotal, string $huellaAnterior, string $fechaHora): string
    {
        return self::calcular([
            'IDEmisorFactura' => $nif,
            'NumSerieFactura' => $numSerie,
            'FechaExpedicionFactura' => $fecha,
            'TipoFactura' => $tipo,
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Huella' => $huellaAnterior,
            'FechaHoraHusoGenRegistro' => $fechaHora,
        ]);
    }

    public static function anulacion(string $nif, string $numSerie, string $fecha, string $huellaAnterior, string $fechaHora): string
    {
        return self::calcular([
            'IDEmisorFacturaAnulada' => $nif,
            'NumSerieFacturaAnulada' => $numSerie,
            'FechaExpedicionFacturaAnulada' => $fecha,
            'Huella' => $huellaAnterior,
            'FechaHoraHusoGenRegistro' => $fechaHora,
        ]);
    }

    /** Un campo vacío se escribe «Nombre=» (primer registro: Huella=). Se recortan espacios laterales. */
    public static function cadena(array $campos): string
    {
        $partes = [];
        foreach ($campos as $nombre => $valor) {
            $partes[] = $nombre . '=' . trim((string)$valor);
        }
        return implode('&', $partes);
    }

    public static function calcular(array $campos): string
    {
        return strtoupper(hash('sha256', self::cadena($campos)));
    }
}
