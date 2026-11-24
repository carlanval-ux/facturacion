<?php
/**
 * RecordatorioImpagosFacturalo - Recordatorio de impagos automático (módulo «impagos»).
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\Recordatorios;

class Init extends InitClass
{
    public function init(): void
    {
    }

    public function uninstall(): void
    {
        // conservamos historial y ajustes por si se reinstala
    }

    public function update(): void
    {
        $g = Recordatorios::GRUPO;
        $firma = "\n\n{empresa}";
        $defaults = [
            'activo' => false,
            // por defecto no se reclaman recibos que vencieron antes de activar el módulo
            'desde' => Tools::date(),
            'hora_ini' => 9,
            'hora_fin' => 19,
            'laborables' => true,
            'max_por_pasada' => 50,
            'adjuntar_pdf' => true,
            'remitente_nombre' => '',
            'remitente_email' => '',
            'excl_clientes' => '',
            'excl_facturas' => '',
            'previo_activo' => true,
            'previo_dias' => 3,
            'previo_asunto' => 'Recordatorio: la factura {numero} vence el {vencimiento}',
            'previo_cuerpo' => "Estimado/a {cliente}:\n\nLe recordamos que la factura {numero}, por importe de {importe}, vence el {vencimiento}."
                . "\n\nSi ya ha realizado el pago, puede ignorar este mensaje. Gracias por su confianza." . $firma,
            'dia_activo' => true,
            'dia_dias' => 0,
            'dia_asunto' => 'Hoy vence la factura {numero}',
            'dia_cuerpo' => "Estimado/a {cliente}:\n\nHoy vence la factura {numero}, por importe de {importe}."
                . "\n\nSi ya la ha abonado, muchas gracias y disculpe las molestias." . $firma,
            'post1_activo' => true,
            'post1_dias' => 7,
            'post1_asunto' => 'Factura {numero} pendiente de pago',
            'post1_cuerpo' => "Estimado/a {cliente}:\n\nSegún nuestros registros, la factura {numero}, por importe de {importe}, venció el {vencimiento} y sigue pendiente de pago."
                . "\n\nLe agradeceremos que la regularice a la mayor brevedad. Si ya la ha pagado o desea comentarnos cualquier incidencia, responda a este mensaje." . $firma,
            'post2_activo' => false,
            'post2_dias' => 15,
            'post2_asunto' => 'Segundo aviso: factura {numero} pendiente de pago',
            'post2_cuerpo' => "Estimado/a {cliente}:\n\nLe escribimos de nuevo porque la factura {numero}, por importe de {importe}, vencida el {vencimiento}, continúa pendiente."
                . "\n\nLe rogamos que realice el pago cuanto antes o se ponga en contacto con nosotros para que podamos ayudarle." . $firma,
            'post3_activo' => false,
            'post3_dias' => 30,
            'post3_asunto' => 'Último aviso: factura {numero} pendiente de pago',
            'post3_cuerpo' => "Estimado/a {cliente}:\n\nLa factura {numero}, por importe de {importe}, vencida el {vencimiento}, sigue sin abonarse pese a nuestros avisos anteriores."
                . "\n\nLe pedimos que la regularice con urgencia o nos indique cuándo podrá hacerlo; estaremos encantados de buscar una solución juntos." . $firma,
        ];

        $cambio = false;
        foreach ($defaults as $clave => $valor) {
            if (null === Tools::settings($g, $clave)) {
                Tools::settingsSet($g, $clave, $valor);
                $cambio = true;
            }
        }
        if ($cambio) {
            Tools::settingsSave();
        }
        Recordatorios::secreto(); // crea el secreto de las firmas de baja si falta
    }
}
