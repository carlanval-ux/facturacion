<?php
namespace FacturaScripts\Plugins\FacturaloVerifactu\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\QrImage;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturaloVerifactu\Lib\VerifactuClient;

/**
 * Pestaña «Verifactu» de la factura de cliente: estado de los registros, QR y alta/subsanación/anulación (solo PRUEBAS).
 */
class EditFacturaCliente
{
    const VIEW = 'VerifactuFactura';
    const ACCIONES = ['verifactu-alta' => '/v1/registros/alta', 'verifactu-subsanar' => '/v1/registros/alta', 'verifactu-anular' => '/v1/registros/anulacion'];

    public function createViews(): Closure
    {
        return function () {
            $this->addHtmlView(EditFacturaCliente::VIEW, 'Tab/VerifactuFactura', 'FacturaCliente', 'Verifactu', 'fa-solid fa-file-shield');
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if (false === isset(EditFacturaCliente::ACCIONES[$action])) {
                return null;
            }
            if (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-update');
                return null;
            }
            if (false === $this->validateFormToken() || false === VerifactuClient::configurado()) {
                Tools::log()->error('Verifactu: formulario no válido o servicio sin configurar (FS_VERIFACTU_URL y FS_VERIFACTU_TOKEN)');
                return null;
            }

            $factura = new FacturaCliente();
            if (false === $factura->loadFromCode($this->request->inputOrQuery('code'))) {
                return null;
            }

            $carga = VerifactuClient::cargaFactura($factura);
            $body = $action === 'verifactu-anular'
                ? ['factura' => ['numserie' => $carga['numserie'], 'fecha' => $carga['fecha']]]
                : ['factura' => $carga, 'subsanacion' => $action === 'verifactu-subsanar'];

            $r = VerifactuClient::post(EditFacturaCliente::ACCIONES[$action], $body);
            if (false === $r['ok']) {
                Tools::log()->error('Verifactu: ' . ($r['data']['error'] ?? 'error ' . $r['status']));
                return null;
            }
            $estado = $r['data']['registro']['estado'] ?? '?';
            Tools::log()->notice('Verifactu (pruebas): registro ' . $estado);
            return null;
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== EditFacturaCliente::VIEW || empty($view->model->codigo)) {
                return;
            }
            $datos = ['configurado' => VerifactuClient::configurado(), 'registros' => [], 'qr' => null, 'error' => null];
            if ($datos['configurado']) {
                $r = VerifactuClient::get('/v1/registros?numserie=' . rawurlencode((string)$view->model->codigo));
                if ($r['ok']) {
                    $datos['registros'] = $r['data']['registros'] ?? [];
                    foreach ($datos['registros'] as $reg) {
                        if ($reg['tipo'] === 'alta' && !empty($reg['qr_url'])) {
                            $datos['qr'] = ['url' => $reg['qr_url'], 'png' => QrImage::png($reg['qr_url'])]; // el QR se genera aquí: ningún dato fiscal sale a terceros
                        }
                    }
                } else {
                    $datos['error'] = $r['data']['error'] ?? 'error ' . $r['status'];
                }
            }
            $view->cursor = [$datos];
        };
    }
}
