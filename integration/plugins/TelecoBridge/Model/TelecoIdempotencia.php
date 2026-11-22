<?php
namespace FacturaScripts\Plugins\TelecoBridge\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/** Reservas de claves de idempotencia. La tabla se crea desde Table/teleco_idempotencia.xml. */
class TelecoIdempotencia extends ModelClass
{
    use ModelTrait;

    public $clave;
    public $hash;
    public $estado;
    public $http;
    public $respuesta;
    public $creado;

    public static function primaryColumn(): string
    {
        return 'clave';
    }

    public static function tableName(): string
    {
        return 'teleco_idempotencia';
    }
}
