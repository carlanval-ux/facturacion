<?php
// Marca blanca de RED TELECOM: los metadatos de los PDF (propiedades del documento) dicen Facturalo.
namespace FacturaScripts\Plugins\Facturalo\Lib\PDF;

abstract class PDFCore extends \FacturaScripts\Core\Lib\PDF\PDFCore
{
    public function newPage(string $orientation = 'portrait', bool $forceNewPage = false)
    {
        parent::newPage($orientation, $forceNewPage);
        $this->pdf->addInfo('Creator', 'Facturalo');
        $this->pdf->addInfo('Producer', 'Facturalo');
    }
}
