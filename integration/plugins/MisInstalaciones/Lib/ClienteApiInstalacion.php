<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Lib;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MisInstalaciones\Model\Instalacion;

/**
 * Consume la API REST del core de una instalación remota.
 */
class ClienteApiInstalacion
{
    private string $apikey;

    private bool $permitida;

    private string $url;

    public function __construct(Instalacion $instalacion)
    {
        $this->apikey = (string)$instalacion->apikey;
        $this->url = (string)$instalacion->url;
        $this->permitida = self::urlPermitida($this->url);
    }

    /**
     * Versión del core y plugins instalados en la instalación remota.
     */
    public function about(): ?array
    {
        $respuesta = $this->peticion('get', 'plugins', ['sort' => ['order' => 'ASC']]);
        if (null === $respuesta) {
            return null;
        }

        return [
            'version' => $respuesta->header('X-Core-Version'),
            'plugins' => $respuesta->json() ?? [],
        ];
    }

    /**
     * Ejecuta una acción (enable o disable) sobre un plugin de la instalación remota.
     */
    public function accionPlugin(string $nombre, string $accion): bool
    {
        return null !== $this->peticion('post', 'plugins/' . $nombre . '/' . $accion, [], 300);
    }

    /**
     * Elimina un error crítico de la instalación remota.
     */
    public function eliminarError(string $hash): bool
    {
        return null !== $this->peticion('delete', 'visorerrores/errores/' . rawurlencode($hash));
    }

    /**
     * Elimina todos los errores críticos de la instalación remota. Devuelve [eliminados, fallidos] o null.
     */
    public function eliminarErrores(): ?array
    {
        $json = $this->peticion('delete', 'visorerrores/errores')?->json();
        return null === $json ? null : [(int)$json['eliminados'], (int)$json['fallidos']];
    }

    /**
     * Errores críticos registrados por el plugin VisorErrores de la instalación remota.
     */
    public function errores(): ?array
    {
        return $this->peticion('get', 'visorerrores/errores')?->json();
    }

    /**
     * Últimos logs de error de la instalación remota (todos los canales).
     */
    public function logs(): ?array
    {
        return $this->peticion('get', 'visorerrores/logs')?->json();
    }

    /**
     * Solo https, salvo que FS_MISINSTALACIONES_PERMITIR_HTTP esté activada (desarrollo).
     */
    public static function urlPermitida(string $url): bool
    {
        return true === Tools::config('FS_MISINSTALACIONES_PERMITIR_HTTP', false)
            || 'https' === parse_url($url, PHP_URL_SCHEME);
    }

    /**
     * Usuarios de la instalación remota con su estado y sus grupos.
     */
    public function usuarios(): ?array
    {
        $usuarios = $this->peticion('get', 'users')?->json();
        if (null === $usuarios) {
            return null;
        }

        $descripciones = array_column($this->peticion('get', 'roles')?->json() ?? [], 'descripcion', 'codrole');
        $gruposPorNick = [];
        foreach ($this->peticion('get', 'roleusers')?->json() ?? [] as $relacion) {
            $gruposPorNick[$relacion['nick']][] = $descripciones[$relacion['codrole']] ?? $relacion['codrole'];
        }

        return array_map(fn(array $usuario) => [
            'nick' => $usuario['nick'],
            'activo' => (bool)$usuario['enabled'],
            'grupos' => $gruposPorNick[$usuario['nick']] ?? [],
        ], $usuarios);
    }

    /**
     * Petición a la API remota (get, post o delete); null si la URL no está permitida o la respuesta no es correcta.
     */
    private function peticion(string $metodo, string $recurso, array $filtros = [], int $timeout = 10): ?Http
    {
        if (false === $this->permitida) {
            return null;
        }

        $datos = 'get' === $metodo ? $filtros + ['limit' => 1000] : $filtros;
        $http = Http::$metodo($this->url . '/api/3/' . $recurso, $datos)
            ->setToken($this->apikey)
            ->setTimeout($timeout);

        return $http->ok() ? $http : null;
    }
}
