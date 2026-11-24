<?php
namespace FacturaScripts\Test\Plugins;

use DateTimeImmutable;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\DiasHabiles;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\Iban;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\SepaTexto;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Lib\SepaXml;
use PHPUnit\Framework\TestCase;

final class RemesasSepaTest extends TestCase
{
    private const ACREEDOR = ['nombre' => 'Red Telecom Conecta, S.L.', 'aci' => 'ES98ZZZ12345678A', 'iban' => 'ES9121000418450200051332', 'bic' => ''];

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-24 10:00:00'); // martes
    }

    private function op(string $id, float $importe, string $sec, string $cobro, array $extra = []): array
    {
        return array_merge([
            'endtoend' => $id, 'importe' => $importe, 'mandato' => 'M-' . $id, 'fechafirma' => '2026-01-15',
            'tipo' => 'CORE', 'secuencia' => $sec, 'fechacobro' => $cobro, 'deudor' => 'Muñoz Peña, José & Hijos',
            'iban' => 'ES7921000813610123456789', 'bic' => '', 'concepto' => 'Factura A-2026/12 vto 1',
        ], $extra);
    }

    public function testIbanValidos(): void
    {
        foreach (['ES9121000418450200051332', 'ES91 2100 0418 4502 0005 1332', 'es7921000813610123456789', 'DE89370400440532013000', 'GB82WEST12345698765432', 'FR1420041010050500013M02606'] as $i) {
            $this->assertTrue(Iban::esValido($i), $i);
        }
    }

    public function testIbanInvalidos(): void
    {
        foreach (['', 'ES9121000418450200051333', 'ES91210004184502000513', 'XX9121000418450200051332', 'ES91-2100-0418-4502-0005-133A', '1234'] as $i) {
            $this->assertFalse(Iban::esValido($i), $i);
        }
    }

    public function testIbanEnmascarado(): void
    {
        $this->assertSame('ES91 **** **** **** **** 1332', Iban::formato('ES9121000418450200051332'));
        $this->assertSame('ES91 2100 0418 4502 0005 1332', Iban::formato('ES9121000418450200051332', false));
        $this->assertTrue(Iban::bicValido('CAIXESBBXXX'));
        $this->assertFalse(Iban::bicValido('CAIX'));
    }

    public function testDiasHabiles(): void
    {
        $this->assertTrue(DiasHabiles::esFestivo(new DateTimeImmutable('2026-04-03'))); // Viernes Santo
        $this->assertTrue(DiasHabiles::esFestivo(new DateTimeImmutable('2026-04-06'))); // Lunes de Pascua
        $this->assertTrue(DiasHabiles::esFestivo(new DateTimeImmutable('2027-03-26')));
        $this->assertFalse(DiasHabiles::esHabil(new DateTimeImmutable('2026-11-28'))); // sábado
        $this->assertSame('2026-12-01', DiasHabiles::sumar($this->ahora(), 5)->format('Y-m-d'));
        $this->assertSame('2026-12-28', DiasHabiles::siguienteHabil(new DateTimeImmutable('2026-12-25'))->format('Y-m-d'));
    }

    public function testTextoSepa(): void
    {
        $this->assertSame('Munoz Pena, Jose y Hijos', SepaTexto::limpio('Muñoz Peña, José & Hijos', 70));
        $this->assertSame(10, strlen(SepaTexto::limpio(str_repeat('a', 50), 10)));
        $this->assertSame('RC12', SepaTexto::id('/RC 12/'));
    }

    public function testAci(): void
    {
        $this->assertTrue(SepaXml::aciValido('ES98ZZZ12345678A'));
        $this->assertFalse(SepaXml::aciValido('ES98'));
        $this->assertFalse(SepaXml::aciValido('1234'));
    }

    public function testGeneraXmlValidoYAgrupado(): void
    {
        $ops = [
            $this->op('RC1', 100.10, 'FRST', '2026-12-01'),
            $this->op('RC2', 200.20, 'RCUR', '2026-11-25'),
            $this->op('RC3', 50.20, 'RCUR', '2026-11-25'),
        ];
        $xml = SepaXml::generar(self::ACREEDOR, $ops, 'REM2611241000', $this->ahora(), ['frst' => 5, 'rcur' => 1, 'b2b' => 1]);

        $this->assertSame([], SepaXml::validarXml($xml));
        $this->assertStringContainsString(SepaXml::NS, $xml);
        $xml = str_replace(" xmlns=\"" . SepaXml::NS . "\"", "", $xml);
        $this->assertSame(2, preg_match_all("/<PmtInf[ >]/", $xml));
        $this->assertSame(3, substr_count($xml, '<DrctDbtTxInf>'));
        $this->assertStringContainsString('<CtrlSum>350.50</CtrlSum>', $xml);
        $this->assertStringContainsString('<InstdAmt Ccy="EUR">100.10</InstdAmt>', $xml);
        $this->assertStringContainsString('<SeqTp>FRST</SeqTp>', $xml);
        $this->assertStringContainsString('<Nm>Munoz Pena, Jose y Hijos</Nm>', $xml);
        $this->assertStringContainsString('<Id>NOTPROVIDED</Id>', $xml);
    }

    public function testPlazosMinimos(): void
    {
        $ahora = $this->ahora()->setTime(0, 0);
        $plazos = ['frst' => 5, 'rcur' => 1, 'b2b' => 1];
        // primer adeudo con solo 1 día hábil: rechazado
        $e = SepaXml::validar(self::ACREEDOR, [$this->op('RC1', 10, 'FRST', '2026-11-25')], $ahora, $plazos);
        $this->assertNotEmpty($e);
        $this->assertStringContainsString('collection-date-too-early', implode('|', $e));
        // el mismo cobro como recurrente o B2B sí vale
        $this->assertSame([], SepaXml::validar(self::ACREEDOR, [$this->op('RC1', 10, 'RCUR', '2026-11-25')], $ahora, $plazos));
        $this->assertSame([], SepaXml::validar(self::ACREEDOR, [$this->op('RC1', 10, 'FRST', '2026-11-25', ['tipo' => 'B2B'])], $ahora, ['frst' => 5, 'rcur' => 1, 'b2b' => 1]));
        // con el plazo configurado a 1 día el primer adeudo también vale
        $this->assertSame([], SepaXml::validar(self::ACREEDOR, [$this->op('RC1', 10, 'FRST', '2026-11-25')], $ahora, ['frst' => 1]));
    }

    public function testRechazaDatosInvalidos(): void
    {
        $ahora = $this->ahora()->setTime(0, 0);
        $casos = [
            'importe 0' => [$this->op('A1', 0, 'RCUR', '2026-11-25'), 'invalid-amount'],
            'sábado' => [$this->op('A2', 5, 'RCUR', '2026-11-28'), 'not-business-day'],
            'iban malo' => [$this->op('A3', 5, 'RCUR', '2026-11-25', ['iban' => 'ES0000000000000000000000']), 'debtor-iban-invalid'],
            'sin mandato' => [$this->op('A4', 5, 'RCUR', '2026-11-25', ['mandato' => '']), 'mandate-reference-missing'],
            'secuencia' => [$this->op('A5', 5, 'XXXX', '2026-11-25'), 'invalid-scheme-or-sequence'],
        ];
        foreach ($casos as $nombre => [$op, $clave]) {
            $this->assertStringContainsString($clave, implode('|', SepaXml::validar(self::ACREEDOR, [$op], $ahora)), $nombre);
        }
        $this->assertStringContainsString('creditor-id-invalid', implode('|', SepaXml::validar(['aci' => 'x'] + self::ACREEDOR, [$this->op('B', 5, 'RCUR', '2026-11-25')], $ahora)));
        $this->assertStringContainsString('duplicated', implode('|', SepaXml::validar(self::ACREEDOR, [$this->op('D', 5, 'RCUR', '2026-11-25'), $this->op('D', 6, 'RCUR', '2026-11-25')], $ahora)));
        $this->expectException(\InvalidArgumentException::class);
        SepaXml::generar(self::ACREEDOR, [$this->op('E', 0, 'RCUR', '2026-11-25')], 'M1', $this->ahora());
    }

    public function testValidarXmlDetectaTotalesIncorrectos(): void
    {
        $xml = SepaXml::generar(self::ACREEDOR, [$this->op('RC1', 10, 'RCUR', '2026-11-25')], 'M2', $this->ahora());
        $roto = str_replace('<CtrlSum>10.00</CtrlSum>', '<CtrlSum>11.00</CtrlSum>', $xml);
        $this->assertContains('ctrlsum-mismatch', SepaXml::validarXml($roto));
        $this->assertSame(['xml-not-well-formed'], SepaXml::validarXml('<a>'));
    }
}
