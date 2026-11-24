<?php
/**
 * FacturaZip for FacturaScripts.
 * Copyright (C) 2026
 * License: LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\FacturaZip;

use FacturaScripts\Core\Template\InitClass;

final class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\ListFacturaCliente());
    }

    public function update(): void
    {
    }

    public function uninstall(): void
    {
    }
}
