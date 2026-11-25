<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ReciboCliente;

/** Línea de remesa: un recibo de cliente incluido en ella y su resultado. */
class RemesaSepaRecibo extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;
    /** @var int */
    public $idremesa;
    /** @var int */
    public $idrecibo;
    /** @var int */
    public $idmandato;
    /** @var string */
    public $codcliente;
    /** @var float */
    public $importe;
    /** @var string */
    public $fechacobro;
    /** @var string */
    public $secuencia;
    /** @var string remesado | cobrado | devuelto */
    public $estado;
    /** @var string */
    public $motivo;
    /** @var string */
    public $fecharesolucion;

    public function clear(): void
    {
        parent::clear();
        $this->estado = 'remesado';
        $this->importe = 0.0;
    }

    public function install(): string
    {
        new RemesaSepa(); // dependencia de la clave ajena
        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function test(): bool
    {
        $this->motivo = Tools::noHtml(substr((string)$this->motivo, 0, 200));
        if (!in_array($this->estado, ['remesado', 'cobrado', 'devuelto'], true)) {
            Tools::log()->error('invalid-status');
            return false;
        }
        if ($this->estado !== 'remesado' && empty($this->fecharesolucion)) {
            $this->fecharesolucion = Tools::date();
        }
        if ($this->estado === 'devuelto' && trim((string)$this->motivo) === '') {
            Tools::log()->error('return-reason-required');
            return false;
        }
        return parent::test();
    }

    /** Efectos de confirmar el cobro o registrar la devolución (solo desde «remesado»). */
    protected function onChange(string $field): bool
    {
        if (false === parent::onChange($field)) {
            return false;
        }
        if ($field !== 'estado') {
            return true;
        }
        if ($this->getOriginal('estado') !== 'remesado') {
            Tools::log()->warning('sepa-line-already-resolved');
            return false;
        }

        $recibo = new ReciboCliente();
        if (false === $recibo->load($this->idrecibo)) {
            return false;
        }

        if ($this->estado === 'cobrado') {
            if (!$recibo->pagado) {
                $recibo->pagado = true;
                $recibo->fechapago = $this->fecharesolucion;
                if (false === $recibo->save()) {
                    return false;
                }
            }
            $this->avanzarMandato();
            return true;
        }

        // devuelto: el recibo sigue pendiente, con nota del motivo
        $nota = '[SEPA ' . $this->fecharesolucion . '] ' . $this->motivo;
        $recibo->observaciones = trim($recibo->observaciones . "\n" . $nota);
        return $recibo->save();
    }

    protected function onUpdate(): void
    {
        parent::onUpdate();
        // cuando no queda ninguna línea por resolver, la remesa se cierra
        if (self::count([Where::eq('idremesa', $this->idremesa), Where::eq('estado', 'remesado')]) === 0) {
            $remesa = new RemesaSepa();
            if ($remesa->load($this->idremesa) && $remesa->estado === 'generada') {
                $remesa->estado = 'cerrada';
                $remesa->save();
            }
        }
    }

    /** Tras un primer cobro el mandato pasa a recurrente; los de un solo uso/último se extinguen. */
    private function avanzarMandato(): void
    {
        $m = new MandatoSepa();
        if (empty($this->idmandato) || false === $m->load($this->idmandato)) {
            return;
        }
        if ($m->secuencia === 'FRST') {
            $m->secuencia = 'RCUR';
        } elseif (in_array($m->secuencia, ['OOFF', 'FNAL'], true)) {
            $m->estado = 'revocado';
        }
        $m->save();
    }

    public static function tableName(): string
    {
        return 'sepa_remesas_recibos';
    }

    public function url(string $type = 'auto', string $list = 'ListRemesaSepa'): string
    {
        return $type === 'edit' || $type === 'auto'
            ? 'EditRemesaSepa?code=' . (int)$this->idremesa
            : parent::url($type, $list);
    }
}
