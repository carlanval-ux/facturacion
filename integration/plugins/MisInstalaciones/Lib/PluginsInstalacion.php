<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Lib;

use FacturaScripts\Core\Tools;

/**
 * Pestaña Plugins: activa o desactiva plugins de la instalación remota.
 */
class PluginsInstalacion
{
    private PanelInstalacion $panel;

    public function __construct(PanelInstalacion $panel)
    {
        $this->panel = $panel;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function alternar(string $plugin, string $accion): array
    {
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/', $plugin) || false === in_array($accion, ['enable', 'disable'], true)) {
            return PanelInstalacion::respuesta(false, 'instalacion-accion-invalida');
        }

        if (false === $this->panel->clienteApi()->accionPlugin($plugin, $accion)) {
            return PanelInstalacion::respuesta(false, 'instalacion-plugin-error', [
                '%plugin%' => $plugin,
                '%accion%' => mb_strtolower(Tools::lang()->trans($accion)),
            ]);
        }

        $clave = 'enable' === $accion ? 'instalacion-plugin-activado' : 'instalacion-plugin-desactivado';
        return PanelInstalacion::respuesta(true, $clave, ['%plugin%' => $plugin]);
    }
}
