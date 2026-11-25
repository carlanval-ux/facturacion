<?php
namespace FacturaScripts\Plugins\TelecoBridge\Controller;

use FacturaScripts\Core\Controller\ApiPagarFacturaCliente;
use FacturaScripts\Plugins\TelecoBridge\Lib\Idempotente;

/** POST|PUT /api/3/telecoPagarFacturaCliente/{id} con `Idempotency-Key`. Mismo cuerpo que pagarFacturaCliente. */
class ApiTelecoPagarFacturaCliente extends ApiPagarFacturaCliente
{
    use Idempotente;

    protected function runResource(): void
    {
        $this->idempotente('pagar', ['id' => (string)$this->getUriParam(3)], fn() => parent::runResource());
    }
}
