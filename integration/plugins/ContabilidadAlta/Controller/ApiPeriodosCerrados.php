<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Periodos;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Reglas;

/**
 * MAPA-18. Clarto publica aquí los periodos cerrados.
 *   GET  /api/3/periodosCerrados                       → lista actual
 *   POST /api/3/periodosCerrados  periodos=2026-T1,2026-04  → sustituye la lista (clave de acceso completo; vacío = ninguno)
 * Formatos: 2026-T1 · 2026 · 2026-03 · 2026-01-01..2026-03-31
 */
class ApiPeriodosCerrados extends ApiController
{
    protected function runResource(): void
    {
        $metodo = $this->request->method();
        if ('GET' === $metodo) {
            $this->response->json(['periodos' => Periodos::lista()]);
            return;
        }
        if ('POST' !== $metodo) {
            $this->fail(Response::HTTP_METHOD_NOT_ALLOWED, 'method-not-allowed');
            return;
        }
        if (!$this->apiKey->fullaccess) {
            $this->fail(Response::HTTP_FORBIDDEN, 'full-access-api-key-required');
            return;
        }
        $lista = trim((string)$this->request->input('periodos', ''));
        $errores = [];
        Reglas::periodos($lista, $errores);
        if (!empty($errores)) {
            $this->fail(Response::HTTP_BAD_REQUEST, 'periodo-no-valido: ' . implode(' ', $errores));
            return;
        }
        Tools::settingsSet('facturalo', Periodos::AJUSTE, $lista);
        Tools::settingsSave();
        $this->response->json(['periodos' => $lista]);
    }

    private function fail(int $code, string $message): void
    {
        $this->response->setHttpCode($code)->json(['status' => 'error', 'message' => $message]);
    }
}
