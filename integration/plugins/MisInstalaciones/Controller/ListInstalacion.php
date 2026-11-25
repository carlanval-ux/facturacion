<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Controller;

use FacturaScripts\Dinamic\Lib\ExtendedController\ListController;

class ListInstalacion extends ListController
{
    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['title'] = 'instalaciones';
        $pageData['menu'] = 'admin';
        $pageData['icon'] = 'fa-solid fa-server';
        return $pageData;
    }

    protected function createViews(): void
    {
        $this->addView('ListInstalacion', 'Instalacion', 'instalaciones', 'fa-solid fa-server');
    }
}
