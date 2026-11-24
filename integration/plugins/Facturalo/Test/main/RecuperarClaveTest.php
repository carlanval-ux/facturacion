<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\Facturalo\Controller\RecuperarClave;
use FacturaScripts\Plugins\Facturalo\Model\FacturaloResetToken;
use PHPUnit\Framework\TestCase;

final class RecuperarClaveTest extends TestCase
{
    public function testTokenVigenteSeEncuentraPorSuHash(): void
    {
        $token = bin2hex(random_bytes(32));
        $row = new FacturaloResetToken();
        $row->tokenhash = RecuperarClave::hashToken($token);
        $row->nick = 'test-reset';
        $row->expira = time() + 3600;
        $this->assertTrue($row->save());

        $this->assertNotNull(RecuperarClave::findToken($token));
        $this->assertNull(RecuperarClave::findToken(bin2hex(random_bytes(32))));
        $this->assertNull(RecuperarClave::findToken('no-es-un-token'));

        $row->delete();
        $this->assertNull(RecuperarClave::findToken($token));
    }

    public function testTokenCaducadoNoSirveYSeBorra(): void
    {
        $token = bin2hex(random_bytes(32));
        $row = new FacturaloResetToken();
        $row->tokenhash = RecuperarClave::hashToken($token);
        $row->nick = 'test-reset';
        $row->expira = time() - 1;
        $this->assertTrue($row->save());

        $this->assertNull(RecuperarClave::findToken($token));
        $this->assertFalse((new FacturaloResetToken())->load($row->tokenhash));
    }
}
