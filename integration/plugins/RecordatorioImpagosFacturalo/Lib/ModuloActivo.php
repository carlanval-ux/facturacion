<?php
/**
 * RecordatorioImpagosFacturalo - Recordatorio de impagos automático. Módulo comercial «impagos».
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib;

use FacturaScripts\Core\Tools;

/**
 * Punto único de consulta del control de módulos. Hoy lee el ajuste
 * facturalo.modulos.<clave> (activo si no existe); después se enganchará al
 * control de módulos de la plataforma cambiando solo este método.
 */
class ModuloActivo
{
    public static function estaActivo(string $clave): bool
    {
        $valor = Tools::settings('facturalo', 'modulos.' . $clave);
        if (null === $valor || '' === $valor) {
            return true; // activo por defecto (pruebas)
        }

        return false === in_array(strtolower(trim((string)$valor)), ['0', 'false', 'no', 'off'], true);
    }
}
