<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\AnticiposFacturalo\Lib\Reparto;
use PHPUnit\Framework\TestCase;

/** Casos numéricos (valores comprobados a mano y con un cálculo independiente): el IVA no se duplica ni se pierde entre anticipos y factura final. */
final class RepartoTest extends TestCase
{
    private const MIXTO = [
        ['codimpuesto' => 'IVA21', 'iva' => 21.0, 'neto' => 600.0],
        ['codimpuesto' => 'IVA10', 'iva' => 10.0, 'neto' => 400.0],
    ];

    public function testUnSoloIva(): void
    {
        $tramos = [['codimpuesto' => 'IVA21', 'iva' => 21.0, 'neto' => 1000.0]];
        $a = Reparto::bases(363.00, $tramos); // 30 % de 1.210
        $this->assertSame(300.0, $a[0]['base']);
        $this->assertSame(363.0, Reparto::total($a));
        $f = Reparto::facturaFinal($tramos, [$a]);
        $this->assertSame(['neto' => 700.0, 'iva' => 147.0, 'total' => 847.0], $f);
    }

    public function testVariosTiposDeIva(): void
    {
        $a = Reparto::bases(500.0, self::MIXTO);
        $this->assertSame(257.29, $a[0]['base']);
        $this->assertSame(171.53, $a[1]['base']);
        $this->assertSame(500.0, Reparto::total($a));
    }

    public function testVariosAnticiposYFinal(): void
    {
        $a = Reparto::bases(500.0, self::MIXTO);
        $b = Reparto::bases(300.0, self::MIXTO);
        $f = Reparto::facturaFinal(self::MIXTO, [$a, $b]);
        $this->assertSame(['neto' => 313.89, 'iva' => 52.11, 'total' => 366.0], $f); // 1.166 - 500 - 300
        // el IVA total declarado (anticipos + final) es el del documento: 126 + 40 = 166
        $iva = round(0.21 * 257.29, 2) + round(0.10 * 171.53, 2) + round(0.21 * 154.37, 2) + round(0.10 * 102.92, 2) + $f['iva'];
        $this->assertEqualsWithDelta(166.0, $iva, 0.011);
    }

    public function testRedondeoDeCentimos(): void
    {
        $tramos = [['codimpuesto' => 'IVA21', 'iva' => 21.0, 'neto' => 33.33], ['codimpuesto' => 'IVA4', 'iva' => 4.0, 'neto' => 12.5]];
        $a = Reparto::bases(20.0, $tramos); // 20,00 no es alcanzable con IVA por tramo: queda 20,01
        $this->assertSame(20.01, Reparto::total($a));
        $f = Reparto::facturaFinal($tramos, [$a]);
        $this->assertSame(33.32, $f['total']); // 53,33 - 20,01
    }

    public function testAnticipoIgualAlTotalDejaLaFinalACero(): void
    {
        $a = Reparto::bases(1166.0, self::MIXTO);
        $this->assertSame(['neto' => 0.0, 'iva' => 0.0, 'total' => 0.0], Reparto::facturaFinal(self::MIXTO, [$a]));
    }

    public function testIvaCero(): void
    {
        $tramos = [['codimpuesto' => 'IVA0', 'iva' => 0.0, 'neto' => 250.0]];
        $a = Reparto::bases(100.0, $tramos);
        $this->assertSame(['neto' => 150.0, 'iva' => 0.0, 'total' => 150.0], Reparto::facturaFinal($tramos, [$a]));
    }

    public function testSinImporteOSinBaseNoReparte(): void
    {
        $this->assertSame([], Reparto::bases(0.0, self::MIXTO));
        $this->assertSame([], Reparto::bases(10.0, [['codimpuesto' => 'IVA21', 'iva' => 21.0, 'neto' => 0.0]]));
    }

    /** Barrido: para cualquier mezcla de IVA el anticipo queda a menos de un céntimo de lo cobrado y la final cuadra. */
    public function testBarridoDeInvariantes(): void
    {
        mt_srand(53);
        $ivas = [21.0, 10.0, 4.0, 0.0];
        for ($k = 0; $k < 2000; $k++) {
            $tramos = [];
            for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
                $tramos[] = ['codimpuesto' => 'X' . $i, 'iva' => $ivas[$i], 'neto' => round(mt_rand(50, 500000) / 100, 2)];
            }
            $totalDoc = Reparto::total(array_map(fn($t) => ['codimpuesto' => $t['codimpuesto'], 'iva' => $t['iva'], 'base' => $t['neto']], $tramos));
            $lista = [];
            $suma = 0.0;
            for ($j = 0; $j < 2; $j++) {
                $importe = round(mt_rand(1, 60) / 100 * ($totalDoc - $suma), 2);
                $bases = Reparto::bases($importe, $tramos);
                if (empty($bases)) {
                    continue;
                }
                $this->assertEqualsWithDelta($importe, Reparto::total($bases), 0.0101);
                $lista[] = $bases;
                $suma += Reparto::total($bases);
            }
            $f = Reparto::facturaFinal($tramos, $lista);
            $this->assertEqualsWithDelta($totalDoc - $suma, $f['total'], 0.02 * 3 * 3);
        }
    }
}
