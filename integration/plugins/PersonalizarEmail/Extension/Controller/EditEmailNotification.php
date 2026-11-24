<?php
/**
 * This file is part of PersonalizarEmail plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\PersonalizarEmail\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\PersonalizarEmail\Lib\EmailPreview;

/**
 * Añade la pestaña de vista previa al editar una notificación de email.
 */
class EditEmailNotification
{
    public function createViews(): Closure
    {
        return function () {
            $this->addHtmlView('EmailPreview', 'Tab/EmailPreview', 'EmailNotification', 'preview', 'fa-solid fa-eye');
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
}
