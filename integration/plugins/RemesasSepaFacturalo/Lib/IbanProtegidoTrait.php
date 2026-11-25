<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use FacturaScripts\Core\Tools;

/**
 * IBAN protegido (RGPD): el formulario muestra solo el IBAN enmascarado ($ibanmask); el valor completo
 * solo lo ve el administrador (columna con level=99) y cualquiera puede escribir uno nuevo en $ibannuevo.
 * El IBAN no se escribe nunca en logs ni en mensajes.
 */
trait IbanProtegidoTrait
{
    /** @var string */
    public $iban;
    /** @var string solo lectura, enmascarado */
    public $ibanmask;
    /** @var string campo de escritura para cambiar el IBAN */
    public $ibannuevo;

    protected function testIban(bool $obligatorio): bool
    {
        $nuevo = trim((string)$this->ibannuevo);
        if ($nuevo !== '') {
            if (!Iban::esValido($nuevo)) {
                Tools::log()->error('invalid-iban');
                return false;
            }
            $this->iban = Iban::normalizar($nuevo);
        } elseif (!empty($this->iban)) {
            $this->iban = Iban::normalizar($this->iban);
            if (!Iban::esValido($this->iban)) {
                Tools::log()->error('invalid-iban');
                return false;
            }
        } elseif ($obligatorio) {
            Tools::log()->error('iban-required');
            return false;
        }

        $this->ibannuevo = null;
        $this->ibanmask = Iban::formato($this->iban);
        $this->bic = strtoupper(trim((string)$this->bic));
        if ($this->bic !== '' && !Iban::bicValido($this->bic)) {
            Tools::log()->error('invalid-bic');
            return false;
        }
        return true;
    }
}
