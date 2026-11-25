<?php
/**
 * VerifactuFacturalo - Ajustes VERI*FACTU por empresa: modo, check «Modo pruebas» y certificado (cifrado con AppKey; nunca en claro).
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class VfpConfig extends ModelClass
{
    use ModelTrait;

    const MODO_VERIFACTU = 'verifactu';
    const MODO_DESACTIVADO = 'desactivado';

    /** @var int */
    public $idempresa;
    /** @var string */
    public $modo = self::MODO_VERIFACTU;
    /** @var bool Por defecto SIEMPRE true: pasar a real es desmarcar este check. */
    public $modo_pruebas = true;
    /** @var bool certificado de sello electrónico (hosts «10» de la AEAT) en vez de personal/representante */
    public $sello = false;
    /** @var string */
    public $cert_nombre;
    /** @var string */
    public $cert_vencimiento;
    /** @var string */
    public $desde;
    /** @var string */
    public $proximo_envio;

    public static function primaryColumn(): string
    {
        return 'idempresa';
    }

    public static function tableName(): string
    {
        return 'vfp_config';
    }

    /** Ajustes de la empresa; si no existen se crean con los valores por defecto (pruebas, sin facturas anteriores a hoy). */
    public static function deEmpresa(int $idempresa): self
    {
        $c = new static();
        if (false === $c->load($idempresa)) {
            $c->idempresa = $idempresa;
            $c->desde = Tools::date();
            $c->save();
        }
        return $c;
    }

    public function activo(): bool
    {
        return $this->modo === self::MODO_VERIFACTU;
    }

    public function entorno(): string
    {
        return $this->modo_pruebas ? 'pruebas' : 'real';
    }

    public function tieneCertificado(): bool
    {
        return VfpCertificado::existe((int)$this->idempresa);
    }

    /** Al cambiar el check «Modo pruebas» se reinicia «desde»: solo se registran las facturas emitidas a partir de ese momento en el nuevo entorno. */
    protected function onChange(string $field): bool
    {
        if ('modo_pruebas' === $field || 'modo' === $field) {
            if ('modo_pruebas' === $field) {
                $this->desde = Tools::date();
            }
            $this->proximo_envio = null;
            VfpEvento::registrar((int)$this->idempresa, 'cambio-' . $field, $field . ' = ' . var_export($this->{$field}, true));
        }
        return parent::onChange($field);
    }
}
