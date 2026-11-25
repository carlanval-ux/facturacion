<?php
/**
 * This file is part of PersonalizarEmail plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\PersonalizarEmail\Lib\Email;

use FacturaScripts\Dinamic\Lib\Email\NewMail;

/**
 * Expone el HTML renderizado de un email sin necesidad de enviarlo.
 * Extiende la clase de Dinamic para respetar las modificaciones de otros plugins.
 */
class PreviewMail extends NewMail
{
    public function preview(): string
    {
        // en pantalla el logo va como data URI: se ve dentro del iframe aunque
        // la instancia no sea pública. El email de prueba (send) usa cid, como
        // el envío real.
        $this->logoEmbedMode = 'data';
        $this->renderHTML();
        $this->logoEmbedMode = 'attach';

        return $this->html;
    }
}
