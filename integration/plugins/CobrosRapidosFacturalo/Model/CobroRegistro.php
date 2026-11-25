<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/** Registro de auditoría de cobros y anulaciones: quién, cuándo, qué recibo e importe. */
class CobroRegistro extends ModelClass
{
    use ModelTrait;

    public $id;
    public $fecha;
    public $nick;
    /** @var string cobro | anulacion */
    public $accion;
    /** @var string agrupa los recibos cobrados en una misma operación */
    public $lote;
    public $idrecibo;
    public $idfactura;
    public $codigofactura;
    public $codcliente;
    public $importe;
    public $fechacobro;
    public $codpago;
    public $codcuenta;
    public $observaciones;
    public $motivo;
    /** @var bool solo en filas 'cobro': ya se anuló */
    public $anulado;

    public function clear(): void
    {
        parent::clear();
        $this->fecha = Tools::dateTime();
        $this->anulado = false;
        $this->importe = 0.0;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'cobros_registro';
    }

    public function test(): bool
    {
        $this->observaciones = Tools::noHtml((string)$this->observaciones);
        $this->motivo = Tools::noHtml((string)$this->motivo);
        return parent::test();
    }
}
