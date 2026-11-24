<?php
namespace FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib;

/**
 * Cálculo puro (sin base de datos) de las cifras del paquete trimestral.
 * Entrada: facturas del AÑO completo como arrays (ver Datos::facturas). Importes ya con signo:
 * una rectificativa trae importes negativos y resta. Salida: estructura JSON estable (esquema en README).
 */
final class Calculo
{
    const SCHEMA = 'facturalo.paquete-trimestral';
    const VERSION = 1;
    const UMBRAL_347 = 3005.06;
    const AVISO_LEGAL = 'Cifras orientativas calculadas con los datos de Facturalo; no constituyen una presentación ante la Agencia Tributaria.';

    const NO_SUJETA = ['ES_7', 'ES_14', 'ES_68_70', 'ES_OTHER_NOT_SUBJECT'];
    const ISP = ['ES_84'];
    const INTRA = ['intracomunitaria', 'intracom-servicios'];
    /** Operaciones que no entran en el 347 (van al 349 o son exterior). */
    const FUERA_347 = ['intracomunitaria', 'intracom-servicios', 'exportacion', 'importacion'];

    /** @return array{0:string,1:string} desde, hasta (Y-m-d) del periodo T1..T4 | anual */
    public static function rango(int $anyo, string $periodo): array
    {
        $trim = ['T1' => ['01-01', '03-31'], 'T2' => ['04-01', '06-30'], 'T3' => ['07-01', '09-30'], 'T4' => ['10-01', '12-31']];
        if (isset($trim[$periodo])) {
            return [$anyo . '-' . $trim[$periodo][0], $anyo . '-' . $trim[$periodo][1]];
        }
        return [$anyo . '-01-01', $anyo . '-12-31'];
    }

    public static function trimestreDe(string $fecha): int
    {
        return (int)ceil(((int)substr($fecha, 5, 2)) / 3);
    }

    /** Fecha Y-m-d normalizada desde d-m-Y o Y-m-d. */
    public static function fechaIso(string $fecha): string
    {
        $t = strtotime($fecha);
        return false === $t ? '' : date('Y-m-d', $t);
    }

    /**
     * Desglose de una factura por (iva, recargo, irpf, excepción). Las bases se escalan al neto de cabecera
     * (descuentos globales) y se redondean a 2 decimales por grupo, como hace el motor de facturas.
     */
    public static function desglose(array $f): array
    {
        $suma = 0.0;
        foreach ($f['lineas'] as $l) {
            $suma += (float)$l['base'];
        }
        $neto = (float)$f['neto'];
        $lineas = $f['lineas'];
        if (empty($lineas) || abs($suma) < 0.000001) {
            // sin líneas utilizables: una sola línea con el tipo medio deducido de la cabecera
            $iva = abs($neto) > 0.000001 ? round((float)$f['totaliva'] / $neto * 100, 2) : 0.0;
            $lineas = [['base' => $neto, 'iva' => $iva, 'recargo' => 0, 'irpf' => 0, 'exc' => '', 'retdesc' => '']];
            $suma = $neto;
        }
        $k = abs($suma) > 0.000001 ? $neto / $suma : 1.0;
        $grupos = [];
        foreach ($lineas as $l) {
            $key = (float)$l['iva'] . '|' . (float)$l['recargo'] . '|' . (float)$l['irpf'] . '|' . ($l['exc'] ?? '') . '|' . ($l['retdesc'] ?? '');
            if (!isset($grupos[$key])) {
                $grupos[$key] = ['base' => 0.0, 'iva' => (float)$l['iva'], 'recargo' => (float)$l['recargo'],
                    'irpf' => (float)$l['irpf'], 'exc' => (string)($l['exc'] ?? ''), 'retdesc' => (string)($l['retdesc'] ?? '')];
            }
            $grupos[$key]['base'] += (float)$l['base'] * $k;
        }
        foreach ($grupos as &$g) {
            $g['base'] = round($g['base'], 2);
            $g['cuota'] = round($g['base'] * $g['iva'] / 100, 2);
            $g['cuota_recargo'] = round($g['base'] * $g['recargo'] / 100, 2);
            $g['cuota_irpf'] = round($g['base'] * $g['irpf'] / 100, 2);
        }
        return array_values($grupos);
    }

    /** Categoría fiscal de un grupo de líneas: tipo IVA ('21', '10', '4', '0', otros) o exenta|nosujeta|isp. */
    public static function categoria(array $g, string $operacion): string
    {
        if (in_array($g['exc'], self::NO_SUJETA, true)) {
            return 'nosujeta';
        }
        if (in_array($g['exc'], self::ISP, true)) {
            return 'isp';
        }
        if ('' !== $g['exc'] || 'exportacion' === $operacion || in_array($operacion, self::INTRA, true)) {
            return abs($g['iva']) < 0.000001 ? 'exenta' : self::tipo($g['iva']);
        }
        return self::tipo($g['iva']);
    }

    private static function tipo(float $iva): string
    {
        return rtrim(rtrim(number_format($iva, 2, '.', ''), '0'), '.') ?: '0';
    }

    public static function esAlquiler(array $g): bool
    {
        if ('' !== $g['retdesc']) {
            return (bool)preg_match('/alquil|arrend|inmueble/i', $g['retdesc']);
        }
        return abs($g['irpf'] - 19.0) < 0.000001;
    }

    private static function sumar(array &$nodo, array $campos, string $ref): void
    {
        foreach ($campos as $k => $v) {
            $nodo[$k] = round(($nodo[$k] ?? 0.0) + $v, 2);
        }
        $nodo['refs'] = $nodo['refs'] ?? [];
        if (!in_array($ref, $nodo['refs'], true)) {
            $nodo['refs'][] = $ref;
        }
    }

    private static function cero(): array
    {
        return ['base' => 0.0, 'cuota' => 0.0, 'refs' => []];
    }

    /**
     * @param array $emitidas facturas de venta del año
     * @param array $recibidas facturas de compra del año
     * @param array $opts ['emisor'=>['nombre','cifnif'], 'ejercicio'=>codejercicio, 'pagos_previos'=>float, 'generado'=>string]
     */
    public static function paquete(int $anyo, string $periodo, array $emitidas, array $recibidas, array $opts = []): array
    {
        [$desde, $hasta] = self::rango($anyo, $periodo);
        $enPeriodo = fn(array $f) => $f['fecha'] >= $desde && $f['fecha'] <= $hasta;
        $anual = fn(array $f) => $f['fecha'] >= $anyo . '-01-01' && $f['fecha'] <= $anyo . '-12-31';
        $emitidasAnyo = array_values(array_filter($emitidas, $anual));
        $recibidasAnyo = array_values(array_filter($recibidas, $anual));
        $em = array_values(array_filter($emitidasAnyo, $enPeriodo));
        $rc = array_values(array_filter($recibidasAnyo, $enPeriodo));

        $avisos = [];
        $aviso = function (string $codigo, string $mensaje, ?string $ref = null) use (&$avisos) {
            $avisos[] = ['codigo' => $codigo, 'mensaje' => $mensaje, 'ref' => $ref];
        };
        $codEjercicio = (string)($opts['ejercicio'] ?? '');
        foreach ([$em, $rc] as $lista) {
            foreach ($lista as $f) {
                if ('' === (string)$f['codejercicio']) {
                    $aviso('sin_ejercicio', $f['codigo'], $f['ref']);
                } elseif ('' !== $codEjercicio && $f['codejercicio'] !== $codEjercicio) {
                    $aviso('otro_ejercicio', $f['codigo'], $f['ref']);
                }
                if ('' === trim((string)$f['cifnif'])) {
                    $aviso('nif_vacio', $f['codigo'], $f['ref']);
                }
                if ($f['rect'] && (float)$f['total'] > 0) {
                    $aviso('rect_positiva', $f['codigo'], $f['ref']);
                }
            }
        }

        $paq = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'aviso' => self::AVISO_LEGAL,
            'emisor' => $opts['emisor'] ?? ['nombre' => '', 'cifnif' => ''],
            'periodo' => ['ejercicio' => $codEjercicio, 'anyo' => $anyo, 'periodo' => $periodo, 'desde' => $desde, 'hasta' => $hasta],
            'generado' => $opts['generado'] ?? date('c'),
            'modelos' => [
                '303' => self::m303($em, $rc),
                '130' => self::m130($emitidasAnyo, $recibidasAnyo, $anyo, $hasta, (float)($opts['pagos_previos'] ?? 0)),
                '111' => self::retenciones($rc, false),
                '115' => self::retenciones($rc, true),
                '347' => self::m347($emitidasAnyo, $recibidasAnyo, $anyo),
                '349' => self::m349($em, $rc),
            ],
            'libros' => ['emitidas' => self::libro($em), 'recibidas' => self::libro($rc)],
        ];

        // retención no informada: proveedor con retención en alguna factura del año y sin ella en otra del periodo
        $conRet = [];
        foreach ($recibidasAnyo as $f) {
            if (abs((float)$f['totalirpf']) > 0.000001) {
                $conRet[self::claveTercero($f)] = true;
            }
        }
        foreach ($rc as $f) {
            if (isset($conRet[self::claveTercero($f)]) && abs((float)$f['totalirpf']) < 0.000001 && !$f['rect']) {
                $aviso('retencion_no_informada', $f['codigo'], $f['ref']);
            }
        }
        // 347: candidatos sin NIF
        foreach (['clientes', 'proveedores'] as $k) {
            foreach ($paq['modelos']['347'][$k] as $t) {
                if ('' === $t['cifnif']) {
                    $aviso('nif_vacio_347', $t['nombre']);
                }
            }
        }
        $paq['avisos'] = $avisos;
        return $paq;
    }

    public static function claveTercero(array $f): string
    {
        $nif = strtoupper(preg_replace('/[\s\-.]/', '', (string)$f['cifnif']));
        return '' !== $nif ? $nif : '#' . mb_strtolower(trim((string)$f['tercero']));
    }

    private static function m303(array $em, array $rc): array
    {
        $rep = ['tipos' => [], 'recargo_equivalencia' => [], 'exentas' => self::cero(), 'no_sujetas' => self::cero(), 'isp' => self::cero()];
        $sop = ['tipos' => [], 'recargo_soportado' => self::cero(), 'exentas' => self::cero(), 'no_sujetas' => self::cero(), 'isp' => self::cero()];
        foreach (['21', '10', '4', '0'] as $t) {
            $rep['tipos'][$t] = self::cero();
            $sop['tipos'][$t] = self::cero();
        }
        foreach ($em as $f) {
            foreach (self::desglose($f) as $g) {
                $c = self::categoria($g, (string)$f['operacion']);
                if (in_array($c, ['exenta', 'nosujeta', 'isp'], true)) {
                    $dest = ['exenta' => 'exentas', 'nosujeta' => 'no_sujetas', 'isp' => 'isp'][$c];
                    self::sumar($rep[$dest], ['base' => $g['base']], $f['ref']);
                } else {
                    $rep['tipos'][$c] = $rep['tipos'][$c] ?? self::cero();
                    self::sumar($rep['tipos'][$c], ['base' => $g['base'], 'cuota' => $g['cuota']], $f['ref']);
                }
                if ($g['recargo'] > 0) {
                    $rk = self::tipo($g['recargo']);
                    $rep['recargo_equivalencia'][$rk] = $rep['recargo_equivalencia'][$rk] ?? self::cero();
                    self::sumar($rep['recargo_equivalencia'][$rk], ['base' => $g['base'], 'cuota' => $g['cuota_recargo']], $f['ref']);
                }
            }
        }
        foreach ($rc as $f) {
            foreach (self::desglose($f) as $g) {
                $c = self::categoria($g, (string)$f['operacion']);
                if (in_array($c, ['exenta', 'nosujeta', 'isp'], true)) {
                    $dest = ['exenta' => 'exentas', 'nosujeta' => 'no_sujetas', 'isp' => 'isp'][$c];
                    self::sumar($sop[$dest], ['base' => $g['base']], $f['ref']);
                } else {
                    $sop['tipos'][$c] = $sop['tipos'][$c] ?? self::cero();
                    self::sumar($sop['tipos'][$c], ['base' => $g['base'], 'cuota' => $g['cuota']], $f['ref']);
                }
                if ($g['recargo'] > 0) {
                    self::sumar($sop['recargo_soportado'], ['base' => $g['base'], 'cuota' => $g['cuota_recargo']], $f['ref']);
                }
            }
        }
        $cuotaRep = 0.0;
        $baseRep = 0.0;
        foreach ($rep['tipos'] as $n) {
            $cuotaRep += $n['cuota'];
            $baseRep += $n['base'];
        }
        $recargo = 0.0;
        foreach ($rep['recargo_equivalencia'] as $n) {
            $recargo += $n['cuota'];
        }
        $cuotaSop = 0.0;
        $baseSop = 0.0;
        foreach ($sop['tipos'] as $n) {
            $cuotaSop += $n['cuota'];
            $baseSop += $n['base'];
        }
        $devengado = round($cuotaRep + $recargo, 2);
        return [
            'repercutido' => $rep, 'soportado' => $sop,
            'total_base_repercutida' => round($baseRep, 2), 'total_cuota_repercutida' => round($cuotaRep, 2),
            'total_recargo_equivalencia' => round($recargo, 2), 'total_devengado' => $devengado,
            'total_base_soportada' => round($baseSop, 2), 'total_cuota_deducible' => round($cuotaSop, 2),
            'resultado' => round($devengado - $cuotaSop, 2),
        ];
    }

    private static function m130(array $emAnyo, array $rcAnyo, int $anyo, string $hasta, float $previos): array
    {
        $ing = self::cero();
        $gas = self::cero();
        $ret = self::cero();
        foreach ($emAnyo as $f) {
            if ($f['fecha'] > $hasta) {
                continue;
            }
            self::sumar($ing, ['base' => (float)$f['neto']], $f['ref']);
            if (abs((float)$f['totalirpf']) > 0.000001) {
                self::sumar($ret, ['base' => (float)$f['totalirpf']], $f['ref']);
            }
        }
        foreach ($rcAnyo as $f) {
            if ($f['fecha'] <= $hasta) {
                self::sumar($gas, ['base' => (float)$f['neto']], $f['ref']);
            }
        }
        $rend = round($ing['base'] - $gas['base'], 2);
        $pago = round(max($rend, 0) * 0.20, 2);
        return [
            'acumulado_desde' => $anyo . '-01-01', 'acumulado_hasta' => $hasta,
            'ingresos' => ['importe' => $ing['base'], 'refs' => $ing['refs']],
            'gastos_deducibles' => ['importe' => $gas['base'], 'refs' => $gas['refs']],
            'rendimiento_neto' => $rend,
            'veinte_por_ciento' => $pago,
            'retenciones_soportadas' => ['importe' => $ret['base'], 'refs' => $ret['refs']],
            'pagos_previos' => round($previos, 2),
            'resultado' => round($pago - $ret['base'] - $previos, 2),
            'nota' => 'Estimación directa simplificada, sin provisión de difícil justificación ni reducciones; los pagos previos los introduce el usuario.',
        ];
    }

    /** Modelo 111 (alquiler=false) o 115 (alquiler=true): retenciones practicadas en compras del periodo. */
    private static function retenciones(array $rc, bool $alquiler): array
    {
        $perc = [];
        $tot = self::cero();
        foreach ($rc as $f) {
            foreach (self::desglose($f) as $g) {
                if ($g['irpf'] <= 0 && abs($g['cuota_irpf']) < 0.000001) {
                    continue;
                }
                if (self::esAlquiler($g) !== $alquiler) {
                    continue;
                }
                $k = self::claveTercero($f);
                $perc[$k] = $perc[$k] ?? ['nombre' => $f['tercero'], 'cifnif' => (string)$f['cifnif'], 'base' => 0.0, 'retencion' => 0.0, 'refs' => []];
                self::sumar($perc[$k], ['base' => $g['base'], 'retencion' => $g['cuota_irpf']], $f['ref']);
                self::sumar($tot, ['base' => $g['base'], 'cuota' => $g['cuota_irpf']], $f['ref']);
            }
        }
        return ['perceptores' => array_values($perc), 'num_perceptores' => count($perc),
            'base' => $tot['base'], 'retenciones' => $tot['cuota'], 'refs' => $tot['refs']];
    }

    private static function m347(array $emAnyo, array $rcAnyo, int $anyo): array
    {
        $agrupa = function (array $facturas) {
            $t = [];
            foreach ($facturas as $f) {
                if (in_array((string)$f['operacion'], self::FUERA_347, true)) {
                    continue;
                }
                $k = self::claveTercero($f);
                $t[$k] = $t[$k] ?? ['nombre' => $f['tercero'], 'cifnif' => (string)$f['cifnif'], 'total' => 0.0,
                    'trimestres' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0], 'refs' => []];
                $q = self::trimestreDe($f['fecha']);
                $t[$k]['trimestres'][$q] = round($t[$k]['trimestres'][$q] + (float)$f['total'], 2);
                self::sumar($t[$k], ['total' => (float)$f['total']], $f['ref']);
            }
            $out = [];
            foreach ($t as $x) {
                if ($x['total'] > self::UMBRAL_347) {
                    $x['trimestres'] = array_values($x['trimestres']);
                    $out[] = $x;
                }
            }
            usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
            return $out;
        };
        return ['anyo' => $anyo, 'umbral' => self::UMBRAL_347, 'clientes' => $agrupa($emAnyo), 'proveedores' => $agrupa($rcAnyo)];
    }

    private static function m349(array $em, array $rc): array
    {
        $filas = [];
        $mapa = ['venta' => ['intracomunitaria' => 'E', 'intracom-servicios' => 'S'], 'compra' => ['intracomunitaria' => 'A', 'intracom-servicios' => 'I']];
        foreach (['venta' => $em, 'compra' => $rc] as $tipo => $lista) {
            foreach ($lista as $f) {
                $op = (string)$f['operacion'];
                if (!isset($mapa[$tipo][$op])) {
                    continue;
                }
                $k = $mapa[$tipo][$op] . '|' . self::claveTercero($f);
                $filas[$k] = $filas[$k] ?? ['clave' => $mapa[$tipo][$op], 'nombre' => $f['tercero'], 'cifnif' => (string)$f['cifnif'], 'base' => 0.0, 'refs' => []];
                self::sumar($filas[$k], ['base' => (float)$f['neto']], $f['ref']);
            }
        }
        $filas = array_values($filas);
        $total = 0.0;
        foreach ($filas as $x) {
            $total += $x['base'];
        }
        return ['operaciones' => $filas, 'total' => round($total, 2)];
    }

    /** Libro de IVA: una fila por factura y tipo impositivo. */
    public static function libro(array $facturas): array
    {
        usort($facturas, fn($a, $b) => [$a['fecha'], $a['codigo']] <=> [$b['fecha'], $b['codigo']]);
        $filas = [];
        foreach ($facturas as $f) {
            foreach (self::desglose($f) as $g) {
                $filas[] = [
                    'fecha' => $f['fecha'], 'factura' => $f['codigo'], 'numero_externo' => (string)($f['numero2'] ?? ''),
                    'tercero' => $f['tercero'], 'cifnif' => (string)$f['cifnif'], 'rectificativa' => (bool)$f['rect'],
                    'base' => $g['base'], 'tipo_iva' => $g['iva'], 'cuota_iva' => $g['cuota'],
                    'tipo_recargo' => $g['recargo'], 'cuota_recargo' => $g['cuota_recargo'],
                    'retencion' => $g['cuota_irpf'], 'ref' => $f['ref'],
                ];
            }
        }
        return $filas;
    }

    /** Refs (facturas) de una cifra por ruta, p. ej. "303.repercutido.tipos.21". Null si no existe. */
    public static function refsDe(array $paquete, string $ruta): ?array
    {
        $nodo = $paquete['modelos'];
        foreach (explode('.', $ruta) as $p) {
            if (!is_array($nodo) || !array_key_exists($p, $nodo)) {
                return null;
            }
            $nodo = $nodo[$p];
        }
        return is_array($nodo) && isset($nodo['refs']) ? $nodo['refs'] : null;
    }
}
