<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Aceptacion;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Contenido;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Token;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Model\PresupuestoAceptacion;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

/** Flujo con base de datos: evidencia, un solo uso y anulación por edición (no envía correos). */
final class AceptacionFlujoTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;
    use RandomDataTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
    }

    private function presupuesto(): array
    {
        $cliente = $this->getRandomCustomer();
        $this->assertTrue($cliente->save());
        $doc = new PresupuestoCliente();
        $this->assertTrue($doc->setSubject($cliente));
        $this->assertTrue($doc->save());
        $linea = $doc->getNewLine();
        $linea->cantidad = 1;
        $linea->pvpunitario = 100;
        $this->assertTrue($linea->save());
        $lineas = $doc->getLines();
        $this->assertTrue(Calculator::calculate($doc, $lineas, true));
        return [$doc, $cliente];
    }

    private function enlace(PresupuestoCliente $doc, int $caduca = 0): array
    {
        $token = Token::generar();
        $row = new PresupuestoAceptacion();
        $row->idpresupuesto = $doc->id();
        $row->codigo = $doc->codigo;
        $row->tokenhash = Token::hash($token);
        $row->hashdoc = Contenido::deDocumento($doc);
        $row->creado = time();
        $row->caduca = $caduca ?: time() + 3600;
        $this->assertTrue($row->save());
        return [$token, $row];
    }

    private function limpiar(PresupuestoCliente $doc, $cliente, PresupuestoAceptacion $row): void
    {
        $row->delete();
        $doc->delete();
        $cliente->getDefaultAddress()->delete();
        $cliente->delete();
    }

    public function testAceptarGuardaEvidenciaYSoloUnaVez(): void
    {
        [$doc, $cliente] = $this->presupuesto();
        [$token, $row] = $this->enlace($doc);

        $r1 = Aceptacion::resolver($token, 'aceptar', 'Ana Pérez', true, '', '203.0.113.7', 'UA-test');
        $this->assertTrue($r1['ok']);
        // doble clic / reenvío del formulario
        $r2 = Aceptacion::resolver($token, 'aceptar', 'Ana Pérez', true, '', '203.0.113.7', 'UA-test');
        $this->assertFalse($r2['ok']);
        $this->assertSame('usado', $r2['error']);
        $r3 = Aceptacion::resolver($token, 'rechazar', '', false, 'x', '203.0.113.8', 'UA');
        $this->assertSame('usado', $r3['error']);

        $row->load($row->id);
        $this->assertSame('aceptado', $row->estado);
        $this->assertSame('Ana Pérez', $row->nombre);
        $this->assertSame('203.0.113.7', $row->ip);
        $this->assertSame('UA-test', $row->useragent);
        $this->assertSame(Contenido::deDocumento($doc), $row->hashresuelto);
        $this->assertNotEmpty($row->resuelto);

        $doc->load($doc->id());
        $this->assertEquals(Aceptacion::estadoAceptado()->idestado, $doc->idestado);

        $this->limpiar($doc, $cliente, $row);
    }

    public function testAceptarExigeNombreYConformidad(): void
    {
        [$doc, $cliente] = $this->presupuesto();
        [$token, $row] = $this->enlace($doc);
        $this->assertSame('datos', Aceptacion::resolver($token, 'aceptar', 'A', true, '', 'ip', 'ua')['error']);
        $this->assertSame('datos', Aceptacion::resolver($token, 'aceptar', 'Ana Pérez', false, '', 'ip', 'ua')['error']);
        $row->load($row->id);
        $this->assertSame('enviado', $row->estado);
        $this->limpiar($doc, $cliente, $row);
    }

    public function testEnlaceCaducadoYTokenInventado(): void
    {
        [$doc, $cliente] = $this->presupuesto();
        [$token, $row] = $this->enlace($doc, time() - 10);
        $this->assertSame('caducado', Aceptacion::resolver($token, 'aceptar', 'Ana Pérez', true, '', 'ip', 'ua')['error']);
        $this->assertSame('invalido', Aceptacion::resolver(Token::generar(), 'aceptar', 'Ana Pérez', true, '', 'ip', 'ua')['error']);
        $this->assertNull(Aceptacion::porToken('no-valido'));
        $this->limpiar($doc, $cliente, $row);
    }

    public function testEditarElPresupuestoAnulaElEnlace(): void
    {
        [$doc, $cliente] = $this->presupuesto();
        [$token, $row] = $this->enlace($doc);

        $linea = $doc->getLines()[0];
        $linea->pvpunitario = 80;
        $this->assertTrue($linea->save());
        $lineas = $doc->getLines();
        $this->assertTrue(Calculator::calculate($doc, $lineas, true));

        $this->assertSame('invalidado', Aceptacion::resolver($token, 'aceptar', 'Ana Pérez', true, '', 'ip', 'ua')['error']);
        $row->load($row->id);
        $this->assertSame('invalidado', $row->estado);
        $this->limpiar($doc, $cliente, $row);
    }
}
