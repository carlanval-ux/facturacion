<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\FiltroEjercicioActual\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FiltroEjercicioActual\Lib\SavedFilterDefault;

class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function (): void {
            AssetManager::addJs(Tools::config('route') . '/Plugins/FiltroEjercicioActual/Assets/JS/SavedFilterDefault.js?v=16');
        };
    }

    public function loadData(): Closure
    {
        return function (string $viewName, object $view): bool {
            SavedFilterDefault::markViewFilters($view, $this->user->nick, $viewName);
            return true;
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action): bool {
            $viewName = $this->active === 'ListReciboCliente'
                ? 'ListReciboCliente'
                : 'ListFacturaCliente';
            if ($action === 'set-default-filter') {
                $id = (string)$this->request->input('loadfilter', '');
                if (SavedFilterDefault::set($id, $this->user->nick, $viewName)) {
                    Tools::log()->notice('record-updated-correctly');
                } else {
                    Tools::log()->warning('Select a saved filter first.');
                }
                return true;
            }

            if ($action === 'clear-default-filter') {
                SavedFilterDefault::clear($this->user->nick, $viewName);
                Tools::log()->notice('record-updated-correctly');
                return true;
            }

            // The native "Todos" link is a GET with activetab. Its purpose is
            // to discard the selected saved filter, including cached values.
            if ($this->request->query->has('activetab')) {
                Cache::delete($this->listView($viewName)->filtersCacheKey());
                return true;
            }

            if (false === $this->request->isMethod('GET')) {
                return true;
            }

            if (SavedFilterDefault::applyToRequest($this->user->nick, $viewName, $this->request)) {
                return true;
            }

            return true;
        };
    }

}
