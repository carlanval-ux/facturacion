<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Facturalo\Controller\Login;
use PHPUnit\Framework\TestCase;

final class LoginEmailTest extends TestCase
{
    private function crear(string $nick, string $email, bool $enabled = true): User
    {
        $u = new User();
        $u->nick = $nick;
        $u->email = $email;
        $u->enabled = $enabled;
        $this->assertTrue($u->setPassword('Prueba123'));
        $this->assertTrue($u->save());
        return $u;
    }

    public function testEmailResuelveElNickSinDistinguirMayusculas(): void
    {
        $u = $this->crear('lg-uno', 'lg-uno@example.com');
        $this->assertSame('lg-uno', Login::resolveNick('LG-Uno@Example.COM'));
        $this->assertNull(Login::resolveNick('lg-uno'));
        $u->delete();
    }

    public function testEmailInexistenteODeshabilitadoNoResuelve(): void
    {
        $u = $this->crear('lg-off', 'lg-off@example.com', false);
        $this->assertNull(Login::resolveNick('nadie@example.com'));
        $this->assertNull(Login::resolveNick('lg-off@example.com'));
        $u->delete();
    }

    public function testEmailAmbiguoNoResuelve(): void
    {
        $a = $this->crear('lg-a', 'lg-dup@example.com');
        $b = $this->crear('lg-b', 'lg-dup@example.com');
        $this->assertNull(Login::resolveNick('lg-dup@example.com'));
        $a->delete();
        $b->delete();
    }
}
