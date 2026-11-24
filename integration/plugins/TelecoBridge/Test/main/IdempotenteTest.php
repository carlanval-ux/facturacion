<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\TelecoBridge\Lib\Idempotente;
use PHPUnit\Framework\TestCase;

final class IdempotenteTest extends TestCase
{
    private function usa(): object
    {
        return new class {
            use Idempotente;
        };
    }

    public function testLaClaveSeLigaAlEndpointYCabeEnLaColumna(): void
    {
        $o = $this->usa();
        $a = $o::claveIdempotente('pagar', 'abc-12345678');
        $this->assertNotSame($a, $o::claveIdempotente('rectificativa', 'abc-12345678'));
        $this->assertSame($a, $o::claveIdempotente('pagar', 'abc-12345678'));
        $this->assertLessThanOrEqual(80, strlen($a));
    }

    public function testElHashNoDependeDelOrdenPeroSiDelCuerpoYDelId(): void
    {
        $o = $this->usa();
        $this->assertSame($o::hashCuerpo(['a' => '1', 'b' => '2'], ['id' => '7']), $o::hashCuerpo(['b' => '2', 'a' => '1'], ['id' => '7']));
        $this->assertNotSame($o::hashCuerpo(['a' => '1'], ['id' => '7']), $o::hashCuerpo(['a' => '2'], ['id' => '7']));
        $this->assertNotSame($o::hashCuerpo(['a' => '1'], ['id' => '7']), $o::hashCuerpo(['a' => '1'], ['id' => '8']));
    }
}
