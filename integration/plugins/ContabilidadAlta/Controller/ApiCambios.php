<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Cambios;

/**
 * MAPA-20. Solo lectura. GET /api/3/cambios?desde=<id|fecha>&limit=100
 * Con clave sin acceso completo hace falta `allowget` sobre el recurso «cambios».
 */
class ApiCambios extends ApiController
{
    protected function runResource(): void
    {
        if ('GET' !== $this->request->method()) {
            $this->response->setHttpCode(Response::HTTP_METHOD_NOT_ALLOWED)->json(['status' => 'error', 'message' => 'method-not-allowed']);
            return;
        }
        $r = Cambios::listar((string)$this->request->query('desde', ''), (int)$this->request->query('limit', 100));
        if (isset($r['error'])) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST)->json(['status' => 'error', 'message' => $r['error']]);
            return;
        }
        $this->response->json($r);
    }
}
