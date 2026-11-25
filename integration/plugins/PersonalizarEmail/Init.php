<?php
/**
 * This file is part of PersonalizarEmail plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\PersonalizarEmail;

use FacturaScripts\Core\Template\InitClass;

class Init extends InitClass
{
    public function init(): void
    {
        // pestaña de ajustes de estilo y vista previa en la configuración de email
        $this->loadExtension(new Extension\Controller\ConfigEmail());

        // pestaña de vista previa al editar cualquier notificación
        $this->loadExtension(new Extension\Controller\EditEmailNotification());
    }

    public function update(): void
    {
    }

    public function uninstall(): void
    {
    }
}
