<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Lib;

/**
 * Parámetros contables esperados (Anexo A del mapa Facturalo↔Clarto), como mapa plano `area.clave.campo => valor`,
 * y comparación contra lo que hay. Puro: sin base de datos.
 */
final class Esperado
{
    public const IVA = ['IVA0' => [0, 0.0], 'IVA4' => [4, 0.5], 'IVA10' => [10, 1.4], 'IVA21' => [21, 5.2]];
    public const IRPF = ['IRPF2' => 2, 'IRPF7' => 7, 'IRPF15' => 15, 'IRPF19' => 19, 'IRPF21' => 21];
    public const SERIES = ['A' => ['General', null], 'R' => ['Rectificativas', 'R'], 'S' => ['Simplificadas', 'S']];
    public const AJUSTES = [
        'decimals' => 2, 'coddivisa' => 'EUR', 'tipoidfiscal' => 'NIF', 'codpago' => 'CONT',
        'codserie' => 'A', 'codserierec' => 'R', 'codimpuesto' => 'IVA21',
    ];

    /**
     * @param string[] $impuestosExistentes códigos de impuesto ya dados de alta con operación IGIC/IPSI (se desactivan)
     */
    public static function plano(int $long, int $anyo, array $impuestosExistentes = [], array $ajustesAplicables = []): array
    {
        $e = [
            'ejercicio.longsubcuenta' => $long,
            'ejercicio.fechainicio' => $anyo . '-01-01',
            'ejercicio.fechafin' => $anyo . '-12-31',
            'ejercicio.estado' => 'ABIERTO',
        ];
        $rep = Reglas::subcuenta('477', $long);
        $sop = Reglas::subcuenta('472', $long);
        foreach (self::IVA as $cod => [$iva, $rec]) {
            $cuentas = $iva > 0;
            $e["impuesto.$cod.activo"] = true;
            $e["impuesto.$cod.iva"] = $iva;
            $e["impuesto.$cod.recargo"] = $rec;
            $e["impuesto.$cod.codsubcuentarep"] = $cuentas ? $rep : null;
            $e["impuesto.$cod.codsubcuentasop"] = $cuentas ? $sop : null;
            $e["impuesto.$cod.codsubcuentarepre"] = $cuentas ? $rep : null;
        }
        foreach ($impuestosExistentes as $cod) {
            $e["impuesto.$cod.activo"] = false;
        }
        foreach (self::IRPF as $cod => $pct) {
            $e["retencion.$cod.porcentaje"] = $pct;
            $e["retencion.$cod.codsubcuentaret"] = Reglas::subcuenta('473', $long);
            $e["retencion.$cod.codsubcuentaacr"] = Reglas::subcuenta('4751', $long);
        }
        foreach (self::SERIES as $cod => [$desc, $tipo]) {
            $e["serie.$cod.tipo"] = $tipo;
            $e["secuencia.$cod.patron"] = Reglas::PATRON;
            $e["secuencia.$cod.longnumero"] = Reglas::LONGNUMERO;
            $e["secuencia.$cod.usarhuecos"] = false;
            $e["secuencia.$cod.mantenerfecha"] = false;
        }
        foreach (self::AJUSTES as $k => $v) {
            if (in_array($k, $ajustesAplicables, true)) {
                $e["ajuste.default.$k"] = $v;
            }
        }
        $e['plan.cuentas_faltantes'] = 0;
        $e['plan.subcuentas_faltantes'] = 0;
        $e['plan.cuentaesp_distintas'] = 0;
        return $e;
    }

    /**
     * Compara el plan del CSV con la base de datos.
     * @param array $filas filas del CSV [cuenta, descripcion, cuentaesp]
     * @param array $cuentas codcuenta => codcuentaesp en la base de datos
     * @param array $subcuentas codsubcuenta => codcuentaesp
     * @return array{cuentas_faltantes:int, subcuentas_faltantes:int, cuentaesp_distintas:int}
     * shortcut: las subcuentas se comparan por cantidad (el importador reconvierte los códigos si la longitud difiere de la del CSV); basta para detectar un plan a medias.
     */
    public static function planFaltas(array $filas, array $cuentas, array $subcuentas): array
    {
        $max = 0;
        foreach ($filas as $f) {
            $max = max($max, strlen((string)$f[0]));
        }
        $cf = $sf = $ed = 0;
        $nsub = 0;
        foreach ($filas as [$cod, , $esp]) {
            $cod = (string)$cod;
            if (strlen($cod) < $max) {
                if (!array_key_exists($cod, $cuentas)) {
                    $cf++;
                } elseif ((string)$cuentas[$cod] !== (string)$esp) {
                    $ed++;
                }
            } else {
                $nsub++;
            }
        }
        $sf = max(0, $nsub - count($subcuentas));
        return ['cuentas_faltantes' => $cf, 'subcuentas_faltantes' => $sf, 'cuentaesp_distintas' => $ed];
    }

    /** @return array<int,array{clave:string,esperado:mixed,actual:mixed}> diferencias (vacío = conforme). */
    public static function comparar(array $esperado, array $actual): array
    {
        $dif = [];
        foreach ($esperado as $k => $v) {
            $a = array_key_exists($k, $actual) ? $actual[$k] : null;
            if (!self::igual($v, $a)) {
                $dif[] = ['clave' => $k, 'esperado' => $v, 'actual' => $a];
            }
        }
        return $dif;
    }

    private static function igual($a, $b): bool
    {
        if (null === $a || '' === $a) {
            return null === $b || '' === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return null !== $b && (bool)$a === (bool)$b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float)$a - (float)$b) < 0.0001;
        }
        return (string)$a === (string)$b;
    }
}
