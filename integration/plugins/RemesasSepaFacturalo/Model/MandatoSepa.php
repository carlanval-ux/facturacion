<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\IbanProtegidoTrait;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\SepaTexto;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\SepaXml;

/** Mandato SEPA de un cliente (autorización de cobro por domiciliación). */
class MandatoSepa extends ModelClass
{
    use ModelTrait;
    use IbanProtegidoTrait;

    /** @var int */
    public $idmandato;
    /** @var string */
    public $codcliente;
    /** @var string referencia única del mandato (MndtId) */
    public $referencia;
    /** @var string */
    public $bic;
    /** @var string */
    public $fechafirma;
    /** @var string CORE | B2B */
    public $tipo;
    /** @var string FRST | RCUR | FNAL | OOFF */
    public $secuencia;
    /** @var string activo | revocado */
    public $estado;
    /** @var string */
    public $observaciones;

    public function clear(): void
    {
        parent::clear();
        $this->tipo = 'CORE';
        $this->secuencia = 'FRST';
        $this->estado = 'activo';
        $this->fechafirma = Tools::date();
    }

    public function install(): string
    {
        new Cliente(); // dependencia de la clave ajena
        return parent::install();
    }

    public function getCliente(): Cliente
    {
        $c = new Cliente();
        $c->load($this->codcliente);
        return $c;
    }

    public static function primaryColumn(): string
    {
        return 'idmandato';
    }

    /** Mandato activo del cliente (el más reciente), o null. */
    public static function activoDe(string $codcliente): ?self
    {
        return self::findWhere([Where::eq('codcliente', $codcliente), Where::eq('estado', 'activo')], ['idmandato' => 'DESC']);
    }

    public static function tableName(): string
    {
        return 'sepa_mandatos';
    }

    public function test(): bool
    {
        $this->observaciones = Tools::noHtml((string)$this->observaciones);
        if (empty($this->codcliente)) {
            Tools::log()->error('customer-required');
            return false;
        }
        if (!in_array($this->tipo, SepaXml::TIPOS, true) || !in_array($this->secuencia, SepaXml::SECUENCIAS, true)
            || !in_array($this->estado, ['activo', 'revocado'], true)) {
            Tools::log()->error('invalid-mandate-data');
            return false;
        }
        if (empty($this->fechafirma) || strtotime($this->fechafirma) > strtotime('today')) {
            Tools::log()->error('mandate-date-invalid');
            return false;
        }

        // referencia única: si falta, se genera
        $this->referencia = SepaTexto::id((string)$this->referencia);
        if ($this->referencia === '') {
            $this->referencia = SepaTexto::id('M' . $this->codcliente . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))));
        }
        $otro = self::findWhere([Where::eq('referencia', $this->referencia)]);
        if ($otro && $otro->idmandato != $this->idmandato) {
            Tools::log()->error('mandate-reference-duplicated');
            return false;
        }

        return $this->testIban(true) && parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListRemesaSepa?activetab=ListMandatoSepa'): string
    {
        return parent::url($type, $list);
    }
}
