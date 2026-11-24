<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Reparto;
use PHPUnit\Framework\TestCase;

final class RepartoTest extends TestCase
{
    public function testReparteEnOrdenYParcial(): void
    {
        $this->assertSame([1 => 100.0, 2 => 20.5], Reparto::distribuir(120.5, [1 => 100.0, 2 => 50.0, 3 => 10.0]));
        $this->assertSame([1 => 30.0], Reparto::distribuir(30, [1 => 100.0]));
    }

    public function testNoSePasaDelImporte(): void
    {
        $this->assertNull(Reparto::distribuir(160.01, [1 => 100.0, 2 => 60.0]));
        $this->assertNull(Reparto::distribuir(0, [1 => 100.0]));
        $this->assertSame([1 => 100.0, 2 => 60.0], Reparto::distribuir(160, [1 => 100.0, 2 => 60.0]));
    }
}
