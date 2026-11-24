<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Reglas;

/** MAPA-22: los códigos nuevos son alfanuméricos de hasta 10 (los existentes no se tocan). */
class Proveedor
{
    public function saveBefore(): Closure
    {
        return function () {
            $cod = trim((string)$this->codproveedor);
            if ('' !== $cod && !$this->exists() && !Reglas::codigoValido($cod)) {
                Tools::log()->error('codigo-alfanumerico', ['%value%' => $cod]);
                return false;
            }
            return true;
        };
    }
}
