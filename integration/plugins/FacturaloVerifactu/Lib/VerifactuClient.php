<?php
namespace FacturaScripts\Plugins\FacturaloVerifactu\Lib;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Cliente del servicio central VERI*FACTU de Facturalo. La instalación se identifica por un token propio
 * (FS_VERIFACTU_TOKEN en config.php); el servicio deduce de él la empresa, de modo que este plugin nunca elige tenant.
 * El certificado solo viaja hacia el servicio: aquí no se guarda ni se vuelve a leer (solo metadatos).
 */
class VerifactuClient
{
    public static function configurado(): bool
    {
        return self::url() !== '' && self::token() !== '';
    }

    /** @return array{ok: bool, status: int, data: array} */
    public static function get(string $path): array
    {
        return self::resultado(Http::get(self::url() . $path)->setBearerToken(self::token())->setTimeout(30));
    }

    /** @return array{ok: bool, status: int, data: array} */
    public static function post(string $path, array $body): array
    {
        return self::resultado(Http::postJson(self::url() . $path, $body)->setBearerToken(self::token())->setTimeout(60));
    }

    /** Factura de cliente de FacturaScripts -> carga del servicio (importes en céntimos, IVA en puntos básicos). */
    public static function cargaFactura($factura): array
    {
        $lineas = [];
        foreach ($factura->getLines() as $linea) {
            $lineas[] = ['base_cent' => (int)round($linea->pvptotal * 100), 'iva_bp' => (int)round($linea->iva * 100)];
        }

        $conDestinatario = trim((string)$factura->cifnif) !== '';
        return [
            'numserie' => (string)$factura->codigo,
            'fecha' => date('Y-m-d', strtotime($factura->fecha)),
            'tipo' => $conDestinatario ? 'F1' : 'F2',
            'descripcion' => 'Factura ' . $factura->codigo,
            'destinatario' => $conDestinatario ? ['nombre' => (string)$factura->nombrecliente, 'nif' => (string)$factura->cifnif] : null,
            'lineas' => $lineas,
        ];
    }

    private static function url(): string
    {
        return rtrim((string)Tools::config('verifactu_url', ''), '/');
    }

    private static function token(): string
    {
        return (string)Tools::config('verifactu_token', '');
    }

    private static function resultado(Http $respuesta): array
    {
        $data = $respuesta->json();
        return [
            'ok' => $respuesta->status() >= 200 && $respuesta->status() < 300,
            'status' => $respuesta->status(),
            'data' => is_array($data) ? $data : [],
        ];
    }
}
