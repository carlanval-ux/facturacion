<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Facturalo\Lib\Defaults;
use PHPUnit\Framework\TestCase;

final class DefaultsTest extends TestCase
{
    public function testAppliesOnceAndKeepsCustomerChoice(): void
    {
        Tools::settingsSet('default', Defaults::MARKER, '');
        Tools::settingsSet('default', 'codimpuesto', '');
        $this->assertTrue(Defaults::apply());
        $this->assertSame('IVA21', Tools::settings('default', 'codimpuesto'));
        $this->assertTrue((bool)Tools::settings('default', 'ventasinstock'));

        // segunda vez: nada que hacer aunque el cliente cambie su eleccion
        Tools::settingsSet('default', 'codimpuesto', 'IVA10');
        $this->assertFalse(Defaults::apply());
        $this->assertSame('IVA10', Tools::settings('default', 'codimpuesto'));
    }
}
