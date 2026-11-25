<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib;

/** Lógica pura de reparto de un total entre recibos pendientes (en orden), con control de no pasarse. */
final class Reparto
{
    /**
     * @param float $total importe total a cobrar
     * @param array<int,float> $pendientes idrecibo => pendiente, ya ordenados por vencimiento
     * @return array<int,float>|null idrecibo => importe a cobrar (solo > 0); null si el total no es válido o excede lo pendiente
     */
    public static function distribuir(float $total, array $pendientes, int $dec = 2): ?array
    {
        $total = round($total, $dec);
        if ($total <= 0 || $total > round(array_sum($pendientes), $dec)) {
            return null;
        }
        $res = [];
        foreach ($pendientes as $id => $pend) {
            if ($total <= 0) {
                break;
            }
            $parte = round(min($pend, $total), $dec);
            if ($parte > 0) {
                $res[$id] = $parte;
            }
            $total = round($total - $parte, $dec);
        }
        return $res;
    }
}
