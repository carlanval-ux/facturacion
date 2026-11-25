<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\FiltroEjercicioActual;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Plugins\FiltroEjercicioActual\Lib\SavedFilterDefault;

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\ListFacturaCliente());
        $this->loadExtension(new Extension\Controller\ListFacturaProveedor());
        $this->loadExtension(new Extension\Controller\ListAlbaranCliente());
        $this->loadExtension(new Extension\Controller\ListAlbaranProveedor());
        $this->loadExtension(new Extension\Controller\ListPedidoCliente());
        $this->loadExtension(new Extension\Controller\ListPedidoProveedor());
        $this->loadExtension(new Extension\Controller\ListPresupuestoCliente());
        $this->loadExtension(new Extension\Controller\ListPresupuestoProveedor());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        SavedFilterDefault::createTable();
        SavedFilterDefault::migrateLegacyMarkers();
    }
}
