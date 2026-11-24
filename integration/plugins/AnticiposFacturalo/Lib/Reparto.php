<?php
namespace FacturaScripts\Plugins\AnticiposFacturalo\Lib;

/** Cálculo puro (sin base de datos) del reparto de un anticipo por tipos de IVA y de la factura final. Redondeo a 2 decimales como el Calculator. */
final class Reparto
{
    /**
     * Reparte un importe cobrado (IVA incluido) entre los tipos de IVA del documento, en proporción a su peso bruto.
     * @param array $tramos [['codimpuesto'=>, 'iva'=>float, 'neto'=>float], ...]
     * @return array [['codimpuesto', 'iva', 'base'], ...] sin tramos a cero; total(...) coincide con $bruto salvo céntimos inalcanzables
     */
    public static function bases(float $bruto, array $tramos): array
    {
        $pesos = [];
        $suma = 0.0;
        foreach ($tramos as $i => $t) {
            $pesos[$i] = max(0.0, (float)$t['neto']) * (1 + (float)$t['iva'] / 100);
            $suma += $pesos[$i];
        }
        if ($bruto <= 0 || $suma <= 0) {
            return [];
        }

        $out = [];
        $mayor = null;
        foreach ($tramos as $i => $t) {
            $f = 1 + (float)$t['iva'] / 100;
            $out[$i] = ['codimpuesto' => $t['codimpuesto'], 'iva' => (float)$t['iva'], 'base' => round($bruto * $pesos[$i] / $suma / $f, 2)];
            if ($mayor === null || $pesos[$i] > $pesos[$mayor]) {
                $mayor = $i;
            }
        }

        // ajuste de céntimos en el tramo mayor; cada paso debe acercar el total al importe
        for ($n = 0; $n < 100; $n++) {
            $dif = round($bruto - self::total($out), 2);
            if (abs($dif) < 0.005) {
                break;
            }
            $prueba = $out;
            $prueba[$mayor]['base'] = round($out[$mayor]['base'] + ($dif > 0 ? 0.01 : -0.01), 2);
            if ($prueba[$mayor]['base'] < 0 || abs(round($bruto - self::total($prueba), 2)) >= abs($dif)) {
                break;
            }
            $out = $prueba;
        }

        return array_values(array_filter($out, fn($b) => $b['base'] > 0));
    }

    /** Total con IVA de unas bases ya repartidas (suma base + IVA redondeado por tramo). */
    public static function total(array $bases): float
    {
        return round(self::sumar($bases)['total'], 2);
    }

    /**
     * Factura final: bases del documento por tramo menos las bases de cada anticipo.
     * @param array $tramosDoc [['codimpuesto','iva','neto'], ...] (neto tras descuentos)
     * @param array[] $anticipos lista de listas de bases (salida de bases())
     * @return array ['neto', 'iva', 'total']
     */
    public static function facturaFinal(array $tramosDoc, array $anticipos): array
    {
        $bases = [];
        foreach ($tramosDoc as $t) {
            $bases[] = ['codimpuesto' => $t['codimpuesto'], 'iva' => (float)$t['iva'], 'base' => round((float)$t['neto'], 2)];
        }
        foreach ($anticipos as $lista) {
            foreach ($lista as $b) {
                $bases[] = ['codimpuesto' => $b['codimpuesto'], 'iva' => (float)$b['iva'], 'base' => -$b['base']];
            }
        }
        $r = self::sumar($bases);
        return ['neto' => round($r['neto'], 2), 'iva' => round($r['iva'], 2), 'total' => round($r['total'], 2)];
    }

    /** Agrupa por tipo de IVA (como el Calculator: el IVA se redondea sobre la base agregada del tramo). */
    private static function sumar(array $bases): array
    {
        $por = [];
        foreach ($bases as $b) {
            $k = (string)(float)$b['iva'];
            $por[$k] = ($por[$k] ?? 0.0) + $b['base'];
        }
        $neto = $iva = 0.0;
        foreach ($por as $k => $base) {
            $base = round($base, 2);
            $neto += $base;
            $iva += round($base * (float)$k / 100, 2);
        }
        return ['neto' => $neto, 'iva' => $iva, 'total' => $neto + $iva];
    }
}
