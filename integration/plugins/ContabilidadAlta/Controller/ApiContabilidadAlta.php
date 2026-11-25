<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Aprovisionador;

/**
 * MAPA-19. Solo con clave API de acceso completo (es administración, no lectura).
 *   GET  /api/3/contabilidadAlta?idempresa=&longsubcuenta=&anyo=   → informe de diferencias (no escribe)
 *   POST /api/3/contabilidadAlta  (idempresa, longsubcuenta, anyo, plan_csv)  → aprovisiona (idempotente) y devuelve el informe
 */
class ApiContabilidadAlta extends ApiController
{
    protected function runResource(): void
    {
        $metodo = $this->request->method();
        if (!in_array($metodo, ['GET', 'POST'], true)) {
            $this->fail(Response::HTTP_METHOD_NOT_ALLOWED, 'method-not-allowed');
            return;
        }
        if (!$this->apiKey->fullaccess) {
            $this->fail(Response::HTTP_FORBIDDEN, 'full-access-api-key-required');
            return;
        }

        $o = [];
        foreach (['idempresa', 'longsubcuenta', 'anyo'] as $k) {
            $v = $this->request->inputOrQuery($k);
            if (null !== $v && '' !== $v) {
                $o[$k] = $v;
            }
        }
        if ('POST' === $metodo && '' !== (string)$this->request->input('plan_csv', '')) {
            $o['plan_csv'] = $this->request->input('plan_csv');
        }

        $a = new Aprovisionador($o);
        if (null !== ($error = $a->validar())) {
            $this->fail(Response::HTTP_BAD_REQUEST, $error);
            return;
        }
        $this->response->json('POST' === $metodo ? $a->aprovisionar() : $a->verificar());
    }

    private function fail(int $code, string $message): void
    {
        $this->response->setHttpCode($code)->json(['status' => 'error', 'message' => $message]);
    }
}
