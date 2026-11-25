<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib;

/** Huella SHA-256 del contenido comercial del presupuesto: si se edita, cambia y el enlace enviado deja de valer. */
final class Contenido
{
    const CAMPOS = ['codigo', 'fecha', 'finoferta', 'codcliente', 'nombrecliente', 'cifnif', 'coddivisa', 'observaciones',
        'neto', 'totaliva', 'totalrecargo', 'totalirpf', 'total'];
    const CAMPOS_LINEA = ['referencia', 'descripcion', 'cantidad', 'pvpunitario', 'dtopor', 'dtopor2', 'codimpuesto', 'iva', 'recargo', 'irpf', 'pvptotal'];

    public static function hash(array $cabecera, array $lineas): string
    {
        $c = [];
        foreach (self::CAMPOS as $campo) {
            $c[$campo] = self::norm($cabecera[$campo] ?? null);
        }
        $l = [];
        foreach ($lineas as $linea) {
            $fila = [];
            foreach (self::CAMPOS_LINEA as $campo) {
                $fila[$campo] = self::norm($linea[$campo] ?? null);
            }
            $l[] = $fila;
        }
        return hash('sha256', json_encode(['c' => $c, 'l' => $l], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param object $doc PresupuestoCliente */
    public static function deDocumento($doc): string
    {
        $cab = [];
        foreach (self::CAMPOS as $campo) {
            $cab[$campo] = $doc->{$campo} ?? null;
        }
        $lineas = [];
        foreach ($doc->getLines() as $linea) {
            $fila = [];
            foreach (self::CAMPOS_LINEA as $campo) {
                $fila[$campo] = $linea->{$campo} ?? null;
            }
            $lineas[] = $fila;
        }
        return self::hash($cab, $lineas);
    }

    private static function norm($v): string
    {
        if (is_float($v) || is_int($v) || (is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v))) {
            return number_format((float)$v, 4, '.', '');
        }
        return trim((string)$v);
    }
}
