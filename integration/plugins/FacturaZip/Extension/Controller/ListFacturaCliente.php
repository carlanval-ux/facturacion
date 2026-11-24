<?php
/**
 * Adds a batch ZIP download to the customer invoice list.
 */

namespace FacturaScripts\Plugins\FacturaZip\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturaZip\Lib\InvoiceFileName;
use ZipArchive;

final class ListFacturaCliente
{
    public function execPreviousAction(): Closure
    {
        return function (string $action): bool {
            if ('download-selected-invoices-zip' !== $action) {
                return true;
            }

            if (!$this->permissions->allowExport) {
                Tools::log()->warning('no-print-permission');
                return true;
            }

            $codes = $this->request->request->getArray('codes');
            if (empty($codes)) {
                Tools::log()->warning('no-selected-item');
                return true;
            }

            if (!class_exists(ZipArchive::class)) {
                Tools::log()->error('zip-extension-not-available');
                return true;
            }

            $file = tempnam(sys_get_temp_dir(), 'facturascripts-zip-');
            $zip = new ZipArchive();
            if (false === $zip->open($file, ZipArchive::OVERWRITE)) {
                Tools::log()->error('zip-file-error');
                return true;
            }

            $invoice = new FacturaCliente();
            $count = 0;
            foreach ($codes as $code) {
                if (!$invoice->load($code)) {
                    continue;
                }

                // Use ExportManager rather than a particular PDF implementation.
                // Any plugin that replaces the active PDF exporter is therefore used.
                $export = new ExportManager();
                $export->newDoc('PDF', $invoice->codigo);
                $export->addBusinessDocPage($invoice);
                $pdf = $export->getDoc();
                if (!empty($pdf)) {
                    $zip->addFromString(InvoiceFileName::pdf($invoice), $pdf);
                    ++$count;
                }
                $invoice->clear();
            }
            $zip->close();

            if (0 === $count) {
                @unlink($file);
                Tools::log()->warning('no-selected-item');
                return true;
            }

            $content = file_get_contents($file);
            @unlink($file);
            $this->setTemplate(false);
            $this->response->headers->set('Content-Type', 'application/zip');
            $this->response->headers->set(
                'Content-Disposition',
                'attachment; filename="facturas-seleccionadas.zip"'
            );
            $this->response->setContent($content);
            return false;
        };
    }

    public function loadData(): Closure
    {
        return function (string $viewName): void {
            if ('ListFacturaCliente' !== $viewName || !$this->permissions->allowExport) {
                return;
            }

            $this->addButton($viewName, [
                'action' => 'download-selected-invoices-zip',
                'icon' => 'fa-solid fa-file-zipper',
                'label' => 'Descargar en ZIP',
                'group' => 'invoice-actions',
            ]);
        };
    }
}
