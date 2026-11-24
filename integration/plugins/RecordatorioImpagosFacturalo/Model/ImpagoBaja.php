<?php
/**
 * RecordatorioImpagosFacturalo - Clientes que han pedido no recibir más recordatorios.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class ImpagoBaja extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;
    /** @var string */
    public $codcliente;
    /** @var string */
    public $fecha;
    /** @var string enlace|manual */
    public $origen;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'impagos_bajas';
    }
}
