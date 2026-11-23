<?php
// Marca blanca de RED TELECOM: metadatos del PDF y pie de factura con filete de marca y datos fiscales de la empresa.
namespace FacturaScripts\Plugins\Facturalo\Lib\Export;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Empresa;

class PDFExport extends \FacturaScripts\Core\Lib\Export\PDFExport
{
    public function newPage(string $orientation = 'portrait', bool $forceNewPage = false)
    {
        parent::newPage($orientation, $forceNewPage);
        $this->pdf->addInfo('Creator', 'Facturalo');
        $this->pdf->addInfo('Producer', 'Facturalo');
    }

    protected function insertFooter()
    {
        $left = self::CONTENT_X;
        $right = $this->tableWidth + self::CONTENT_X;
        $y = self::FOOTER_Y;

        // filete rojo de marca encima del pie
        $this->pdf->setStrokeColor(1, 0, 0);
        $this->pdf->setLineStyle(1.5);
        $this->pdf->line($left, $y + 14, $right, $y + 14);

        // datos fiscales de la empresa (de la ficha de empresa, no fijos: cada instalación pone los suyos)
        $company = new Empresa();
        if ($company->load(Tools::settings('default', 'idempresa', ''))) {
            $legal = trim(Tools::fixHtml($company->nombre) . ($company->cifnif ? ' · NIF ' . $company->cifnif : ''));
            $this->pdf->setColor(0.082, 0.176, 0.337);
            $this->pdf->addText($left, $y, self::FONT_SIZE - 1, $legal);
            $this->pdf->setColor(0, 0, 0);
        }

        $now = $this->i18n->trans('generated-at', ['%when%' => date('d-m-Y H:i')]);
        $this->pdf->addText($right, $y, self::FONT_SIZE - 1, $now, 0, 'right');
    }
}
