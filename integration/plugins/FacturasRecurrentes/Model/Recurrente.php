<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/** Plantilla recurrente, o grupo de plantillas que comparten líneas y datos del documento. */
class Recurrente extends ModelClass
{
    use ModelTrait;

    public $idrecurrente;
    public $nombre;
    public $esgrupo;
    public $idgrupo;
    public $activo;
    public $tipo;
    public $codcliente;
    public $codproveedor;
    public $tipodoc;
    public $codserie;
    public $codpago;
    public $observaciones;
    public $unidad;
    public $cada;
    public $fecha_inicio;
    public $fecha_fin;
    public $proxima;
    public $pct_primero;
    public $fecha_pasada;
    public $enviar_email;
    public $creado;

    public function clear(): void
    {
        parent::clear();
        $this->esgrupo = false;
        $this->activo = true;
        $this->tipo = 'venta';
        $this->tipodoc = 'factura';
        $this->unidad = 'meses';
        $this->cada = 1;
        $this->pct_primero = 100.0;
        $this->fecha_pasada = 'ajustada';
        $this->enviar_email = false;
        $this->fecha_inicio = Tools::date();
    }

    /** Configuración efectiva del documento: la del grupo si pertenece a uno. */
    public function config(): self
    {
        if (!empty($this->idgrupo)) {
            $g = new self();
            if ($g->load($this->idgrupo) && $g->esgrupo) {
                return $g;
            }
        }
        return $this;
    }

    public static function primaryColumn(): string
    {
        return 'idrecurrente';
    }

    public static function tableName(): string
    {
        return 'recurrentes';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml($this->nombre);
        $this->observaciones = Tools::noHtml($this->observaciones);
        if (empty($this->nombre)) {
            return false;
        }
        if (!in_array($this->tipo, ['venta', 'compra'], true)
            || !in_array($this->tipodoc, ['factura', 'albaran', 'pedido', 'presupuesto'], true)
            || !in_array($this->unidad, ['dias', 'semanas', 'meses', 'manual'], true)
            || !in_array($this->fecha_pasada, ['ajustada', 'prevista', 'hoy'], true)) {
            Tools::log()->warning('recurring-invalid-data');
            return false;
        }
        $this->cada = max(1, (int)$this->cada);
        $this->pct_primero = (float)$this->pct_primero;
        if ($this->pct_primero <= 0 || $this->pct_primero > 100) {
            $this->pct_primero = 100.0;
        }
        if (empty($this->idrecurrente)) {
            $this->creado = Tools::dateTime();
        }

        if ($this->esgrupo) {
            $this->idgrupo = $this->codcliente = $this->codproveedor = $this->proxima = null;
            $this->unidad = 'manual';
            return parent::test();
        }

        if (!empty($this->idgrupo)) {
            $g = new self();
            if (false === $g->load($this->idgrupo) || !$g->esgrupo) {
                Tools::log()->warning('recurring-invalid-group');
                return false;
            }
            $this->tipo = $g->tipo;
        }
        if (($this->tipo === 'venta' && empty($this->codcliente))
            || ($this->tipo === 'compra' && empty($this->codproveedor))) {
            Tools::log()->warning('recurring-subject-required');
            return false;
        }
        if (empty($this->fecha_inicio)) {
            $this->fecha_inicio = Tools::date();
        }
        // primera fecha prevista = inicio; en modo manual no hay fecha
        if ($this->unidad === 'manual') {
            $this->proxima = null;
        } elseif (empty($this->proxima)) {
            $this->proxima = $this->fecha_inicio;
        }
        return parent::test();
    }
}
