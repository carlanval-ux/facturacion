<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\TelecoBridge\Controller\ApiTelecoFacturaCliente;
use PHPUnit\Framework\TestCase;

final class IdempotencyScopeTest extends TestCase
{
    public function testKeyIsUnchangedWithASingleCompany(): void
    {
        $this->assertSame('abc-12345678', ApiTelecoFacturaCliente::scopedKey('abc-12345678', null));
    }

    public function testSameKeyDiffersPerCompanyAndFitsTheColumn(): void
    {
        $a = ApiTelecoFacturaCliente::scopedKey('abc-12345678', 1);
        $b = ApiTelecoFacturaCliente::scopedKey('abc-12345678', 2);
        $this->assertNotSame($a, $b);
        $this->assertSame($a, ApiTelecoFacturaCliente::scopedKey('abc-12345678', 1));
        $this->assertLessThanOrEqual(80, strlen($a));
    }
}
