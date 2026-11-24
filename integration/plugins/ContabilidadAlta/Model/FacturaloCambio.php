<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/** Cambios en facturas y recibos de cliente (outbox) para que la gestoría detecte cobros, rectificativas y estados sin releer todo. */
class FacturaloCambio extends ModelClass
{
    use ModelTrait;

    public $id;
    public $tipo;
    public $idref;
    public $idfactura;
    public $idfacturarect;
    public $evento;
    public $creado;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'facturalo_cambios';
    }
}
