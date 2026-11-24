<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Export\PDFExport;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\AcreedorSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\MandatoSepa;

/** Documento PDF del mandato SEPA para firmar, con la cabecera (nombre y logo) de la empresa emisora. */
final class MandatoPdf extends PDFExport
{
    public function generar(MandatoSepa $m, bool $verIban): string
    {
        $cliente = $m->getCliente();
        $empresaId = (int)Tools::settings('default', 'idempresa', 1);
        $acreedor = AcreedorSepa::findWhere([Where::eq('idempresa', $empresaId)]);
        $empresa = new Empresa();
        $empresa->load($empresaId);

        $this->newDoc('mandato-' . $m->referencia, 0, '');
        $this->setCompany($empresaId);
        $p = $this->pdf;
        $p->ezText("\n\n" . Tools::trans('sepa-mandate-title'), 15, ['justification' => 'center']);
        $p->ezText(Tools::trans($m->tipo === 'B2B' ? 'sepa-mandate-subtitle-b2b' : 'sepa-mandate-subtitle-core') . "\n", 9, ['justification' => 'center']);

        $linea = fn(string $k, string $v) => $p->ezText('<b>' . Tools::trans($k) . ':</b> ' . $this->fixValue($v), 10);
        $linea('sepa-mandate-reference', (string)$m->referencia);
        $p->ezText('');
        $p->ezText('<b>' . Tools::trans('sepa-creditor') . '</b>', 11);
        $linea('sepa-name', (string)($acreedor->nombre ?? $empresa->nombre));
        $linea('sepa-creditor-id', (string)($acreedor->aci ?? ''));
        $linea('sepa-address', trim($empresa->direccion . ' ' . $empresa->codpostal . ' ' . $empresa->ciudad));
        $p->ezText('');
        $p->ezText('<b>' . Tools::trans('sepa-debtor') . '</b>', 11);
        $linea('sepa-name', (string)($cliente->razonsocial ?: $cliente->nombre));
        $linea('sepa-fiscal-number', (string)$cliente->cifnif);
        $linea('sepa-iban', Iban::formato($m->iban, !$verIban));
        $linea('sepa-bic', (string)$m->bic);
        $linea('sepa-payment-type', Tools::trans('sepa-recurrent-payment'));
        $p->ezText('');
        $p->ezText($this->fixValue(Tools::trans($m->tipo === 'B2B' ? 'sepa-mandate-legal-b2b' : 'sepa-mandate-legal-core')), 9, ['justification' => 'full']);
        $p->ezText("\n\n");
        $linea('sepa-signature-date', '____ / ____ / ________');
        $linea('sepa-signature-place', '______________________________');
        $p->ezText("\n\n\n" . Tools::trans('sepa-signature-debtor') . ': ______________________________', 10);

        return (string)$this->getDoc();
    }
}
