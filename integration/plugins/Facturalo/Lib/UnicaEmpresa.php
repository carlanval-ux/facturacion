<?php
namespace FacturaScripts\Plugins\Facturalo\Lib;

use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Settings;

/**
 * Cada tenant de Facturalo es UNA sola empresa (la del cliente). No se pueden crear ni borrar empresas.
 * Excepcion explicita: ajuste facturalo.multiempresa = 1 (pruebas de plataforma, p. ej. teleco-test). Se lee siempre de la base de datos, no de la cache de ajustes.
 */
final class UnicaEmpresa
{
    public static function multiempresa(): bool
    {
        $model = new Settings();
        $value = $model->load('facturalo') ? ($model->getProperties()['multiempresa'] ?? null) : null;
        return in_array((string)$value, ['1', 'true'], true);
    }

    public static function bloqueaAlta(): bool
    {
        return false === self::multiempresa() && (new Empresa())->count() >= 1;
    }

    public static function bloqueaBaja(): bool
    {
        return false === self::multiempresa();
    }
}
