<?php
/**
 * VerifactuFacturalo - Registro de eventos (altas de certificado, cambios de modo, envíos, errores). Nunca contiene claves ni contenido del certificado.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class VfpEvento extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;
    /** @var int */
    public $idempresa;
    /** @var string */
    public $fecha;
    /** @var string */
    public $tipo;
    /** @var string */
    public $detalle;
    /** @var string */
    public $nick;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'vfp_eventos';
    }

    public static function registrar(?int $idempresa, string $tipo, string $detalle = '', ?string $nick = null): void
    {
        $e = new static();
        $e->idempresa = $idempresa;
        $e->fecha = Tools::dateTime();
        $e->tipo = $tipo;
        $e->detalle = mb_substr($detalle, 0, 2000);
        $e->nick = $nick;
        $e->save();
    }
}
