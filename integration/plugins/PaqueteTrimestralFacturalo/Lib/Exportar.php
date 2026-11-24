<?php
namespace FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib;

use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use ZipArchive;

/** Salidas del paquete: CSV UTF-8 (';'), PDF, JSON y ZIP. */
final class Exportar
{
    const CAB_LIBRO = ['fecha' => 'Fecha', 'factura' => 'Factura', 'numero_externo' => 'N.º externo', 'tercero' => 'Tercero', 'cifnif' => 'NIF',
        'base' => 'Base', 'tipo_iva' => '% IVA', 'cuota_iva' => 'Cuota IVA', 'tipo_recargo' => '% RE', 'cuota_recargo' => 'Cuota RE', 'retencion' => 'Retención'];

    public static function nombre(array $p, string $que): string
    {
        return 'paquete-' . $p['periodo']['anyo'] . '-' . $p['periodo']['periodo'] . '-' . $que;
    }

    private static function num($v): string
    {
        return is_float($v) || is_int($v) ? number_format((float)$v, 2, ',', '') : (string)$v;
    }

    /** Fila CSV: sin saltos de línea y neutralizando fórmulas (=, +, @) en celdas de texto. */
    private static function csvLinea(array $celdas): string
    {
        $o = [];
        foreach ($celdas as $c) {
            $c = str_replace(["\r", "\n"], ' ', (string)$c);
            if ('' !== $c && in_array($c[0], ['=', '+', '@'], true)) {
                $c = "'" . $c;
            }
            $o[] = preg_match('/[;"]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }
        return implode(';', $o) . "\r\n";
    }

    public static function libroCsv(array $filas, array $p, string $titulo): string
    {
        $s = "\xEF\xBB\xBF" . self::csvLinea([$titulo . ' · ' . $p['emisor']['nombre'] . ' (' . $p['emisor']['cifnif'] . ') · ' . $p['periodo']['desde'] . ' – ' . $p['periodo']['hasta']]);
        $s .= self::csvLinea([$p['aviso']]) . self::csvLinea(array_values(self::CAB_LIBRO));
        foreach ($filas as $f) {
            $l = [];
            foreach (array_keys(self::CAB_LIBRO) as $k) {
                $l[] = self::num($f[$k]);
            }
            $s .= self::csvLinea($l);
        }
        return $s;
    }

    /** Resumen plano (modelo; concepto; base; cuota/importe) para CSV y PDF. */
    public static function resumenFilas(array $p): array
    {
        $m = $p['modelos'];
        $r = [];
        $add = function (string $mod, string $con, $a = '', $b = '') use (&$r) {
            $r[] = ['modelo' => $mod, 'concepto' => $con, 'base' => '' === $a ? '' : self::num($a), 'cuota' => '' === $b ? '' : self::num($b)];
        };
        $rep = $m['303']['repercutido'];
        foreach ($rep['tipos'] as $t => $n) {
            $add('303', 'IVA repercutido ' . $t . '%', $n['base'], $n['cuota']);
        }
        foreach ($rep['recargo_equivalencia'] as $t => $n) {
            $add('303', 'Recargo de equivalencia ' . $t . '%', $n['base'], $n['cuota']);
        }
        $add('303', 'Operaciones exentas', $rep['exentas']['base']);
        $add('303', 'Operaciones no sujetas', $rep['no_sujetas']['base']);
        foreach ($m['303']['soportado']['tipos'] as $t => $n) {
            $add('303', 'IVA soportado ' . $t . '%', $n['base'], $n['cuota']);
        }
        $add('303', 'Total devengado', '', $m['303']['total_devengado']);
        $add('303', 'Total deducible', '', $m['303']['total_cuota_deducible']);
        $add('303', 'Resultado', '', $m['303']['resultado']);
        $c = $m['130'];
        $add('130', 'Ingresos acumulados', $c['ingresos']['importe']);
        $add('130', 'Gastos deducibles acumulados', $c['gastos_deducibles']['importe']);
        $add('130', 'Rendimiento neto', $c['rendimiento_neto']);
        $add('130', '20 % del rendimiento', '', $c['veinte_por_ciento']);
        $add('130', 'Retenciones soportadas', '', $c['retenciones_soportadas']['importe']);
        $add('130', 'Pagos fraccionados previos', '', $c['pagos_previos']);
        $add('130', 'Resultado', '', $c['resultado']);
        $add('111', 'Retenciones a profesionales (' . $m['111']['num_perceptores'] . ' perceptores)', $m['111']['base'], $m['111']['retenciones']);
        $add('115', 'Retenciones por alquileres (' . $m['115']['num_perceptores'] . ' perceptores)', $m['115']['base'], $m['115']['retenciones']);
        foreach (['clientes', 'proveedores'] as $k) {
            foreach ($m['347'][$k] as $t) {
                $add('347', $k . ': ' . $t['nombre'] . ' ' . $t['cifnif'], '', $t['total']);
            }
        }
        foreach ($m['349']['operaciones'] as $t) {
            $add('349', 'Clave ' . $t['clave'] . ': ' . $t['nombre'] . ' ' . $t['cifnif'], $t['base']);
        }
        return $r;
    }

    public static function resumenCsv(array $p): string
    {
        $s = "\xEF\xBB\xBF" . self::csvLinea([$p['aviso']]) . self::csvLinea(['Modelo', 'Concepto', 'Base', 'Cuota / importe']);
        foreach (self::resumenFilas($p) as $f) {
            $s .= self::csvLinea(array_values($f));
        }
        return $s;
    }

    public static function json(array $p): string
    {
        return json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** PDF de una tabla con el nombre del emisor y el aviso legal en el título. */
    public static function pdfTabla(array $cabeceras, array $filas, array $p, string $titulo): string
    {
        $em = new ExportManager();
        $em->newDoc('PDF', $titulo);
        $rows = [];
        foreach ($filas as $f) {
            $r = [];
            foreach ($cabeceras as $clave => $texto) {
                $r[$texto] = self::num($f[$clave] ?? '');
            }
            $rows[] = $r;
        }
        $t = $titulo . ' · ' . $p['emisor']['nombre'] . ' (' . $p['emisor']['cifnif'] . ') · ' . $p['periodo']['desde'] . ' – ' . $p['periodo']['hasta'] . "\n" . $p['aviso'];
        $em->addTablePage(array_values($cabeceras), $rows, [], $t);
        return (string)$em->getDoc();
    }

    public static function libroPdf(array $filas, array $p, string $titulo): string
    {
        return self::pdfTabla(self::CAB_LIBRO, $filas, $p, $titulo);
    }

    public static function resumenPdf(array $p): string
    {
        return self::pdfTabla(['modelo' => 'Modelo', 'concepto' => 'Concepto', 'base' => 'Base', 'cuota' => 'Cuota / importe'],
            self::resumenFilas($p), $p, Tools::lang()->trans('trimestral-resumen'));
    }

    /** ZIP con libros, resúmenes y, si FacturaZip está activo, un PDF por factura emitida. Devuelve la ruta del temporal. */
    public static function zip(array $p): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'paquete-');
        $zip = new ZipArchive();
        if (true !== $zip->open($file, ZipArchive::OVERWRITE)) {
            return null;
        }
        $zip->addFromString('LEEME.txt', $p['aviso'] . "\r\n");
        $zip->addFromString('paquete.json', self::json($p));
        $zip->addFromString('resumen.csv', self::resumenCsv($p));
        $zip->addFromString('resumen.pdf', self::resumenPdf($p));
        $zip->addFromString('libro-emitidas.csv', self::libroCsv($p['libros']['emitidas'], $p, 'Libro de facturas emitidas'));
        $zip->addFromString('libro-emitidas.pdf', self::libroPdf($p['libros']['emitidas'], $p, 'Libro de facturas emitidas'));
        $zip->addFromString('libro-recibidas.csv', self::libroCsv($p['libros']['recibidas'], $p, 'Libro de facturas recibidas'));
        $zip->addFromString('libro-recibidas.pdf', self::libroPdf($p['libros']['recibidas'], $p, 'Libro de facturas recibidas'));
        if (Plugins::isEnabled('FacturaZip')) {
            $vistas = [];
            foreach ($p['libros']['emitidas'] as $fila) {
                if (isset($vistas[$fila['ref']])) {
                    continue;
                }
                $vistas[$fila['ref']] = true;
                $fac = new FacturaCliente();
                if (!$fac->load((int)substr($fila['ref'], 4))) {
                    continue;
                }
                $ex = new ExportManager();
                $ex->newDoc('PDF', $fac->codigo);
                $ex->addBusinessDocPage($fac);
                $pdf = $ex->getDoc();
                if (!empty($pdf)) {
                    $zip->addFromString('facturas-emitidas/' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $fac->codigo) . '.pdf', $pdf);
                }
            }
        }
        $zip->close();
        return $file;
    }
}
