<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/** Línea de una plantilla o grupo: producto (referencia) o concepto libre. */
class RecurrenteLinea extends ModelClass
{
    use ModelTrait;

    public $idlinea;
    public $idrecurrente;
    public $referencia;
    public $descripcion;
    public $cantidad;
    public $pvpunitario;
    public $dtopor;
    public $codimpuesto;

    public function clear(): void
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->pvpunitario = 0.0;
        $this->dtopor = 0.0;
    }

    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    public static function tableName(): string
    {
        return 'recurrentes_lineas';
    }

    public function test(): bool
    {
        $this->descripcion = Tools::noHtml($this->descripcion);
        $this->referencia = Tools::noHtml($this->referencia) ?: null;
        $this->codimpuesto = $this->codimpuesto ?: null;
        if (empty($this->referencia) && empty($this->descripcion)) {
            return false;
        }
        return parent::test();
    }
}
