<?php
namespace FacturaScripts\Test\Plugins;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Xml;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Valida contra los XSD públicos de la AEAT (copiados en Test/xsd; solo se ha cambiado la ruta del import de xmldsig a local). */
final class XmlTest extends TestCase
{
    private function momento(string $hora): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-19 ' . $hora, new DateTimeZone('Europe/Madrid'));
    }

    private function datos(array $extra = []): array
    {
        return array_replace_recursive([
            'emisor' => ['nif' => '89890001K', 'nombre' => 'Empresa Prueba SL'],
            'sistema' => ['nombre' => 'RED TELECOM', 'nif' => 'B00000000', 'id' => 'FV', 'version' => '0.1', 'instalacion' => '1'],
            'factura' => [
                'numserie' => 'A2026-0001', 'fecha' => '2026-11-19', 'tipo' => 'F1', 'descripcion' => 'Servicios & "cuotas"',
                'desglose' => [
                    ['iva' => 21, 'base' => 100.0, 'cuota' => 21.0, 'recargo' => 0, 'cuota_recargo' => 0],
                    ['iva' => 0, 'base' => 10.0, 'cuota' => 0, 'recargo' => 0, 'cuota_recargo' => 0],
                ],
                'destinatario' => ['nombre' => 'Cliente SA', 'nif' => 'B12345674'],
            ],
            'anterior' => null,
            'momento' => $this->momento('12:00:00'),
        ], $extra);
    }

    /** Valida el RegFactuSistemaFacturacion contenido en el sobre SOAP contra SuministroLR.xsd. */
    private function validarSobre(string $soap): array
    {
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($soap));
        $solo = new DOMDocument();
        $solo->appendChild($solo->importNode($doc->getElementsByTagNameNS('*', 'RegFactuSistemaFacturacion')->item(0), true));
        libxml_use_internal_errors(true);
        $ok = $solo->schemaValidate(__DIR__ . '/../xsd/SuministroLR.xsd');
        $errores = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        return [$ok, implode("\n", $errores)];
    }

    private function sobre(array ...$registros): string
    {
        return Xml::envio(['nif' => '89890001K', 'nombre' => 'Empresa Prueba SL'], array_column($registros, 'xml'));
    }

    public function testAltaPrimerRegistroValidaXsd(): void
    {
        $r = Xml::alta($this->datos());
        $this->assertSame('21.00', $r['cuota_total']);
        $this->assertSame('131.00', $r['importe_total']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $r['huella']);
        [$ok, $err] = $this->validarSobre($this->sobre($r));
        $this->assertTrue($ok, $err);
    }

    public function testAltaEncadenadaRectificativaYAnulacionValidanXsd(): void
    {
        $a1 = Xml::alta($this->datos());
        $ant = ['nif' => '89890001K', 'numserie' => 'A2026-0001', 'fecha' => '2026-11-19', 'huella' => $a1['huella']];
        $a2 = Xml::alta($this->datos(['factura' => ['numserie' => 'R2026-0001', 'tipo' => 'R1', 'rectificada' => ['numserie' => 'A2026-0001', 'fecha' => '2026-11-19']],
            'anterior' => $ant, 'momento' => $this->momento('12:00:05')]));
        $this->assertStringContainsString('<sf:RegistroAnterior>', $a2['xml']);
        $this->assertStringContainsString('<sf:FacturasRectificadas>', $a2['xml']);
        $d = $this->datos();
        $an = Xml::anulacion(['emisor' => $d['emisor'], 'sistema' => $d['sistema'], 'factura' => ['numserie' => 'A2026-0001', 'fecha' => '2026-11-19'],
            'anterior' => ['nif' => '89890001K', 'numserie' => 'R2026-0001', 'fecha' => '2026-11-19', 'huella' => $a2['huella']], 'momento' => $this->momento('12:00:10')]);
        [$ok, $err] = $this->validarSobre($this->sobre($a1, $a2, $an));
        $this->assertTrue($ok, $err);
    }

    public function testRecargoDeEquivalenciaValidaXsd(): void
    {
        $r = Xml::alta($this->datos(['factura' => ['desglose' => [['iva' => 21, 'base' => 100.0, 'cuota' => 21.0, 'recargo' => 5.2, 'cuota_recargo' => 5.2]]]]));
        $this->assertSame('26.20', $r['cuota_total']);
        [$ok, $err] = $this->validarSobre($this->sobre($r));
        $this->assertTrue($ok, $err);
    }

    public function testLaHuellaDelRegistroCoincideConLaFormulaDeLaAeat(): void
    {
        $r = Xml::alta($this->datos());
        $cadena = 'IDEmisorFactura=89890001K&NumSerieFactura=A2026-0001&FechaExpedicionFactura=19-11-2026&TipoFactura=F1&CuotaTotal=21.00&ImporteTotal=131.00&Huella=&FechaHoraHusoGenRegistro=2026-11-19T12:00:00+01:00';
        $this->assertSame(strtoupper(hash('sha256', $cadena)), $r['huella']);
    }

    public function testRespuestaCorrectaYFault(): void
    {
        $ok = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><r:RespuestaRegFactuSistemaFacturacion xmlns:r="x" xmlns:sf="y"><r:CSV>ABC123</r:CSV><r:TiempoEsperaEnvio>60</r:TiempoEsperaEnvio><r:EstadoEnvio>ParcialmenteCorrecto</r:EstadoEnvio>'
            . '<r:RespuestaLinea><sf:IDFactura><sf:NumSerieFactura>A1</sf:NumSerieFactura></sf:IDFactura><r:EstadoRegistro>AceptadoConErrores</r:EstadoRegistro><r:CodigoErrorRegistro>2000</r:CodigoErrorRegistro><r:DescripcionErrorRegistro>x</r:DescripcionErrorRegistro></r:RespuestaLinea></r:RespuestaRegFactuSistemaFacturacion></s:Body></s:Envelope>';
        $r = Xml::respuesta($ok);
        $this->assertSame(false, $r['fault']);
        $this->assertSame('ABC123', $r['csv']);
        $this->assertSame('AceptadoConErrores', $r['lineas'][0]['estado']);
        $this->assertSame('A1', $r['lineas'][0]['numserie']);
        $f = Xml::respuesta('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Client</faultcode><faultstring>Certificado</faultstring></s:Fault></s:Body></s:Envelope>');
        $this->assertSame(true, $f['fault']);
    }

    public function testRespuestaConDoctypeSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        Xml::respuesta('<!DOCTYPE x [<!ENTITY a "b">]><x/>');
    }
}
