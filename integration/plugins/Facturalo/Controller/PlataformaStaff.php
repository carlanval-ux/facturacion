<?php
namespace FacturaScripts\Plugins\Facturalo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Facturalo\Lib\Staff;

/** Lista de nicks del personal de plataforma. Solo la edita el propio staff. */
class PlataformaStaff extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Personal de plataforma';
        $data['icon'] = 'fa-solid fa-user-shield';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        if (false === Staff::isStaff($user->nick)) {
            $this->setTemplate(false);
            $response->setHttpCode(403);
            return;
        }
        if ('save' === $this->request->input('action') && $this->validateFormToken()) {
            $nicks = array_filter(array_map('trim', explode(',', (string)$this->request->input('nicks', ''))), 'strlen');
            // el propio usuario no se puede quitar: evita dejar la plataforma sin staff
            $nicks[] = $user->nick;
            Tools::settingsSet('facturalo', 'staff_nicks', implode(',', array_unique($nicks)));
            Tools::settingsSave();
            Tools::log()->notice('record-updated-correctly');
        }
    }

    public function staffNicks(): string
    {
        return implode(', ', Staff::nicks());
    }
}
