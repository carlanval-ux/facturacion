<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Aceptacion;

/** Acciones del emisor sobre un presupuesto (POST + token CSRF): enviar para aceptación y convertir. */
class AceptacionAccion extends Controller
{
    /** @var string */
    public $volver = 'ListPresupuestoCliente';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['title'] = 'aceptacion-presupuesto';
        $data['menu'] = 'sales';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $doc = new PresupuestoCliente();
        if (false === $this->request->isMethod('POST') || false === $doc->load((int)$this->request->input('code', 0))) {
            Tools::log()->warning('record-not-found');
            return;
        }
        $this->volver = $doc->url();
        if (false === $this->permissions->allowUpdate || ($this->permissions->onlyOwnerData && $doc->nick !== $user->nick)) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }
        if (false === $this->validateFormToken()) {
            return;
        }

        $accion = (string)$this->request->input('action', '');
        if ('aceptacion-enviar' === $accion) {
            $res = Aceptacion::enviar($doc, (string)$user->nick);
        } elseif ('aceptacion-convertir' === $accion) {
            $res = Aceptacion::convertir($doc, (string)$this->request->input('destino', ''));
        } else {
            return;
        }
        $res['ok'] ? Tools::log()->notice($res['msg']) : Tools::log()->warning($res['msg']);
        // vuelta al presupuesto tras leer el mensaje
        $this->redirect($doc->url(), 3);
    }
}
