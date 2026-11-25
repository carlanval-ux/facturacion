<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Lib;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaloCambio;

/** MAPA-20: registro y consulta de cambios. Registrar nunca debe romper una factura. */
final class Cambios
{
    public const MAX = 500;

    /** @param string $tipo 'factura'|'recibo'; $evento 'guardado'|'eliminado' */
    public static function registrar(string $tipo, $idref, $idfactura, $idfacturarect, string $evento): void
    {
        try {
            $c = new FacturaloCambio();
            $c->tipo = $tipo;
            $c->idref = (int)$idref;
            $c->idfactura = $idfactura ? (int)$idfactura : null;
            $c->idfacturarect = $idfacturarect ? (int)$idfacturarect : null;
            $c->evento = $evento;
            $c->creado = date('Y-m-d H:i:s');
            $c->save();
        } catch (\Throwable $e) {
            // shortcut: un fallo del registro se descarta (no rompe la factura); la gestoría puede releer por fecha.
        }
    }

    /**
     * Cursor: entero = id del último cambio visto; fecha (Y-m-d[ H:i:s]) = cambios desde esa fecha.
     * Devuelve ['cambios' => [...], 'siguiente' => id, 'hay_mas' => bool] o ['error' => ...].
     * shortcut: la tabla crece sin purga; podar cambios antiguos (> 1 año) cuando moleste.
     */
    public static function listar(string $desde, int $limite): array
    {
        $limite = max(1, min(self::MAX, $limite));
        if ('' === $desde || ctype_digit($desde)) {
            $where = [Where::gt('id', (int)$desde)];
        } else {
            $ts = strtotime($desde);
            if (false === $ts) {
                return ['error' => 'desde: id numérico o fecha Y-m-d[ H:i:s]'];
            }
            $where = [Where::gte('creado', date('Y-m-d H:i:s', $ts))];
        }
        $filas = FacturaloCambio::all($where, ['id' => 'ASC'], 0, $limite + 1);
        $hayMas = count($filas) > $limite;
        $out = [];
        foreach (array_slice($filas, 0, $limite) as $f) {
            $out[] = [
                'id' => (int)$f->id, 'tipo' => $f->tipo, 'idref' => (int)$f->idref, 'idfactura' => $f->idfactura,
                'idfacturarect' => $f->idfacturarect, 'evento' => $f->evento, 'creado' => $f->creado,
            ];
        }
        $siguiente = $out ? end($out)['id'] : (int)$desde;
        return ['cambios' => $out, 'siguiente' => $siguiente, 'hay_mas' => $hayMas];
    }
}
