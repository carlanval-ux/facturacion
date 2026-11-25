<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib\Calculo;
use FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib\Exportar;
use PHPUnit\Framework\TestCase;

final class CalculoTest extends TestCase
{
    private function linea(float $base, float $iva = 21, float $rec = 0, float $irpf = 0, string $exc = '', string $ret = ''): array
    {
        return ['base' => $base, 'iva' => $iva, 'recargo' => $rec, 'irpf' => $irpf, 'exc' => $exc, 'retdesc' => $ret];
    }

    private function fac(string $ref, string $fecha, array $lineas, array $extra = []): array
    {
        $neto = 0.0;
        $iva = 0.0;
        foreach ($lineas as $l) {
            $neto += $l['base'];
            $iva += round($l['base'] * $l['iva'] / 100, 2);
        }
        return $extra + [
            'ref' => $ref, 'id' => (int)substr($ref, strpos($ref, ':') + 1), 'codigo' => strtoupper(str_replace(':', '-', $ref)),
            'numero2' => '', 'fecha' => $fecha, 'codejercicio' => '2026', 'tercero' => 'Tercero ' . $ref, 'cifnif' => 'B12345674',
            'operacion' => '', 'rect' => false, 'neto' => round($neto, 2), 'totaliva' => round($iva, 2), 'totalrecargo' => 0.0,
            'totalirpf' => 0.0, 'total' => round($neto + $iva, 2), 'lineas' => $lineas,
        ];
    }

    private function paq(array $em, array $rc, string $per = 'T1', array $opts = []): array
    {
        return Calculo::paquete(2026, $per, $em, $rc, $opts + ['ejercicio' => '2026', 'generado' => '2026-04-01T00:00:00+02:00']);
    }

    public function testRangos(): void
    {
        $this->assertSame(['2026-04-01', '2026-06-30'], Calculo::rango(2026, 'T2'));
        $this->assertSame(['2026-01-01', '2026-12-31'], Calculo::rango(2026, 'anual'));
        $this->assertSame(4, Calculo::trimestreDe('2026-12-31'));
    }

    public function testRedondeoPorTipo(): void
    {
        $f = $this->fac('cli:1', '2026-02-10', [$this->linea(33.33, 21), $this->linea(66.67, 10)]);
        $p = $this->paq([$f], []);
        $t = $p['modelos']['303']['repercutido']['tipos'];
        $this->assertSame(33.33, $t['21']['base']);
        $this->assertSame(7.0, $t['21']['cuota']);
        $this->assertSame(6.67, $t['10']['cuota']);
        $this->assertSame(13.67, $p['modelos']['303']['total_cuota_repercutida']);
        $this->assertSame(['cli:1'], $t['21']['refs']);
    }

    public function testDescuentoGlobalEscalaBases(): void
    {
        // líneas suman 200, neto de cabecera 180 (10 % global): la base del 21 es 180
        $f = $this->fac('cli:2', '2026-02-10', [$this->linea(200, 21)]);
        $f['neto'] = 180.0;
        $p = $this->paq([$f], []);
        $this->assertSame(180.0, $p['modelos']['303']['repercutido']['tipos']['21']['base']);
        $this->assertSame(37.8, $p['modelos']['303']['repercutido']['tipos']['21']['cuota']);
    }

    public function testRectificativaRestaYFacturasOtroAnyoQuedanFuera(): void
    {
        $venta = $this->fac('cli:1', '2026-01-15', [$this->linea(200, 21)]);
        $rect = $this->fac('cli:2', '2026-03-31', [$this->linea(-50, 21)], ['rect' => true]);
        $dic = $this->fac('cli:3', '2025-12-31', [$this->linea(999, 21)], ['codejercicio' => '2025']);
        $ene = $this->fac('cli:4', '2027-01-01', [$this->linea(999, 21)], ['codejercicio' => '2027']);
        $t2 = $this->fac('cli:5', '2026-04-01', [$this->linea(999, 21)]);
        $p = $this->paq([$venta, $rect, $dic, $ene, $t2], []);
        $n = $p['modelos']['303']['repercutido']['tipos']['21'];
        $this->assertSame(150.0, $n['base']);
        $this->assertSame(31.5, $n['cuota']);
        $this->assertCount(2, $p['libros']['emitidas']);
        $this->assertSame([], array_filter($p['avisos'], fn($a) => 'rect_positiva' === $a['codigo']));
    }

    public function testRectificativaPositivaAvisa(): void
    {
        $rect = $this->fac('cli:2', '2026-03-31', [$this->linea(50, 21)], ['rect' => true]);
        $codigos = array_column($this->paq([$rect], [])['avisos'], 'codigo');
        $this->assertContains('rect_positiva', $codigos);
    }

    public function testRecargoDeEquivalencia(): void
    {
        $f = $this->fac('cli:1', '2026-02-10', [$this->linea(100, 21, 5.2)]);
        $m = $this->paq([$f], [])['modelos']['303'];
        $this->assertSame(5.2, $m['repercutido']['recargo_equivalencia']['5.2']['cuota']);
        $this->assertSame(21.0, $m['total_cuota_repercutida']);
        $this->assertSame(26.2, $m['total_devengado']);
    }

    public function testResultado303ConExentasYNoSujetas(): void
    {
        $v = $this->fac('cli:1', '2026-02-10', [$this->linea(100, 21), $this->linea(300, 0, 0, 0, 'ES_20'), $this->linea(70, 0, 0, 0, 'ES_68_70')]);
        $c = $this->fac('prov:1', '2026-02-11', [$this->linea(40, 21)]);
        $m = $this->paq([$v], [$c])['modelos']['303'];
        $this->assertSame(300.0, $m['repercutido']['exentas']['base']);
        $this->assertSame(70.0, $m['repercutido']['no_sujetas']['base']);
        $this->assertSame(8.4, $m['total_cuota_deducible']);
        $this->assertSame(12.6, $m['resultado']);
    }

    public function testRetenciones111y115(): void
    {
        $prof = $this->fac('prov:1', '2026-02-01', [$this->linea(1000, 21, 0, 15)], ['totalirpf' => 150.0]);
        $alq = $this->fac('prov:2', '2026-02-02', [$this->linea(500, 21, 0, 19, '', 'Retención alquileres')], ['totalirpf' => 95.0, 'cifnif' => 'A58818501']);
        $p = $this->paq([], [$prof, $alq]);
        $this->assertSame(150.0, $p['modelos']['111']['retenciones']);
        $this->assertSame(1000.0, $p['modelos']['111']['base']);
        $this->assertSame(95.0, $p['modelos']['115']['retenciones']);
        $this->assertSame(1, $p['modelos']['115']['num_perceptores']);
    }

    public function testModelo130Acumulado(): void
    {
        $i1 = $this->fac('cli:1', '2026-02-01', [$this->linea(1000, 21)], ['totalirpf' => 150.0]);
        $i2 = $this->fac('cli:2', '2026-05-01', [$this->linea(1000, 21)]);
        $i3 = $this->fac('cli:3', '2026-08-01', [$this->linea(5000, 21)]);
        $g1 = $this->fac('prov:1', '2026-03-01', [$this->linea(500, 21)]);
        $m = $this->paq([$i1, $i2, $i3], [$g1], 'T2', ['pagos_previos' => 0])['modelos']['130'];
        $this->assertSame(2000.0, $m['ingresos']['importe']);
        $this->assertSame(500.0, $m['gastos_deducibles']['importe']);
        $this->assertSame(1500.0, $m['rendimiento_neto']);
        $this->assertSame(300.0, $m['veinte_por_ciento']);
        $this->assertSame(150.0, $m['resultado']);
        $m = $this->paq([$i1, $i2, $i3], [$g1], 'T2', ['pagos_previos' => 100])['modelos']['130'];
        $this->assertSame(50.0, $m['resultado']);
    }

    public function testUmbral347(): void
    {
        $justo = $this->fac('cli:1', '2026-02-01', [$this->linea(2483.52, 21)], ['cifnif' => 'B11111111', 'total' => 3005.06]);
        $pasa = $this->fac('cli:2', '2026-05-01', [$this->linea(1000, 21)], ['cifnif' => 'B22222222', 'total' => 3010.00]);
        $rect = $this->fac('cli:3', '2026-11-01', [$this->linea(-1, 21)], ['cifnif' => 'B22222222', 'total' => -1.21, 'rect' => true]);
        $p = $this->paq([$justo, $pasa, $rect], [], 'T1')['modelos']['347'];
        $this->assertCount(1, $p['clientes']);
        $this->assertSame('B22222222', $p['clientes'][0]['cifnif']);
        $this->assertSame(3008.79, $p['clientes'][0]['total']);
        $this->assertSame([0.0, 3010.0, 0.0, -1.21], $p['clientes'][0]['trimestres']);
    }

    public function testIntracomunitarias349(): void
    {
        $e = $this->fac('cli:1', '2026-02-01', [$this->linea(800, 0, 0, 0, 'ES_25')], ['operacion' => 'intracomunitaria', 'cifnif' => 'FR12345678901']);
        $a = $this->fac('prov:1', '2026-02-02', [$this->linea(300, 0)], ['operacion' => 'intracom-servicios', 'cifnif' => 'DE123456789']);
        $p = $this->paq([$e], [$a])['modelos']['349'];
        $this->assertSame(1100.0, $p['total']);
        $this->assertSame(['E', 'I'], array_column($p['operaciones'], 'clave'));
        $m = $this->paq([$e], [$a])['modelos']['303'];
        $this->assertSame(800.0, $m['repercutido']['exentas']['base']);
    }

    public function testAvisosDeDatosFaltantes(): void
    {
        $sinNif = $this->fac('cli:1', '2026-02-01', [$this->linea(10, 21)], ['cifnif' => '']);
        $sinEj = $this->fac('cli:2', '2026-02-01', [$this->linea(10, 21)], ['codejercicio' => '']);
        $otroEj = $this->fac('cli:3', '2026-02-01', [$this->linea(10, 21)], ['codejercicio' => '2025']);
        $conRet = $this->fac('prov:1', '2026-01-01', [$this->linea(100, 21, 0, 15)], ['totalirpf' => 15.0]);
        $sinRet = $this->fac('prov:2', '2026-02-01', [$this->linea(100, 21)]);
        $codigos = array_column($this->paq([$sinNif, $sinEj, $otroEj], [$conRet, $sinRet])['avisos'], 'codigo');
        foreach (['nif_vacio', 'sin_ejercicio', 'otro_ejercicio', 'retencion_no_informada'] as $c) {
            $this->assertContains($c, $codigos);
        }
    }

    public function testDrillDownYLibroOrdenado(): void
    {
        $a = $this->fac('cli:2', '2026-03-01', [$this->linea(10, 21)]);
        $b = $this->fac('cli:1', '2026-01-01', [$this->linea(10, 21)]);
        $p = $this->paq([$a, $b], []);
        $this->assertSame(['cli:2', 'cli:1'], Calculo::refsDe($p, '303.repercutido.tipos.21'));
        $this->assertNull(Calculo::refsDe($p, '303.nada'));
        $this->assertSame('cli:1', $p['libros']['emitidas'][0]['ref']);
    }

    public function testCsvUtf8PuntoYComaYSinInyeccion(): void
    {
        $f = $this->fac('cli:1', '2026-02-01', [$this->linea(10.5, 21)], ['tercero' => '=CMD;"x"']);
        $p = $this->paq([$f], [], 'T1', ['emisor' => ['nombre' => 'Empresa', 'cifnif' => 'B1']]);
        $csv = Exportar::libroCsv($p['libros']['emitidas'], $p, 'Libro');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"\'=CMD;""x"""', $csv);
        $this->assertStringContainsString(';10,50;21,00;2,21;', $csv);
        $this->assertStringContainsString('no constituyen una presentación', $csv);
        $json = json_decode(Exportar::json($p), true);
        $this->assertSame('facturalo.paquete-trimestral', $json['schema']);
        $this->assertSame(1, $json['version']);
    }
}
