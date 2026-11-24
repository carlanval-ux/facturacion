<?php
/**
 * This file is part of PersonalizarEmail plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\PersonalizarEmail\Lib\Email;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\Email\NewMail as CoreNewMail;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AttachedFile;
use PHPMailer\PHPMailer\Exception;

/**
 * Sobrescribe el renderizado del email para poder incluir el logo sin depender
 * de que la instancia sea accesible desde internet:
 * - envío real: imagen incrustada en el mensaje (cid), si emailstyle.logoembed está activo.
 * - vista previa en pantalla: data URI, visible siempre dentro del iframe.
 */
class NewMail extends CoreNewMail
{
    /** Identificador de la imagen incrustada dentro del mensaje. */
    const LOGO_CID = 'company-logo';

    /** Cómo incluir el logo: 'attach' (imagen cid, envío real) o 'data' (data URI, vista previa). */
    protected $logoEmbedMode = 'attach';

    protected function renderHTML(): void
    {
        $logoUrl = $this->embeddedLogoUrl();
        if (empty($logoUrl)) {
            parent::renderHTML();
            return;
        }

        $this->html = Html::render('Email/' . static::$template, [
            'company' => $this->empresa,
            'logoUrl' => $logoUrl,
            'footerBlocks' => $this->getFooterBlocks(),
            'mainBlocks' => $this->getMainBlocks(),
            'title' => $this->title
        ]);
    }

    /**
     * URL embebida del logo (cid: o data:), o cadena vacía para usar
     * el comportamiento del core (enlace a la URL de la instancia).
     */
    private function embeddedLogoUrl(): string
    {
        $logoId = Tools::settings('email', 'idlogo');
        if (empty($logoId)) {
            return '';
        }

        $attFile = AttachedFile::find($logoId);
        if (null === $attFile || false === file_exists($attFile->getFullPath())) {
            return '';
        }

        // vista previa en pantalla: el iframe no puede resolver cid: y puede no
        // tener acceso a la URL de la instancia, así que va dentro del propio html
        if ('data' === $this->logoEmbedMode) {
            $content = file_get_contents($attFile->getFullPath());
            return false === $content ?
                '' :
                'data:' . $attFile->mimetype . ';base64,' . base64_encode($content);
        }

        // envío real: solo incrustamos si el ajuste está activado.
        // El valor puede llegar como bool (guardado) o 'TRUE' (override de la vista previa)
        $embed = Tools::settings('emailstyle', 'logoembed', false);
        if (false === in_array($embed, [true, 1, '1', 'TRUE', 'true'], true)) {
            return '';
        }

        try {
            $this->mail->addEmbeddedImage($attFile->getFullPath(), self::LOGO_CID, basename($attFile->getFullPath()));
        } catch (Exception $ex) {
            Tools::log()->warning($ex->getMessage());
            return '';
        }

        return 'cid:' . self::LOGO_CID;
    }
}
