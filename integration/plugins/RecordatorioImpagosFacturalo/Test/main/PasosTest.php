<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\Pasos;
use PHPUnit\Framework\TestCase;

final class PasosTest extends TestCase
{
    private const OFFS = ['previo' => -3, 'dia' => 0, 'post1' => 7, 'post2' => 15];

    public function testAntesDeTodoNoSeEnvia(): void
    {
        $r = Pasos::decidir(self::OFFS, [], '2026-12-10', '2026-12-01');
        $this->assertNull($r['enviar']);
    }

    public function testCadaPasoEnSuDia(): void
    {
        $this->assertSame('previo', Pasos::decidir(self::OFFS, [], '2026-12-10', '2026-12-07')['enviar']);
        $this->assertSame('dia', Pasos::decidir(self::OFFS, ['previo'], '2026-12-10', '2026-12-10')['enviar']);
        $this->assertSame('post1', Pasos::decidir(self::OFFS, ['previo', 'dia'], '2026-12-10', '2026-12-17')['enviar']);
    }

    public function testIdempotente(): void
    {
        $r = Pasos::decidir(self::OFFS, ['previo'], '2026-12-10', '2026-12-08');
        $this->assertNull($r['enviar']);
        $this->assertSame([], $r['omitir']);
    }

    public function testActivacionTardiaSoloEnviaElUltimo(): void
    {
        $r = Pasos::decidir(self::OFFS, [], '2026-12-10', '2026-12-30');
        $this->assertSame('post2', $r['enviar']);
        $this->assertSame(['previo', 'dia', 'post1'], $r['omitir']);
    }

    public function testPasoManualYHorario(): void
    {
        $this->assertSame('previo', Pasos::pasoActual(self::OFFS, '2026-12-10', '2026-11-01'));
        $this->assertSame('post1', Pasos::pasoActual(self::OFFS, '2026-12-10', '2026-12-20'));
        $this->assertTrue(Pasos::enHorario(9, 1, 9, 19, true));
        $this->assertFalse(Pasos::enHorario(19, 1, 9, 19, true));
        $this->assertFalse(Pasos::enHorario(10, 6, 9, 19, true));
        $this->assertTrue(Pasos::enHorario(10, 6, 9, 19, false));
    }

    public function testVariablesTokenYListas(): void
    {
        $t = Pasos::render("Hola {cliente}\n{importe}", ['cliente' => '<b>Ana</b>', 'importe' => '10 €'], true);
        $this->assertSame('Hola &lt;b&gt;Ana&lt;/b&gt;<br />' . "\n" . '10 €', $t);
        $this->assertSame('Hola Ana', Pasos::render('Hola {cliente}', ['cliente' => 'Ana'], false));

        $tk = Pasos::token('C1', 'secreto');
        $this->assertTrue(Pasos::tokenValido('C1', $tk, 'secreto'));
        $this->assertFalse(Pasos::tokenValido('C2', $tk, 'secreto'));
        $this->assertFalse(Pasos::tokenValido('C1', $tk, ''));

        $this->assertSame(['A1', 'B2'], Pasos::lista("a1, b2;\na1"));
        $this->assertSame(-3, Pasos::desplazamiento('previo', 3));
        $this->assertSame(0, Pasos::desplazamiento('dia', 9));
    }
}
