<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/** Un enlace de aceptación (un solo presupuesto) y, al resolverse, la evidencia de la aceptación o el rechazo. */
class PresupuestoAceptacion extends ModelClass
{
    use ModelTrait;

    public $id;
    public $idpresupuesto;
    public $codigo;
    public $tokenhash;
    public $hashdoc;
    public $estado;
    public $email;
    public $nick;
    public $creado;
    public $caduca;
    public $visto;
    public $resuelto;
    public $nombre;
    public $ip;
    public $useragent;
    public $motivo;
    public $hashresuelto;

    public function clear(): void
    {
        parent::clear();
        $this->estado = 'enviado';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'presupuestos_aceptacion';
    }
}
