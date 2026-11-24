<?php
/**
 * This file is part of PersonalizarEmail plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\PersonalizarEmail\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Plugins\PersonalizarEmail\Lib\EmailPreview;

/**
 * Añade a la configuración de email una pestaña de personalización de estilo
 * (colores y pie, guardados en el grupo de ajustes "emailstyle") y una pestaña
 * de vista previa de las notificaciones.
 */
class ConfigEmail
{
    public function createViews(): Closure
    {
        return function () {
            $this->addEditView('ConfigEmailStyle', 'Settings', 'email-customization', 'fa-solid fa-palette')
                ->setSettings('btnNew', false)
                ->setSettings('btnDelete', false);

            $this->addHtmlView('EmailPreview', 'Tab/EmailPreview', 'EmailNotification', 'preview', 'fa-solid fa-eye');
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'ConfigEmailStyle') {
                return;
            }

            $view->loadData('emailstyle');
            $view->model->name = 'emailstyle';

            // rellenamos los valores por defecto (los del core) para que el
            // formulario muestre los colores actuales aunque nunca se haya guardado
            $defaults = [
                'colortitle' => '#3D67AC',
                'colorbg' => '#f6f9fc',
                'colorcontainer' => '#FFFFFF',
                'colorbtn' => '#2E7EFA',
                'titlesize' => 32,
                'logoposition' => 'top',
                'logoalign' => 'center',
                'bodycolor' => '#000000',
                'bodysize' => 16,
                'footercolor' => '#000000',
                'footersize' => 10,
            ];
            foreach ($defaults as $key => $value) {
                if (empty($view->model->{$key})) {
                    $view->model->{$key} = $value;
                }
            }
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action === 'email-preview') {
                $this->setTemplate(false);
                $this->response->json(EmailPreview::fromRequest($this->request));
                return false;
            }

            if ($action === 'email-preview-send') {
                $this->setTemplate(false);
                $result = $this->validateFormToken() ?
                    EmailPreview::sendFromRequest($this->request) :
                    ['ok' => false, 'error' => Tools::trans('invalid-request')];

                // devolvemos un token nuevo para poder repetir el envío sin recargar
                $result['token'] = $this->multiRequestProtection->newToken();
                $this->response->json($result);
                return false;
            }
        };
    }

    /**
     * Lista de notificaciones para el selector de la pestaña de vista previa.
     * Se invoca desde Twig como fsc.emailPreviewNotifications().
     */
    public function emailPreviewNotifications(): Closure
    {
        return function () {
            return EmailNotification::all([], ['name' => 'ASC'], 0, 0);
        };
    }
}
