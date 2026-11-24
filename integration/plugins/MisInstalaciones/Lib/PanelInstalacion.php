<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MisInstalaciones\Model\Instalacion;

/**
 * Conexión compartida por las pestañas del panel de una instalación remota.
 * La información de la instalación (versión y plugins) se consulta una sola vez por petición.
 */
class PanelInstalacion
{
    public const VERSION_VISOR_ERRORES = 1.6;

    private ?array $about = null;

    private bool $aboutConsultado = false;

    private ClienteApiInstalacion $clienteApi;

    private Instalacion $instalacion;

    public function __construct(Instalacion $instalacion)
    {
        $this->instalacion = $instalacion;
        $this->clienteApi = new ClienteApiInstalacion($instalacion);
    }

    /**
     * Versión del core y plugins de la instalación remota, o null si no se puede consultar.
     */
    public function about(): ?array
    {
        if (false === $this->aboutConsultado) {
            $this->aboutConsultado = true;
            $this->about = $this->puedeConectar() ? $this->clienteApi->about() : null;
        }

        return $this->about;
    }

    public function clienteApi(): ClienteApiInstalacion
    {
        return $this->clienteApi;
    }

    /**
     * Hay una URL válida y una API key: se puede consultar la instalación y probar la conexión.
     */
    public function puedeConectar(): bool
    {
        return false !== filter_var((string)$this->instalacion->url, FILTER_VALIDATE_URL)
            && '' !== trim((string)$this->instalacion->apikey);
    }

    /**
     * VisorErrores activo y con la API REST (desde la versión 1.6).
     */
    public function tieneVisorErrores(): bool
    {
        return (bool)array_filter(
            $this->about()['plugins'] ?? [],
            fn(array $plugin) => 'VisorErrores' === $plugin['name'] && $plugin['enabled']
                && (float)$plugin['version'] >= self::VERSION_VISOR_ERRORES
        );
    }

    /**
     * Respuesta JSON de las acciones AJAX con el mensaje traducido.
     *
     * @return array{status: string, message: string}
     */
    public static function respuesta(bool $ok, string $clave, array $parametros = []): array
    {
        return ['status' => $ok ? 'success' : 'error', 'message' => Tools::lang()->trans($clave, $parametros)];
    }

    public function urlPermitida(): bool
    {
        return ClienteApiInstalacion::urlPermitida((string)$this->instalacion->url);
    }
}
