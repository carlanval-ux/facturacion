<?php
namespace FacturaScripts\Plugins\Facturalo;

use FacturaScripts\Core\Template\InitClass;

final class Init extends InitClass
{
    public function init(): void
    {
        // La generacion de PDF escribe su cache de fuentes aqui; con MyFiles compartido entre releases puede no existir.
        $dir = FS_FOLDER . '/MyFiles/Cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function uninstall(): void {}

    public function update(): void {}
}
