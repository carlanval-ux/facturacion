<?php
/**
 * VerifactuFacturalo - Lectura y validación de certificados PKCS#12 (.p12/.pfx) con openssl. No guarda ni registra nada.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Lib;

class Certificado
{
    /**
     * @return array{nombre:string,vencimiento:string,nif:string}|string array con los datos, o el texto del error (sin datos sensibles)
     */
    public static function leer(string $p12, string $clave)
    {
        if (false === extension_loaded('openssl')) {
            return 'Falta la extensión openssl de PHP.';
        }
        $out = [];
        if (false === @openssl_pkcs12_read($p12, $out, $clave) || empty($out['cert']) || empty($out['pkey'])) {
            return 'No se pudo abrir el certificado: contraseña incorrecta, fichero no válido o sin clave privada.';
        }
        $x = openssl_x509_parse($out['cert']);
        if (false === $x) {
            return 'El certificado no es legible.';
        }
        if (($x['validTo_time_t'] ?? 0) < time()) {
            return 'El certificado está caducado (' . date('d-m-Y', $x['validTo_time_t']) . ').';
        }
        if (($x['validFrom_time_t'] ?? 0) > time()) {
            return 'El certificado todavía no es válido.';
        }
        $sujeto = $x['subject'] ?? [];
        $serie = (string)($sujeto['serialNumber'] ?? '');
        $nif = preg_match('/(?:IDCES|VATES)-([A-Z0-9]{9})/i', $serie . ' ' . ($sujeto['organizationIdentifier'] ?? ''), $m) ? strtoupper($m[1]) : '';
        $cn = $sujeto['CN'] ?? '';
        return [
            'nombre' => mb_substr(is_array($cn) ? implode(' ', $cn) : (string)$cn, 0, 200),
            'vencimiento' => date('Y-m-d', $x['validTo_time_t']),
            'nif' => $nif,
        ];
    }

    /**
     * Fichero PEM temporal (0600) con certificado + cadena + clave privada CIFRADA con una contraseña aleatoria de un solo uso: la clave no toca el disco en claro.
     * @return array{ruta:string,clave:string}|null
     */
    public static function pemTemporal(string $p12, string $clave): ?array
    {
        $out = [];
        if (false === @openssl_pkcs12_read($p12, $out, $clave)) {
            return null;
        }
        $efimera = bin2hex(random_bytes(16));
        if (false === openssl_pkey_export($out['pkey'], $pkeyPem, $efimera)) {
            return null;
        }
        $ruta = tempnam(sys_get_temp_dir(), 'vfp');
        chmod($ruta, 0600);
        file_put_contents($ruta, $out['cert'] . implode('', $out['extracerts'] ?? []) . $pkeyPem);
        return ['ruta' => $ruta, 'clave' => $efimera];
    }
}
