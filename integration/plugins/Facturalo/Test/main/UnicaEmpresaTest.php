<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Plugins\Facturalo\Lib\UnicaEmpresa;
use PHPUnit\Framework\TestCase;

final class UnicaEmpresaTest extends TestCase
{
    protected function tearDown(): void
    {
        Tools::settingsSet('facturalo', 'multiempresa', '0');
        Tools::settingsSave();
    }

    public function testNoSePuedeCrearUnaSegundaEmpresa(): void
    {
        Tools::settingsSet('facturalo', 'multiempresa', '0');
        Tools::settingsSave();
        $this->assertTrue(UnicaEmpresa::bloqueaAlta());
        $e = new Empresa();
        $e->nombre = $e->nombrecorto = 'zz-segunda';
        $e->cifnif = '00000000T';
        $this->assertFalse($e->save());
    }

    public function testConElAjusteSePermite(): void
    {
        Tools::settingsSet('facturalo', 'multiempresa', '1');
        Tools::settingsSave();
        $this->assertFalse(UnicaEmpresa::bloqueaAlta());
        $e = new Empresa();
        $e->nombre = $e->nombrecorto = 'zz-segunda';
        $e->cifnif = '00000000T';
        $this->assertTrue($e->save());
        $this->assertTrue($e->delete());
    }
}
