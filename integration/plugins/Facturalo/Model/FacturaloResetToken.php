<?php
namespace FacturaScripts\Plugins\Facturalo\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/** Tokens de recuperación de contraseña (solo el hash SHA-256; el token en claro viaja únicamente en el correo). */
class FacturaloResetToken extends ModelClass
{
    use ModelTrait;

    public $tokenhash;
    public $nick;
    public $expira;

    public static function primaryColumn(): string
    {
        return 'tokenhash';
    }

    public static function tableName(): string
    {
        return 'facturalo_reset_tokens';
    }
}
