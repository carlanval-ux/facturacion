<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\IbanProtegidoTrait;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\SepaXml;

/** Datos del acreedor SEPA de una empresa: identificador (ACI), cuenta de cargo y plazos de presentación. */
class AcreedorSepa extends ModelClass
{
    use ModelTrait;
    use IbanProtegidoTrait;

    /** @var int */
    public $idacreedor;
    /** @var int */
    public $idempresa;
    /** @var string */
    public $nombre;
    /** @var string */
    public $aci;
    /** @var string */
    public $bic;
    /** @var int */
    public $plazofrst;
    /** @var int */
    public $plazorcur;
    /** @var int */
    public $plazob2b;

    public function clear(): void
    {
        parent::clear();
        $this->plazofrst = 5;
        $this->plazorcur = 1;
        $this->plazob2b = 1;
    }

    /** Datos listos para el generador SEPA. */
    public function paraXml(): array
    {
        return ['nombre' => $this->nombre, 'aci' => $this->aci, 'iban' => $this->iban, 'bic' => $this->bic];
    }

    public function plazos(): array
    {
        return ['frst' => (int)$this->plazofrst, 'rcur' => (int)$this->plazorcur, 'b2b' => (int)$this->plazob2b];
    }

    /** Lista de carencias del acreedor de la empresa (vacía si está completo). */
    public static function faltas(int $idempresa): array
    {
        $a = self::findWhere([\FacturaScripts\Core\Where::eq('idempresa', $idempresa)]);
        if (null === $a) {
            return ['creditor-missing'];
        }
        $f = [];
        if (!SepaXml::aciValido($a->aci)) {
            $f[] = 'creditor-id-invalid';
        }
        if (empty($a->iban)) {
            $f[] = 'creditor-iban-invalid';
        }
        return $f;
    }

    public static function primaryColumn(): string
    {
        return 'idacreedor';
    }

    public static function tableName(): string
    {
        return 'sepa_acreedores';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml((string)$this->nombre);
        $this->aci = strtoupper(preg_replace('/\s+/', '', (string)$this->aci));
        if (!SepaXml::aciValido($this->aci)) {
            Tools::log()->error('creditor-id-invalid');
            return false;
        }
        if (!SepaXml::aciControlOk($this->aci)) {
            Tools::log()->warning('creditor-id-check-digit');
        }
        foreach (['plazofrst', 'plazorcur', 'plazob2b'] as $campo) {
            $this->{$campo} = max(0, min(15, (int)$this->{$campo}));
        }
        return $this->testIban(true) && parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListRemesaSepa?activetab=ListAcreedorSepa'): string
    {
        return parent::url($type, $list);
    }
}
