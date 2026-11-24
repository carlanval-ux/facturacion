<?php
namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Lib\MenuItem;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Facturalo\Lib\Staff;
use PHPUnit\Framework\TestCase;

final class StaffTest extends TestCase
{
    protected function setUp(): void
    {
        Tools::settingsSet('facturalo', 'staff_nicks', 'admin, Carlos');
        Tools::settingsSave();
    }

    public function testStaffSinDistinguirMayusculas(): void
    {
        $this->assertTrue(Staff::isStaff('CARLOS'));
        $this->assertTrue(Staff::isStaff('admin'));
        $this->assertFalse(Staff::isStaff('cliente1'));
        $this->assertFalse(Staff::isStaff(null));
    }

    public function testApiDePluginsExigeClaveDeStaff(): void
    {
        $this->assertTrue(Staff::mustBlock('/api/3/plugins', []));
        $this->assertTrue(Staff::mustBlock('/api/3/plugins/Foo', ['Token' => 'no-existe']));
        $this->assertFalse(Staff::mustBlock('/api/3/clientes', []));
    }

    public function testSinSesionNoSeBloqueaPorNombre(): void
    {
        // sin cookies el controlador pedirá el login; cron y despliegue sin sesión siguen funcionando
        $this->assertFalse(Staff::mustBlock('/AdminPlugins', []));
        $this->assertFalse(Staff::mustBlock('/cron', []));
        $this->assertFalse(Staff::mustBlock('/deploy', []));
    }

    public function testMenuDeClienteNoLlevaPaginasTecnicas(): void
    {
        $admin = new MenuItem('admin', 'Administrador', '#');
        $admin->menu['AdminPlugins'] = new MenuItem('AdminPlugins', 'Plugins', 'AdminPlugins');
        $admin->menu['ListSerie'] = new MenuItem('ListSerie', 'Series', 'ListSerie');
        $filtrado = Staff::filterMenu($admin);
        $this->assertArrayNotHasKey('AdminPlugins', $filtrado->menu);
        $this->assertArrayHasKey('ListSerie', $filtrado->menu);
    }
}
