<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\ModuloActivo;

/** Panel del embudo de aceptación: filtro por estado, con el recuento de cada uno en la etiqueta. */
class ListPresupuestoCliente
{
    public function createViews(): Closure
    {
        return function () {
            if (false === ModuloActivo::estaActivo('aceptacion')) {
                return;
            }
            $ahora = time();
            $sub = fn(string $cond) => 'SELECT idpresupuesto FROM presupuestos_aceptacion WHERE ' . $cond;
            $embudo = [
                'enviado' => "estado = 'enviado' AND caduca >= " . $ahora,
                'visto' => "estado = 'visto' AND caduca >= " . $ahora,
                'aceptado' => "estado = 'aceptado'",
                'rechazado' => "estado = 'rechazado'",
                'caducado' => "estado IN ('enviado','visto') AND caduca < " . $ahora,
            ];
            $db = new DataBase();
            $valores = [['label' => Tools::trans('aceptacion-embudo'), 'where' => []]];
            foreach ($embudo as $clave => $cond) {
                // solo el último enlace de cada presupuesto cuenta
                $cond .= ' AND id IN (SELECT MAX(id) FROM presupuestos_aceptacion GROUP BY idpresupuesto)';
                $n = $db->select('SELECT COUNT(*) AS n FROM presupuestos_aceptacion WHERE ' . $cond);
                $valores[] = [
                    'label' => Tools::trans('aceptacion-estado-' . $clave) . ' (' . (int)($n[0]['n'] ?? 0) . ')',
                    'where' => [Where::in('idpresupuesto', $sub($cond))],
                ];
            }
            $this->addFilterSelectWhere('ListPresupuestoCliente', 'aceptacion', $valores);
        };
    }
}
