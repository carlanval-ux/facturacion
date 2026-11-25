<?php
/**
 * VerifactuFacturalo - QR de cotejo y leyenda VERI*FACTU en la cabecera del PDF de las facturas de cliente registradas. Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Extension\Lib\PDF;

use Closure;
use FacturaScripts\Core\Lib\QrImage;
use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Servicio;
use Throwable;

class PDFDocument
{
    public function qrImageHeader(): Closure
    {
        return function ($model) {
            $r = $model->modelClassName() === 'FacturaCliente' ? Servicio::registroDeAlta((int)$model->idfactura) : null;
            if (null === $r || empty($r->qr_url)) {
                return null;
            }
            try {
                return QrImage::png($r->qr_url, QrImage::ECC_M, 5, 1); // la especificación del QR exige corrección de errores nivel M
            } catch (Throwable $e) {
                return null;
            }
        };
    }

    public function qrTitleHeader(): Closure
    {
        return function ($model) {
            $r = $model->modelClassName() === 'FacturaCliente' ? Servicio::registroDeAlta((int)$model->idfactura) : null;
            return $r ? 'VERI*FACTU' . ('pruebas' === $r->entorno ? ' (PRUEBAS)' : '') : null;
        };
    }

    public function qrSubtitleHeader(): Closure
    {
        return function ($model) {
            $r = $model->modelClassName() === 'FacturaCliente' ? Servicio::registroDeAlta((int)$model->idfactura) : null;
            return $r ? 'Factura verificable en la sede electrónica de la AEAT' : null;
        };
    }
}
