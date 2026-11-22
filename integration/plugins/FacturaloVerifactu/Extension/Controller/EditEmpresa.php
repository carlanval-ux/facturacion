<?php
namespace FacturaScripts\Plugins\FacturaloVerifactu\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Plugins\FacturaloVerifactu\Lib\VerifactuClient;

/**
 * Pestaña «Verifactu» de la empresa: subir/revocar el certificado y ver titular, vigencia y estado.
 * Todo lo hace el servicio central; aquí no se guarda el certificado ni su contraseña.
 */
class EditEmpresa
{
    const VIEW = 'VerifactuEmpresa';
    const TIPOS = ['sello_empresa', 'representante', 'colaborador_social'];

    public function createViews(): Closure
    {
        return function () {
            $this->addHtmlView(EditEmpresa::VIEW, 'Tab/VerifactuEmpresa', 'Empresa', 'Verifactu', 'fa-solid fa-file-shield');
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if (false === in_array($action, ['verifactu-cert', 'verifactu-revocar'], true)) {
                return null;
            }
            if (false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-update');
                return null;
            }
            if (false === $this->validateFormToken()) {
                return null;
            }
            if (false === VerifactuClient::configurado()) {
                Tools::log()->error('Verifactu: falta configurar FS_VERIFACTU_URL y FS_VERIFACTU_TOKEN en config.php');
                return null;
            }

            if ($action === 'verifactu-revocar') {
                $r = VerifactuClient::post('/v1/certificado/revocar', []);
                $r['ok'] ? Tools::log()->notice('Certificado revocado') : Tools::log()->error('No se pudo revocar: ' . ($r['data']['error'] ?? $r['status']));
                return null;
            }

            $file = $this->request->files->get('p12');
            $tipo = $this->request->getOnly('tipo', EditEmpresa::TIPOS);
            $pass = (string)$this->request->input('passphrase', '');
            if (null === $file || false === $file->isValid() || $tipo === null || $pass === '') {
                Tools::log()->error('Indica el fichero del certificado (.p12/.pfx), su contraseña y el tipo');
                return null;
            }

            $empresa = new Empresa();
            $empresa->loadFromCode($this->request->inputOrQuery('code'));
            $body = [
                'tipo' => $tipo,
                'alias' => $file->getClientOriginalName(),
                'p12_base64' => base64_encode((string)file_get_contents($file->getPathname())),
                'passphrase' => $pass,
                'nif_empresa' => (string)$empresa->cifnif, // el servicio comprueba que es el de esta instalación
            ];
            if ($tipo !== 'sello_empresa') {
                $body['apoderamiento'] = [
                    'referencia' => (string)$this->request->input('apoderamiento_ref', ''),
                    'nif_representado' => (string)$empresa->cifnif,
                ];
            }

            $r = VerifactuClient::post('/v1/certificado', $body);
            if ($r['ok']) {
                Tools::log()->notice('Certificado guardado y verificado');
                return null;
            }
            Tools::log()->error(($r['data']['error'] ?? 'Error ' . $r['status']) . (empty($r['data']['problemas']) ? '' : ': ' . implode('; ', $r['data']['problemas'])));
            return null;
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== EditEmpresa::VIEW) {
                return;
            }
            $view->cursor = [['configurado' => VerifactuClient::configurado()] + (VerifactuClient::configurado() ? VerifactuClient::get('/v1/estado') : ['ok' => false, 'data' => []])];
        };
    }
}
