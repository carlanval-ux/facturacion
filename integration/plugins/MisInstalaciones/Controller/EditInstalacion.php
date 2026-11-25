<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Controller;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Dinamic\Lib\ExtendedController\EditController;
use FacturaScripts\Plugins\MisInstalaciones\Lib\PanelInstalacion;
use FacturaScripts\Plugins\MisInstalaciones\Lib\PluginsInstalacion;
use FacturaScripts\Plugins\MisInstalaciones\Lib\VisorErroresInstalacion;
use FacturaScripts\Plugins\MisInstalaciones\Model\Instalacion;

/**
 * Ficha de una instalación. Las pestañas remotas se cargan por AJAX: la página nunca espera a la instalación remota
 * y la API key solo se envía al navegador en el formulario.
 */
class EditInstalacion extends EditController
{
    private const ACCIONES_AJAX = ['remoto-cargar', 'remoto-test', 'plugin-toggle', 'error-delete', 'error-delete-all'];

    /** vista => [título, icono] */
    private const PESTANAS = [
        'ErroresInstalacion' => ['instalacion-visor-errores', 'fa-solid fa-triangle-exclamation'],
        'LogsBdInstalacion' => ['instalacion-logs-bd', 'fa-solid fa-database'],
        'PluginsInstalacion' => ['instalacion-plugins', 'fa-solid fa-box-open'],
        'AcercaInstalacion' => ['about', 'fa-solid fa-circle-info'],
        'UsuariosInstalacion' => ['users', 'fa-solid fa-users'],
    ];

    private ?PanelInstalacion $panel = null;

    public function getModelClassName(): string
    {
        return 'Instalacion';
    }

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'instalacion';
        $pageData['menu'] = 'admin';
        $pageData['icon'] = 'fa-solid fa-server';
        return $pageData;
    }

    /**
     * La pestaña principal (Datos) no es la primera, así que el core debe tomarla de forma explícita.
     */
    public function mainTabName(): string
    {
        return 'EditInstalacion';
    }

    private function accionRemota(string $action, Instalacion $instalacion): array
    {
        $panel = new PanelInstalacion($instalacion);
        if (false === $panel->urlPermitida()) {
            return $this->error('instalacion-url-https-obligatoria');
        }

        switch ($action) {
            case 'remoto-test':
                $ok = null !== $panel->about();
                return PanelInstalacion::respuesta($ok, $ok ? 'instalacion-conexion-correcta' : 'instalacion-sin-conexion');

            case 'plugin-toggle':
                if (false === $this->permissions->allowUpdate || false === $this->validateFormToken()) {
                    return $this->error('not-allowed-update');
                }
                return (new PluginsInstalacion($panel))->alternar(
                    (string)$this->request->input('plugin', ''),
                    (string)$this->request->input('modo', '')
                );

            case 'error-delete':
            case 'error-delete-all':
                if (false === $this->permissions->allowDelete || false === $this->validateFormToken()) {
                    return $this->error('not-allowed-delete');
                }
                $visor = new VisorErroresInstalacion($panel);
                return 'error-delete' === $action ?
                    $visor->eliminar((string)$this->request->input('hash', '')) :
                    $visor->eliminarTodos();
        }

        return $this->cargarPestana($panel, (string)$this->request->input('seccion', ''));
    }

    /**
     * HTML de una pestaña remota y si la instalación tiene VisorErrores 1.6+ (para mostrar u ocultar sus pestañas).
     */
    private function cargarPestana(PanelInstalacion $panel, string $vista): array
    {
        $about = $panel->about();
        if (null === $about) {
            return $this->error('instalacion-sin-conexion');
        }

        switch ($vista) {
            case 'PluginsInstalacion':
                $datos = ['plugins' => $about['plugins']];
                break;

            case 'AcercaInstalacion':
                $usuarios = $panel->clienteApi()->usuarios();
                $datos = ['acerca' => [
                    'version' => $about['version'],
                    'plugins' => count($about['plugins']),
                    'usuarios' => null === $usuarios ? null : count($usuarios),
                ]];
                break;

            case 'UsuariosInstalacion':
                $datos = ['usuarios' => $panel->clienteApi()->usuarios()];
                break;

            case 'ErroresInstalacion':
                $datos = ['errores' => (new VisorErroresInstalacion($panel))->errores()];
                break;

            case 'LogsBdInstalacion':
                $datos = ['logs' => (new VisorErroresInstalacion($panel))->logsBd()];
                break;

            default:
                return $this->error('instalacion-accion-invalida');
        }

        return [
            'status' => 'success',
            'html' => Html::render('Remoto/' . substr($vista, 0, -strlen('Instalacion')) . '.html.twig', $datos),
            'visorErrores' => $panel->tieneVisorErrores(),
        ];
    }

    protected function createViews(): void
    {
        // las pestañas remotas solo existen si hay una instalación guardada; su contenido llega por AJAX
        $code = (string)$this->request->inputOrQuery('code', '');
        $instalacion = new Instalacion();
        if ('' !== $code && $instalacion->load($code)) {
            $this->panel = new PanelInstalacion($instalacion);
            foreach (self::PESTANAS as $vista => [$titulo, $icono]) {
                $this->addHtmlView($vista, 'Tab/Remota', 'Instalacion', $titulo, $icono);
                $this->setSettings($vista, 'btnPrint', false);
            }
            AssetManager::addJs(Tools::config('route') . '/Dinamic/Assets/JS/EditInstalacion.js?v=' . Tools::date());
        }
        parent::createViews();
        $this->addHtmlView('AyudaInstalacion', 'Tab/Ayuda', 'Instalacion', 'instalacion-ayuda', 'fa-solid fa-circle-question');

        $this->tab($this->mainTabName())->title = Tools::lang()->trans('instalacion-datos');
        // la exportación de la ficha incluiría la API key
        $this->setSettings($this->mainTabName(), 'btnPrint', false);
        $this->setTabsPosition('top');
    }

    private function error(string $clave): array
    {
        return PanelInstalacion::respuesta(false, $clave);
    }

    protected function execAfterAction($action): void
    {
        parent::execAfterAction($action);

        // la ayuda se muestra siempre, también en una instalación nueva
        $this->setSettings('AyudaInstalacion', 'active', true);
        if (null === $this->panel) {
            return;
        }

        if (false === $this->panel->urlPermitida()) {
            Tools::log()->warning('instalacion-url-https-obligatoria');
            return;
        }

        if ($this->panel->puedeConectar()) {
            $this->tab($this->mainTabName())->addButton([
                'action' => 'instalacionTestConexion()',
                'color' => 'info',
                'icon' => 'fa-solid fa-plug',
                'label' => 'instalacion-test-conexion',
                'type' => 'js',
            ]);
        }
    }

    protected function execPreviousAction($action)
    {
        // mismo criterio que EditController::loadData(): code de la URL o id del formulario
        $instalacion = $this->getModel();
        $code = (string)$this->request->inputOrQuery('code', $this->request->input($instalacion->primaryColumn(), ''));
        $this->hasData = '' !== $code && $instalacion->load($code);
        if (false === $this->hasData) {
            $this->active = $this->mainTabName();
        }

        if (in_array($action, self::ACCIONES_AJAX, true)) {
            $this->setTemplate(false);
            $respuesta = $this->hasData ? $this->accionRemota($action, $instalacion) : $this->error('instalacion-accion-invalida');
            // los tokens son de un solo uso: cada respuesta entrega el siguiente
            $this->response->json($respuesta + ['token' => $this->multiRequestProtection->newToken()]);
            return false;
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        // la cabecera (recargar, nuevo, migas) usa la primera pestaña: sus modelos también deben cargar la instalación
        // la API key solo puede llegar al navegador en el formulario de datos
        if ($viewName !== $this->mainTabName()) {
            $view->loadData((string)$this->getModel()->id());
            $view->model->loadFromData(['apikey' => '']);
        }
    }
}
