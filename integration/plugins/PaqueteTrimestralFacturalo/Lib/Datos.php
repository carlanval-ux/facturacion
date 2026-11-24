<?php
namespace FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib;

use FacturaScripts\Core\DataSrc\Retenciones;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;

/** Carga las facturas del año de una empresa como arrays para Calculo (solo lectura). */
final class Datos
{
    /** @param string $tipo 'cli' | 'prov' */
    public static function facturas(string $tipo, int $idempresa, int $anyo): array
    {
        $cli = 'cli' === $tipo;
        $modelo = $cli ? new FacturaCliente() : new FacturaProveedor();
        $where = [Where::eq('idempresa', $idempresa), Where::between('fecha', $anyo . '-01-01', $anyo . '-12-31')];
        $out = [];
        $retenciones = [];
        foreach ($modelo->all($where, ['fecha' => 'ASC', 'idfactura' => 'ASC'], 0, 0) as $f) {
            $lineas = [];
            foreach ($f->getLines() as $l) {
                $cod = (string)($l->codretencion ?? '');
                if ('' !== $cod && !isset($retenciones[$cod])) {
                    $retenciones[$cod] = (string)Retenciones::get($cod)->descripcion;
                }
                $lineas[] = [
                    'base' => (float)$l->pvptotal, 'iva' => (float)$l->iva, 'recargo' => (float)$l->recargo,
                    'irpf' => (float)$l->irpf, 'exc' => (string)($l->excepcioniva ?? ''),
                    'retdesc' => '' !== $cod ? $retenciones[$cod] : '',
                ];
            }
            $out[] = [
                'ref' => $tipo . ':' . $f->idfactura, 'id' => (int)$f->idfactura, 'codigo' => (string)$f->codigo,
                'numero2' => (string)($cli ? $f->numero2 : $f->numproveedor),
                'fecha' => Calculo::fechaIso((string)$f->fecha), 'codejercicio' => (string)$f->codejercicio,
                'tercero' => (string)($cli ? $f->nombrecliente : $f->nombre), 'cifnif' => (string)$f->cifnif,
                'operacion' => (string)($f->operacion ?? ''), 'rect' => !empty($f->idfacturarect),
                'neto' => (float)$f->neto, 'totaliva' => (float)$f->totaliva, 'totalrecargo' => (float)$f->totalrecargo,
                'totalirpf' => (float)$f->totalirpf, 'total' => (float)$f->total, 'lineas' => $lineas,
            ];
        }
        return $out;
    }
}
