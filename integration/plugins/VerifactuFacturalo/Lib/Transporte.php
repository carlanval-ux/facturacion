<?php
/**
 * VerifactuFacturalo - Transporte SOAP con certificado de cliente (mTLS) sobre streams de PHP (sin la extensión soap ni curl).
 * Antes de abrir ningún socket se comprueba el destino con Endpoint::validar() según el check «Modo pruebas». Sin redirecciones.
 * Nunca se registra el cuerpo ni el material del certificado. Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Lib;

use InvalidArgumentException;
use RuntimeException;

class Transporte
{
    const MAX_RESPUESTA = 5242880;

    /**
     * @param array{p12:string,clave:string} $cert
     * @return string cuerpo de la respuesta (HTTP 200 o Fault SOAP 500)
     */
    public static function enviar(string $url, bool $pruebas, string $xml, array $cert, int $timeout = 30): string
    {
        Endpoint::validar($url, $pruebas); // lanza InvalidArgumentException sin abrir conexión
        $pem = Certificado::pemTemporal($cert['p12'], $cert['clave']);
        if (null === $pem) {
            throw new RuntimeException('No se pudo preparar el certificado de cliente');
        }
        try {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: text/xml; charset=utf-8\r\nSOAPAction: \"\"\r\nContent-Length: " . strlen($xml) . "\r\n",
                    'content' => $xml,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'ssl' => ['local_cert' => $pem['ruta'], 'passphrase' => $pem['clave'], 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
            ]);
            $cuerpo = @file_get_contents($url, false, $ctx, 0, self::MAX_RESPUESTA);
            $estado = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $estado = (int)$m[1];
                }
            }
        } finally {
            @unlink($pem['ruta']);
        }
        if (false === $cuerpo || 0 === $estado) {
            throw new RuntimeException('Error de red al conectar con la AEAT');
        }
        if ($estado >= 300 && $estado < 400) {
            throw new RuntimeException('Redirección no permitida');
        }
        if ($estado !== 200 && $estado !== 500) {
            throw new RuntimeException('La AEAT respondió HTTP ' . $estado);
        }
        return $cuerpo;
    }

    /** Solo negocia TLS con el certificado de cliente (no envía ningún mensaje). */
    public static function handshake(string $url, bool $pruebas, array $cert, int $timeout = 15): ?string
    {
        try {
            Endpoint::validar($url, $pruebas);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $pem = Certificado::pemTemporal($cert['p12'], $cert['clave']);
        if (null === $pem) {
            return 'No se pudo preparar el certificado de cliente';
        }
        try {
            $ctx = stream_context_create(['ssl' => ['local_cert' => $pem['ruta'], 'passphrase' => $pem['clave'], 'verify_peer' => true, 'verify_peer_name' => true]]);
            $s = @stream_socket_client('ssl://' . parse_url($url, PHP_URL_HOST) . ':443', $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if (false === $s) {
                return 'No se pudo negociar TLS: ' . $errstr;
            }
            fclose($s);
            return null;
        } finally {
            @unlink($pem['ruta']);
        }
    }
}
