<?php
/**
 * VerifactuFacturalo - Selector de endpoint. ÚNICO punto que decide a qué servidor de la AEAT se envía: lo decide solo el check «Modo pruebas» de la empresa.
 * Pruebas = preproducción (prewww*.aeat.es); sin el check = producción (www*.agenciatributaria.gob.es). URLs de la documentación pública de la AEAT
 * (WSDL «SistemaFacturacion»). Con certificado de sello electrónico se usan los hosts «10»; con certificado personal/representante los «1».
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Lib;

use InvalidArgumentException;

class Endpoint
{
    const RUTA_SOAP = '/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
    const RUTA_QR = '/wlpl/TIKE-CONT/ValidarQR';

    public static function host(bool $pruebas, bool $sello = false): string
    {
        if ($pruebas) {
            return $sello ? 'prewww10.aeat.es' : 'prewww1.aeat.es';
        }
        return $sello ? 'www10.agenciatributaria.gob.es' : 'www1.agenciatributaria.gob.es';
    }

    public static function soap(bool $pruebas, bool $sello = false): string
    {
        return 'https://' . self::host($pruebas, $sello) . self::RUTA_SOAP;
    }

    /** URL base del QR de cotejo (la AEAT valida la factura en su servicio). */
    public static function qr(bool $pruebas): string
    {
        return 'https://' . ($pruebas ? 'prewww2.aeat.es' : 'www2.agenciatributaria.gob.es') . self::RUTA_QR;
    }

    /** Defensa en profundidad: el transporte rechaza cualquier URL que no corresponda al check. */
    public static function validar(string $url, bool $pruebas): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $esperados = [self::host($pruebas, false), self::host($pruebas, true)];
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || false === in_array($host, $esperados, true)) {
            throw new InvalidArgumentException('Destino no permitido para el modo ' . ($pruebas ? 'pruebas' : 'real'));
        }
    }
}
