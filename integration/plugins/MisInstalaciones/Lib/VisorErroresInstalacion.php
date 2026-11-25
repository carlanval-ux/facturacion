<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Lib;

/**
 * Pestañas Visor de Errores y Logs de base de datos: datos del plugin VisorErrores de la instalación remota.
 */
class VisorErroresInstalacion
{
    private PanelInstalacion $panel;

    public function __construct(PanelInstalacion $panel)
    {
        $this->panel = $panel;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function eliminar(string $hash): array
    {
        $ok = 1 === preg_match('/^[a-f0-9]{32}$/', $hash) && $this->panel->clienteApi()->eliminarError($hash);
        return PanelInstalacion::respuesta($ok, $ok ? 'instalacion-error-eliminado' : 'instalacion-error-no-eliminado');
    }

    /**
     * @return array{status: string, message: string}
     */
    public function eliminarTodos(): array
    {
        $resultado = $this->panel->clienteApi()->eliminarErrores();
        if (null === $resultado || $resultado[1] > 0) {
            return PanelInstalacion::respuesta(false, 'instalacion-errores-no-eliminados', [
                '%fallidos%' => (string)($resultado[1] ?? 0),
            ]);
        }

        return PanelInstalacion::respuesta(true, 'instalacion-errores-eliminados', ['%eliminados%' => (string)$resultado[0]]);
    }

    public function errores(): ?array
    {
        return $this->panel->tieneVisorErrores() ? $this->panel->clienteApi()->errores() : null;
    }

    /**
     * Logs del canal de base de datos, con el mensaje decodificado para mostrarlo como texto.
     */
    public function logsBd(): ?array
    {
        $logs = $this->panel->tieneVisorErrores() ? $this->panel->clienteApi()->logs() : null;
        if (null === $logs) {
            return null;
        }

        $bd = array_filter($logs, fn(array $log) => 'database' === $log['channel']);
        return array_map(
            fn(array $log) => ['message' => html_entity_decode($log['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8')] + $log,
            array_values($bd)
        );
    }
}
