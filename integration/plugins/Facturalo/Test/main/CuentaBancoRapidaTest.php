<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Model\CuentaBanco;
use FacturaScripts\Plugins\Facturalo\Lib\CuentaBancoRapida;
use PHPUnit\Framework\TestCase;

final class CuentaBancoRapidaTest extends TestCase
{
    public function testIbanVacioOInvalidoSeRechaza(): void
    {
        $this->assertFalse(CuentaBancoRapida::crear('', 'x')['ok']);
        $this->assertFalse(CuentaBancoRapida::crear('ES0000000000000000000000', 'x')['ok']);
    }

    public function testCreaCuentaConIbanValidoYDescripcionPorDefecto(): void
    {
        $r = CuentaBancoRapida::crear('ES91 2100 0418 4502 0005 1332', '');
        $this->assertTrue($r['ok']);
        $this->assertSame('Cuenta 1332', $r['descripcion']);
        $c = new CuentaBanco();
        $this->assertTrue($c->load($r['codcuenta']));
        $this->assertSame('ES9121000418450200051332', $c->iban);
        $this->assertNotEmpty($c->idempresa);
        // el mismo IBAN no duplica: devuelve la cuenta existente
        $otra = CuentaBancoRapida::crear('ES9121000418450200051332', 'otra');
        $this->assertTrue($otra['ok']);
        $this->assertTrue($otra['existente']);
        $this->assertSame($r['codcuenta'], $otra['codcuenta']);
        $this->assertTrue($c->delete());
    }
}
