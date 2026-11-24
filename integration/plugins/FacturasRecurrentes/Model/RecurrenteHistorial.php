<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/** Un intento/resultado de generación por plantilla y fecha prevista (clave única = idempotencia). */
class RecurrenteHistorial extends ModelClass
{
    use ModelTrait;

    public $idhistorial;
    public $idrecurrente;
    public $clave;
    public $fecha_prevista;
    public $fecha_doc;
    public $estado;
    public $intentos;
    public $tipodoc;
    public $iddoc;
    public $codigo;
    public $mensaje;
    public $envio;
    public $actualizado;

    public static function primaryColumn(): string
    {
        return 'idhistorial';
    }

    public static function tableName(): string
    {
        return 'recurrentes_historial';
    }
}
