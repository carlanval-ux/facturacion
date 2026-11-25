<?php
namespace FacturaScripts\Plugins\Facturalo\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Facturalo\Lib\CuentaBancoRapida;

/** Accion AJAX «crear-cuenta-banco»: la usa el boton «Nueva cuenta» junto al desplegable de cuenta bancaria. */
class EditFormaPago
{
    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ('crear-cuenta-banco' !== $action) {
                return true;
            }
            $this->setTemplate(false);
            if (false === $this->permissions->allowUpdate) {
                $res = ['ok' => false, 'error' => 'Sin permiso'];
            } elseif (false === $this->validateFormToken()) {
                $res = ['ok' => false, 'error' => 'Petición no válida, recarga la página'];
            } else {
                $res = CuentaBancoRapida::crear(
                    (string)$this->request->input('iban', ''),
                    (string)$this->request->input('descripcion', ''),
                    (int)$this->request->input('idempresa', 0) ?: null
                );
            }
            $this->response->json($res);
            return false;
        };
    }
}
