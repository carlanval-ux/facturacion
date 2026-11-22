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
        $this->cerrarPanel();
    }

    // El panel de FS no es para clientes: fuera de loopback solo se atiende la API. Administracion: tunel SSH al puerto 443 del host.
    private function cerrarPanel(): void
    {
        if (PHP_SAPI === 'cli' || getenv('FACTURALO_PANEL_ABIERTO') === '1') {
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
