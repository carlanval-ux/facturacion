<?php
/**
 * VerifactuFacturalo - Registro de facturación (alta o anulación) con su huella, XML generado y estado de envío a la AEAT.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class VfpRegistro extends ModelClass
{
    use ModelTrait;

    const PENDIENTE = 'pendiente';
    const ACEPTADO = 'aceptado';
    const ACEPTADO_ERRORES = 'aceptado_errores';
    const RECHAZADO = 'rechazado';
    const ERROR = 'error';

    /** @var int */
    public $id;
    /** @var int */
    public $idempresa;
    /** @var int */
    public $idfactura;
    /** @var string pruebas|real */
    public $entorno;
    /** @var string alta|anulacion */
    public $tipo;
    /** @var string */
    public $numserie;
    /** @var string */
    public $fecha_expedicion;
    /** @var string */
    public $importe_total;
    /** @var string */
    public $huella;
    /** @var string */
    public $huella_anterior = '';
    /** @var string */
    public $fecha_hora;
    /** @var string */
    public $xml;
    /** @var string */
    public $estado = self::PENDIENTE;
    /** @var string */
    public $csv;
    /** @var string */
    public $codigo_error;
    /** @var string */
    public $mensaje;
    /** @var int */
    public $intentos = 0;
    /** @var string */
    public $proximo_intento;
    /** @var string */
    public $creado;
    /** @var string */
    public $enviado;
    /** @var string */
    public $qr_url;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'vfp_registros';
    }

    /** Un registro aceptado (también con errores) bloquea la factura; uno pendiente/error también, porque ya tiene huella encadenada. */
    public function bloqueaFactura(): bool
    {
        return $this->tipo === 'alta' && $this->estado !== self::RECHAZADO;
    }
}
