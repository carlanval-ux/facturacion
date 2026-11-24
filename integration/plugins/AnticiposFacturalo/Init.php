<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AnticiposFacturalo\Lib\ModuloActivo;
use FacturaScripts\Plugins\AnticiposFacturalo\Model\Anticipo;

final class Init extends InitClass
{
    public function init(): void
    {
        try {
            if (false === ModuloActivo::estaActivo('anticipos')) {
                return; // módulo no contratado: sin pestañas ni descuentos automáticos
            }
        } catch (\Throwable $e) {
            return; // sin base de datos aún: se reintenta en la siguiente petición
        }
        $this->loadExtension(new Extension\Lib\BusinessDocumentGenerator());
        $this->loadExtension(new Extension\Model\FacturaCliente());
        $this->loadExtension(new Extension\Controller\EditPresupuestoCliente());
        $this->loadExtension(new Extension\Controller\EditPedidoCliente());
        $this->loadExtension(new Extension\Controller\EditAlbaranCliente());
        $this->loadExtension(new Extension\Controller\EditCliente());
    }

    public function update(): void
    {
        new Anticipo(); // crea la tabla

        // serie propia de las facturas de anticipo (configurable en Anticipos > Ajustes)
        $serie = new Serie();
        if (false === $serie->load('ANT')) {
            $serie->codserie = 'ANT';
            $serie->descripcion = Tools::trans('anticipos');
            $serie->save();
        }
        if ('' === (string)Tools::settings('anticipos', 'codserie', '')) {
            Tools::settingsSet('anticipos', 'codserie', 'ANT');
            Tools::settingsSave();
        }
    }

    public function uninstall(): void
    {
        // los anticipos y sus facturas son datos fiscales: no se borra nada
    }
}
