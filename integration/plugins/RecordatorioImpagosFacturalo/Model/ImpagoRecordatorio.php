<?php
/**
 * RecordatorioImpagosFacturalo - Historial de recordatorios de impago.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class ImpagoRecordatorio extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;
    /** @var int */
    public $idrecibo;
    /** @var int */
    public $idfactura;
    /** @var string */
    public $codigofactura;
    /** @var string */
    public $codcliente;
    /** @var string previo|dia|post1|post2|post3 */
    public $paso;
    /** @var string|null idrecibo-paso en los envíos automáticos (único: garantiza la idempotencia); null en los manuales */
    public $clave;
    /** @var string */
    public $email;
    /** @var string */
    public $fecha;
    /** @var int */
    public $intentos;
    /** @var string enviando|enviado|error|sin-email|omitido */
    public $resultado;
    /** @var string cron|manual */
    public $origen;
    /** @var string */
    public $nick;
    /** @var string */
    public $mensaje;

    public function clear(): void
    {
        parent::clear();
        $this->intentos = 0;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'impagos_recordatorios';
    }
}
