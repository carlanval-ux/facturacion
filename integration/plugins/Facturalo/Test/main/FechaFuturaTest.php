<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\Facturalo\Lib\FechaFutura;
use PHPUnit\Framework\TestCase;

final class FechaFuturaTest extends TestCase
{
    public function testComparaSoloLaFecha(): void
    {
        $this->assertFalse(FechaFutura::esFutura('24-11-2026', '2026-11-24 23:59:59'));
        $this->assertFalse(FechaFutura::esFutura('24-11-2026', '2026-11-24 00:01:00'));
        $this->assertTrue(FechaFutura::esFutura('25-11-2026', '2026-11-24 23:59:59'));
        $this->assertFalse(FechaFutura::esFutura('23-11-2026', '2026-11-24'));
    }

    public function testEjercicioDistinto(): void
    {
        $this->assertTrue(FechaFutura::esFutura('01-01-2027', '2026-12-31 23:59:00'));
        $this->assertFalse(FechaFutura::esFutura('31-12-2025', '2026-01-01 00:01:00'));
    }

    public function testFechaVaciaONoValida(): void
    {
        $this->assertFalse(FechaFutura::esFutura('', '2026-11-24'));
        $this->assertFalse(FechaFutura::esFutura(null, '2026-11-24'));
    }
}
