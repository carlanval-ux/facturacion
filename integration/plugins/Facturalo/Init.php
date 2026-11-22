<?php
namespace FacturaScripts\Plugins\Facturalo;

use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;

final class Init extends InitClass
{
    public function init(): void
    {
        // La generacion de PDF escribe su cache de fuentes aqui; con MyFiles compartido entre releases puede no existir.
        $dir = FS_FOLDER . '/MyFiles/Cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->cerrarPanel();
        // identidad visual (se carga tras el CSS del nucleo)
        AssetManager::addCss(Tools::config('route') . '/Dinamic/Assets/CSS/facturalo.css', 10);
    }

    // Facturalo ES el panel de FS con marca blanca: abierto por defecto. Con FACTURALO_PANEL_CERRADO=1 (variable de entorno del pool) fuera de loopback solo se atiende la API.
    private function cerrarPanel(): void
    {
        if (PHP_SAPI === 'cli' || getenv('FACTURALO_PANEL_CERRADO') !== '1') {
            return;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return;
        }
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_starts_with($path, '/api/') || $path === '/api') {
            return;
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'No encontrado';
        exit;
    }

    public function uninstall(): void {}

    public function update(): void {}
}
