<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Contenido;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Embudo;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Limite;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\ModuloActivo;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Token;
use PHPUnit\Framework\TestCase;

/** Lógica pura (sin base de datos): token, caducidad, huella, un solo uso, límite y módulo. */
final class AceptacionLogicaTest extends TestCase
{
    public function testTokenLargoAleatorioYSoloSuHashSeGuarda(): void
    {
        $a = Token::generar();
        $b = Token::generar();
        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertTrue(Token::valido($a));
        $this->assertFalse(Token::valido('abc'));
        $this->assertFalse(Token::valido(strtoupper($a)));
        $this->assertFalse(Token::valido($a . 'x'));
        $this->assertSame(hash('sha256', $a), Token::hash($a));
        $this->assertNotSame($a, Token::hash($a));
    }

    public function testCaducidad(): void
    {
        $fila = ['estado' => 'enviado', 'caduca' => 1000, 'hashdoc' => 'h'];
        $this->assertSame(Embudo::ENVIADO, Embudo::estado($fila, 1000));
        $this->assertSame(Embudo::CADUCADO, Embudo::estado($fila, 1001));
        $fila['estado'] = 'visto';
        $this->assertSame(Embudo::VISTO, Embudo::estado($fila, 999));
        $this->assertSame(Embudo::CADUCADO, Embudo::estado($fila, 1001));
        $this->assertFalse(Embudo::resoluble(Embudo::CADUCADO));
    }

    public function testResueltoNoCaducaNiVuelveAAbrirse(): void
    {
        foreach (['aceptado', 'rechazado'] as $e) {
            $this->assertSame($e, Embudo::estado(['estado' => $e, 'caduca' => 1, 'hashdoc' => 'h'], 99999, 'otro'));
        }
    }

    public function testDobleClicSoloUnaResolucion(): void
    {
        $this->assertTrue(Embudo::resoluble(Embudo::ENVIADO));
        $this->assertTrue(Embudo::resoluble(Embudo::VISTO));
        foreach ([Embudo::ACEPTADO, Embudo::RECHAZADO, Embudo::CADUCADO, Embudo::INVALIDADO] as $e) {
            $this->assertFalse(Embudo::resoluble($e), $e);
        }
    }

    public function testEditarElPresupuestoInvalidaElEnlace(): void
    {
        $cab = ['codigo' => 'P1', 'total' => 121.0, 'neto' => 100.0];
        $lin = [['descripcion' => 'Servicio', 'cantidad' => 1, 'pvpunitario' => 100, 'pvptotal' => 100]];
        $h = Contenido::hash($cab, $lin);
        $fila = ['estado' => 'enviado', 'caduca' => time() + 100, 'hashdoc' => $h];
        $this->assertSame(Embudo::ENVIADO, Embudo::estado($fila, time(), $h));

        $lin[0]['pvpunitario'] = 90;
        $this->assertSame(Embudo::INVALIDADO, Embudo::estado($fila, time(), Contenido::hash($cab, $lin)));
    }

    public function testHashEstableYSensibleAlContenido(): void
    {
        $cab = ['codigo' => 'P1', 'total' => '121.00'];
        $lin = [['descripcion' => ' A ', 'cantidad' => '1']];
        $this->assertSame(Contenido::hash($cab, $lin), Contenido::hash(['codigo' => 'P1', 'total' => 121], [['descripcion' => 'A', 'cantidad' => 1.0]]));
        $this->assertSame(64, strlen(Contenido::hash($cab, $lin)));
        $this->assertNotSame(Contenido::hash($cab, $lin), Contenido::hash($cab, array_merge($lin, $lin)));
        $this->assertNotSame(Contenido::hash($cab, $lin), Contenido::hash(['codigo' => 'P2', 'total' => '121.00'], $lin));
    }

    public function testLimitePorIp(): void
    {
        $marcas = [];
        for ($i = 0; $i < Limite::MAX; $i++) {
            $this->assertTrue(Limite::permitirEn($marcas, 1000));
        }
        $this->assertFalse(Limite::permitirEn($marcas, 1000));
        $this->assertTrue(Limite::permitirEn($marcas, 1000 + Limite::VENTANA + 1));
    }

    public function testModuloActivoPorDefectoEnPruebas(): void
    {
        $this->assertTrue(ModuloActivo::interpretar(null, false));
        $this->assertFalse(ModuloActivo::interpretar(null, true));
        $this->assertTrue(ModuloActivo::interpretar('1', true));
        $this->assertFalse(ModuloActivo::interpretar('0', false));
        $this->assertFalse(ModuloActivo::interpretar(false, false));
    }
}
