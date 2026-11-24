<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\FacturasRecurrentes\Lib\Recurrencia;
use PHPUnit\Framework\TestCase;

final class RecurrenciaTest extends TestCase
{
    public function testDiasYSemanas(): void
    {
        $this->assertSame('2026-01-11', Recurrencia::siguiente('2026-01-01', 10, 'dias'));
        $this->assertSame('2026-01-15', Recurrencia::siguiente('01-01-2026', 2, 'semanas'));
    }

    public function testMesesConservanElDiaSinDesbordar(): void
    {
        $this->assertSame('2026-02-28', Recurrencia::siguiente('2026-01-31', 1, 'meses', '2026-01-31'));
        $this->assertSame('2026-03-31', Recurrencia::siguiente('2026-02-28', 1, 'meses', '2026-01-31'));
        $this->assertSame('2027-01-15', Recurrencia::siguiente('2026-12-15', 1, 'meses'));
        $this->assertSame('2026-07-15', Recurrencia::siguiente('2026-01-15', 6, 'meses'));
    }

    public function testManualNoTieneSiguiente(): void
    {
        $this->assertNull(Recurrencia::siguiente('2026-01-01', 1, 'manual'));
    }

    public function testFechaNuncaFutura(): void
    {
        $this->assertSame('2026-03-01', Recurrencia::fechaDocumento('2026-03-01', '2026-03-05', 'prevista'));
        $this->assertSame('2026-03-05', Recurrencia::fechaDocumento('2026-03-01', '2026-03-05', 'hoy'));
        $this->assertSame('2026-03-05', Recurrencia::fechaDocumento('2026-04-01', '2026-03-05', 'prevista'));
    }
}
