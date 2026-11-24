<?php
namespace FacturaScripts\Plugins\ContabilidadAlta;

use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;

/** Funciones de plataforma: siempre activas (no son un módulo de pago). */
class Init extends InitClass
{
    public function init(): void
    {
        foreach (['contabilidadAlta' => 'ApiContabilidadAlta', 'cambios' => 'ApiCambios', 'periodosCerrados' => 'ApiPeriodosCerrados'] as $ruta => $clase) {
            ApiRoot::addCustomResource($ruta);
            Kernel::addRoute('/api/3/' . $ruta, '\FacturaScripts\Dinamic\Controller\\' . $clase);
        }
        $this->loadExtension(new Extension\Model\FacturaCliente());
        $this->loadExtension(new Extension\Model\FacturaProveedor());
        $this->loadExtension(new Extension\Model\ReciboCliente());
        $this->loadExtension(new Extension\Model\SecuenciaDocumento());
        $this->loadExtension(new Extension\Model\Cliente());
        $this->loadExtension(new Extension\Model\Proveedor());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        new \FacturaScripts\Dinamic\Model\FacturaloCambio(); // crea la tabla si falta
    }
}
