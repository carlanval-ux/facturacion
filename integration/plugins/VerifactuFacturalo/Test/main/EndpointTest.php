<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\VerifactuFacturalo\Lib\Endpoint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Requisito del OWNER: el check «Modo pruebas» es lo ÚNICO que decide el endpoint. */
final class EndpointTest extends TestCase
{
    public function testConCheckVaAPreproduccion(): void
    {
        $this->assertSame('https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', Endpoint::soap(true));
        $this->assertSame('https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', Endpoint::soap(true, true));
        $this->assertSame('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR', Endpoint::qr(true));
    }

    public function testSinCheckVaAProduccion(): void
    {
        $this->assertSame('https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', Endpoint::soap(false));
        $this->assertSame('https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', Endpoint::soap(false, true));
        $this->assertSame('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR', Endpoint::qr(false));
    }

    public function testElCambioEsSoloElBooleano(): void
    {
        $this->assertSame(parse_url(Endpoint::soap(true), PHP_URL_PATH), parse_url(Endpoint::soap(false), PHP_URL_PATH));
        $this->assertStringStartsWith('prewww', Endpoint::host(true));
        $this->assertStringStartsWith('www', Endpoint::host(false));
    }

    public function testValidarRechazaDestinoQueNoCorrespondeAlCheck(): void
    {
        Endpoint::validar(Endpoint::soap(true), true);
        Endpoint::validar(Endpoint::soap(false, true), false);
        $malos = [[Endpoint::soap(false), true], [Endpoint::soap(true), false], ['http://prewww1.aeat.es/x', true],
            ['https://evil.example/x', true], ['https://prewww1.aeat.es.evil.test/x', true]];
        foreach ($malos as [$url, $pruebas]) {
            try {
                Endpoint::validar($url, $pruebas);
                $this->fail('debería rechazar ' . $url);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no permitido', $e->getMessage());
            }
        }
    }
}
