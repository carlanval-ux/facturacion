<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\FacturasRecurrentes\Lib\Generador;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\Recurrente;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\RecurrenteHistorial;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\RecurrenteLinea;
use PHPUnit\Framework\TestCase;

/** Necesita base de datos (infraestructura de tests de plugins). */
final class GeneradorTest extends TestCase
{
    public function testIdempotenteYAvanzaLaFecha(): void
    {
        $c = new Cliente();
        $c->nombre = $c->razonsocial = 'Cliente recurrente test';
        $this->assertTrue($c->save());

        $r = new Recurrente();
        $r->nombre = 'test';
        $r->codcliente = $c->codcliente;
        $r->unidad = 'meses';
        $r->fecha_inicio = date('d-m-Y', strtotime('-1 day'));
        $this->assertTrue($r->save());

        $l = new RecurrenteLinea();
        $l->idrecurrente = $r->idrecurrente;
        $l->descripcion = 'Cuota';
        $l->pvpunitario = 100;
        $this->assertTrue($l->save());

        $hoy = date('Y-m-d');
        $this->assertSame(1, Generador::procesar($hoy));
        $this->assertSame(0, Generador::procesar($hoy), 'segunda pasada: no duplica');
        $this->assertSame(1, FacturaCliente::count([Where::eq('codcliente', $c->codcliente)]));
        $this->assertSame(1, RecurrenteHistorial::count([Where::eq('idrecurrente', $r->idrecurrente), Where::eq('estado', 'ok')]));

        $r->loadFromCode($r->idrecurrente);
        $this->assertGreaterThan($hoy, date('Y-m-d', strtotime($r->proxima)));

        foreach (FacturaCliente::all([Where::eq('codcliente', $c->codcliente)]) as $f) {
            $f->delete();
        }
        RecurrenteLinea::deleteWhere([Where::eq('idrecurrente', $r->idrecurrente)]);
        RecurrenteHistorial::deleteWhere([Where::eq('idrecurrente', $r->idrecurrente)]);
        $r->delete();
        $c->delete();
    }
}
