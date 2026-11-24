<?php
namespace FacturaScripts\Plugins\TelecoBridge\Controller;

use FacturaScripts\Core\Controller\ApiCreateFacturaRectificativaCliente;
use FacturaScripts\Plugins\TelecoBridge\Lib\Idempotente;

/** POST /api/3/telecoRectificativaCliente con `Idempotency-Key`. Mismo cuerpo que crearFacturaRectificativaCliente. */
class ApiTelecoRectificativaCliente extends ApiCreateFacturaRectificativaCliente
{
    use Idempotente;

    protected function runResource(): void
    {
        $this->idempotente('rectificativa', [], fn() => parent::runResource());
    }
}
