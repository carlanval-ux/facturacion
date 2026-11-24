<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Extension\Model;

use Closure;
use FacturaScripts\Plugins\ContabilidadAlta\Lib\Reglas;

/** MAPA-21: las secuencias de facturas de cliente nunca reutilizan huecos ni copian la fecha, y las nuevas llevan el patrón de serie. */
class SecuenciaDocumento
{
    public function saveBefore(): Closure
    {
        return function () {
            if ('FacturaCliente' !== $this->tipodoc) {
                return true;
            }
            $s = Reglas::secuenciaFactura(
                ['patron' => $this->patron, 'longnumero' => $this->longnumero, 'usarhuecos' => $this->usarhuecos, 'mantenerfecha' => $this->mantenerfecha],
                !$this->exists()
            );
            foreach ($s as $campo => $valor) {
                $this->{$campo} = $valor;
            }
            return true;
        };
    }
}
