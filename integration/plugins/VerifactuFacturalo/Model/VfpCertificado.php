<?php
/**
 * VerifactuFacturalo - Certificado de cliente de la empresa. El .p12 y su contraseña se guardan SIEMPRE cifrados (AES-256-GCM con la FS_APP_KEY de la instalación,
 * enlazados al id de la empresa); si no se puede cifrar, no se guarda. Ninguna vista ni log lee estas columnas.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Model;

use FacturaScripts\Core\AppKey;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class VfpCertificado extends ModelClass
{
    use ModelTrait;

    const PROPOSITO = 'verifactufacturalo-cert';

    /** @var int */
    public $idempresa;
    /** @var string cifrado (base64 del .p12) */
    public $p12;
    /** @var string cifrado */
    public $clave;
    /** @var string */
    public $subido;

    public static function primaryColumn(): string
    {
        return 'idempresa';
    }

    public static function tableName(): string
    {
        return 'vfp_certificados';
    }

    public static function existe(int $idempresa): bool
    {
        return (new static())->load($idempresa);
    }

    /** Guarda cifrado. Devuelve false si no hay forma segura de cifrar (sin openssl o sin FS_APP_KEY). */
    public static function guardar(int $idempresa, string $p12, string $clave): bool
    {
        $c = new static();
        $c->load($idempresa);
        $c->idempresa = $idempresa;
        $c->p12 = AppKey::encrypt(self::PROPOSITO, base64_encode($p12), 'p12:' . $idempresa) ?? '';
        $c->clave = AppKey::encrypt(self::PROPOSITO, $clave, 'clave:' . $idempresa) ?? '';
        if ('' === $c->p12 || '' === $c->clave) {
            return false;
        }
        $c->subido = Tools::dateTime();
        return $c->save();
    }

    /** @return array{p12:string,clave:string}|null el material en claro, solo para usarlo un instante en memoria */
    public static function descifrar(int $idempresa): ?array
    {
        $c = new static();
        if (false === $c->load($idempresa)) {
            return null;
        }
        $p12 = AppKey::decrypt(self::PROPOSITO, $c->p12, 'p12:' . $idempresa);
        $clave = AppKey::decrypt(self::PROPOSITO, $c->clave, 'clave:' . $idempresa);
        if (null === $p12 || null === $clave) {
            return null;
        }
        $bin = base64_decode($p12, true);
        return false === $bin ? null : ['p12' => $bin, 'clave' => $clave];
    }
}
