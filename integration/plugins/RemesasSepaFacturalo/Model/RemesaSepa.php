<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/** Remesa de adeudos directos SEPA (cabecera). El XML generado se conserva para volver a descargarlo. */
class RemesaSepa extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $idremesa;
    /** @var int */
    public $idempresa;
    /** @var string identificador del mensaje (MsgId) */
    public $codigo;
    /** @var string */
    public $fecha;
    /** @var string generada | cerrada | anulada */
    public $estado;
    /** @var int */
    public $numrecibos;
    /** @var float */
    public $total;
    /** @var string */
    public $nick;
    /** @var string */
    public $xml;

    public function clear(): void
    {
        parent::clear();
        $this->estado = 'generada';
        $this->numrecibos = 0;
        $this->total = 0.0;
        $this->fecha = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'idremesa';
    }

    public static function tableName(): string
    {
        return 'sepa_remesas';
    }

    public function url(string $type = 'auto', string $list = 'ListRemesaSepa'): string
    {
        return parent::url($type, $list);
    }
}
