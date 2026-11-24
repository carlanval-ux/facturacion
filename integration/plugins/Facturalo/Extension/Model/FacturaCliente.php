<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Facturalo\Lib\FechaFutura;

/**
 * Rechaza guardar una factura de cliente con fecha posterior a hoy (altas y cambios de fecha) para no alterar la numeración.
 * Un registro existente con fecha futura no se bloquea si no se toca la fecha. Aplica a UI, API, agrupaciones, rectificativas e importaciones (todas pasan por save()).
 */
class FacturaCliente
{
    public function saveBefore(): Closure
    {
        return function () {
            if ((empty($this->id()) || $this->isDirty('fecha')) && FechaFutura::esFutura($this->fecha)) {
                Tools::log()->error('fecha-factura-futura', ['%fecha%' => Tools::date($this->fecha)]);
                // la API del núcleo solo devuelve «error al guardar» (422): aquí se responde con el motivo; la transacción abierta se revierte al cerrar
                if (PHP_SAPI !== 'cli' && 0 === stripos((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/api/')) {
                    http_response_code(422);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['status' => 'error', 'message' => Tools::trans('fecha-factura-futura', ['%fecha%' => Tools::date($this->fecha)])], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                return false;
            }
            return true;
        };
    }
}
