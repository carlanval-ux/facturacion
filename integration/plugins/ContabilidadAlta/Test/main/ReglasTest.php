<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\ContabilidadAlta\Lib\Reglas;
use PHPUnit\Framework\TestCase;

final class ReglasTest extends TestCase
{
    public function testCodigoAlfanumericoHastaDiez(): void
    {
        $this->assertTrue(Reglas::codigoValido('TVC000123'));
        $this->assertTrue(Reglas::codigoValido('1'));
        $this->assertFalse(Reglas::codigoValido('AB_12'));
        $this->assertFalse(Reglas::codigoValido('A.B'));
        $this->assertFalse(Reglas::codigoValido('ABCDEFGHIJK')); // 11
        $this->assertFalse(Reglas::codigoValido(''));
    }

    public function testSubcuentaALongitudDelEjercicio(): void
    {
        $this->assertSame('4770000000', Reglas::subcuenta('477', 10));
        $this->assertSame('477000000', Reglas::subcuenta('477', 9));
        $this->assertSame('47700000000', Reglas::subcuenta('477', 11));
        $this->assertSame('4751000000', Reglas::subcuenta('4751', 10));
    }

    public function testSecuenciaNuevaPorDefectoTomaElPatronDeSerie(): void
    {
        foreach (['', '{EJE}{SERIE}{0NUM}', 'F{EJE}{SERIE}{NUM}'] as $patron) {
            $s = Reglas::secuenciaFactura(['patron' => $patron, 'longnumero' => 4, 'usarhuecos' => true, 'mantenerfecha' => true], true);
            $this->assertSame('{SERIE}{EJE}-{0NUM}', $s['patron']);
            $this->assertSame(6, $s['longnumero']);
            $this->assertFalse($s['usarhuecos']);
            $this->assertFalse($s['mantenerfecha']);
        }
    }

    public function testSecuenciaExistenteSoloPierdeHuecosYFecha(): void
    {
        $s = Reglas::secuenciaFactura(['patron' => 'F{EJE}{SERIE}{NUM}', 'longnumero' => 4, 'usarhuecos' => true, 'mantenerfecha' => true], false);
        $this->assertSame('F{EJE}{SERIE}{NUM}', $s['patron']);
        $this->assertSame(4, $s['longnumero']);
        $this->assertFalse($s['usarhuecos']);
        $this->assertFalse($s['mantenerfecha']);
    }

    public function testSecuenciaNuevaConPatronPropioLoConserva(): void
    {
        $s = Reglas::secuenciaFactura(['patron' => 'X{SERIE}{0NUM}', 'longnumero' => 5, 'usarhuecos' => true, 'mantenerfecha' => false], true);
        $this->assertSame('X{SERIE}{0NUM}', $s['patron']);
        $this->assertFalse($s['usarhuecos']);
    }

    public function testPeriodos(): void
    {
        $err = [];
        $p = Reglas::periodos('2026-T1, 2025 2026-05;2026-07-01..2026-07-15', $err);
        $this->assertSame([], $err);
        $this->assertSame([['2026-01-01', '2026-03-31'], ['2025-01-01', '2025-12-31'], ['2026-05-01', '2026-05-31'], ['2026-07-01', '2026-07-15']], $p);
        $this->assertSame([['2026-10-01', '2026-12-31']], Reglas::periodos('2026-T4'));
    }

    public function testPeriodosNoValidosSeInforman(): void
    {
        $err = [];
        $this->assertSame([], Reglas::periodos('2026-T5 foo 2026-13 2026-02-01..2026-01-01', $err));
        $this->assertSame(['2026-T5', 'foo', '2026-13', '2026-02-01..2026-01-01'], $err);
    }

    public function testFechaCerrada(): void
    {
        $p = Reglas::periodos('2026-T1,2026-05');
        $this->assertTrue(Reglas::fechaCerrada('31-03-2026', $p));
        $this->assertTrue(Reglas::fechaCerrada('2026-01-01', $p));
        $this->assertTrue(Reglas::fechaCerrada('15-05-2026 10:00:00', $p));
        $this->assertFalse(Reglas::fechaCerrada('01-04-2026', $p));
        $this->assertFalse(Reglas::fechaCerrada('', $p));
        $this->assertFalse(Reglas::fechaCerrada(null, $p));
        $this->assertFalse(Reglas::fechaCerrada('31-03-2026', []));
    }
}
