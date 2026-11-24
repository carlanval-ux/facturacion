<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\ContabilidadAlta\Lib\Esperado;
use PHPUnit\Framework\TestCase;

final class EsperadoTest extends TestCase
{
    public function testAnexoA4ImpuestosConSubcuentasDeGrupo(): void
    {
        $e = Esperado::plano(10, 2026, ['IGIC7', 'IPSI1']);
        $this->assertSame('4770000000', $e['impuesto.IVA21.codsubcuentarep']);
        $this->assertSame('4720000000', $e['impuesto.IVA4.codsubcuentasop']);
        $this->assertSame('4770000000', $e['impuesto.IVA10.codsubcuentarepre']);
        $this->assertNull($e['impuesto.IVA0.codsubcuentarep']);
        $this->assertSame(5.2, $e['impuesto.IVA21.recargo']);
        $this->assertFalse($e['impuesto.IGIC7.activo']);
        $this->assertFalse($e['impuesto.IPSI1.activo']);
    }

    public function testSubcuentasSeAjustanALaLongitud(): void
    {
        $e = Esperado::plano(9, 2026);
        $this->assertSame('477000000', $e['impuesto.IVA21.codsubcuentarep']);
        $this->assertSame('473000000', $e['retencion.IRPF15.codsubcuentaret']);
        $this->assertSame('475100000', $e['retencion.IRPF15.codsubcuentaacr']);
        $this->assertSame(9, $e['ejercicio.longsubcuenta']);
    }

    public function testSeriesYSecuenciasA5(): void
    {
        $e = Esperado::plano(10, 2026);
        foreach (['A', 'R', 'S'] as $s) {
            $this->assertSame('{SERIE}{EJE}-{0NUM}', $e["secuencia.$s.patron"]);
            $this->assertSame(6, $e["secuencia.$s.longnumero"]);
            $this->assertFalse($e["secuencia.$s.usarhuecos"]);
            $this->assertFalse($e["secuencia.$s.mantenerfecha"]);
        }
        $this->assertSame('R', $e['serie.R.tipo']);
        $this->assertNull($e['serie.A.tipo']);
    }

    public function testAjustesSoloLosAplicables(): void
    {
        $e = Esperado::plano(10, 2026, [], ['decimals', 'codserie']);
        $this->assertSame(2, $e['ajuste.default.decimals']);
        $this->assertSame('A', $e['ajuste.default.codserie']);
        $this->assertArrayNotHasKey('ajuste.default.codpago', $e);
    }

    public function testCompararDetectaYToleraFormas(): void
    {
        $esperado = ['a' => 21, 'b' => false, 'c' => null, 'd' => '4770000000', 'e' => 5.2, 'f' => 0];
        $this->assertSame([], Esperado::comparar($esperado, ['a' => '21', 'b' => 0, 'c' => '', 'd' => '4770000000', 'e' => 5.2000001, 'f' => 0]));
        $dif = Esperado::comparar($esperado, ['a' => 20, 'b' => true, 'd' => '4770000021', 'e' => 5.2]);
        $this->assertSame(['a', 'b', 'd', 'f'], array_column($dif, 'clave')); // f ausente ≠ 0
    }

    public function testPlanFaltas(): void
    {
        $filas = [['4', 'Acreedores', ''], ['47', 'Hacienda', ''], ['472', 'IVA soportado', 'IVASOP'], ['4720000000', 'IVA', ''], ['4770000000', 'IVA rep', '']];
        $ok = Esperado::planFaltas($filas, ['4' => '', '47' => '', '472' => 'IVASOP'], ['4720000000' => '', '4770000000' => '']);
        $this->assertSame(['cuentas_faltantes' => 0, 'subcuentas_faltantes' => 0, 'cuentaesp_distintas' => 0], $ok);

        $mal = Esperado::planFaltas($filas, ['4' => '', '472' => ''], ['4720000000' => '']);
        $this->assertSame(['cuentas_faltantes' => 1, 'subcuentas_faltantes' => 1, 'cuentaesp_distintas' => 1], $mal);
    }
}
