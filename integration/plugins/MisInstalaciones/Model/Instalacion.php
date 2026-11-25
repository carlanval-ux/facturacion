<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\MisInstalaciones\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MisInstalaciones\Lib\ClienteApiInstalacion;

class Instalacion extends ModelClass
{
    use ModelTrait;

    public bool $activa = true;

    public ?string $apikey = null;

    public ?string $id = null;

    public ?string $nombre = null;

    public ?string $observaciones = null;

    public ?string $url = null;

    public function getApiFieldsToHide(): array
    {
        return array_unique(array_merge(['apikey'], parent::getApiFieldsToHide()));
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'instalaciones';
    }

    public function test(): bool
    {
        $this->url = rtrim(trim((string)$this->url), '/');
        if (false === filter_var($this->url, FILTER_VALIDATE_URL)) {
            Tools::log()->warning('instalacion-url-invalida');
            return false;
        }

        if (false === ClienteApiInstalacion::urlPermitida($this->url)) {
            Tools::log()->warning('instalacion-url-https-obligatoria');
            return false;
        }

        if (empty($this->apikey)) {
            Tools::log()->warning('instalacion-apikey-obligatoria');
            return false;
        }

        return parent::test();
    }
}
