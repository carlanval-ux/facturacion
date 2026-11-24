<?php
namespace FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib\Calculo;
use FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib\Datos;
use FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib\Exportar;
use FacturaScripts\Plugins\PaqueteTrimestralFacturalo\Lib\ModuloActivo;

/** Cifras del periodo para el cliente y su gestoría. No presenta nada ante la Agencia Tributaria. */
class PaqueteTrimestral extends Controller
{
    const PERIODOS = ['T1', 'T2', 'T3', 'T4', 'anual'];

    /** @var bool */
    public $activo = true;
    /** @var array */
    public $paquete = [];
    /** @var string */
    public $codejercicio = '';
    /** @var string */
    public $periodo = 'T1';
    /** @var float */
    public $previos = 0.0;
    /** @var array|null facturas de la cifra consultada (drill-down) */
    public $detalle = null;
    /** @var string */
    public $ruta = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'trimestral-title';
        $data['icon'] = 'fa-solid fa-box-archive';
        return $data;
    }

    public function ejercicios(): array
    {
        return Ejercicios::byEmpresa($this->empresa->idempresa);
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->activo = ModuloActivo::estaActivo();
        if (false === $this->activo) {
            return;
        }

        $this->codejercicio = (string)$this->request->queryOrInput('codejercicio', '');
        $this->periodo = (string)$this->request->queryOrInput('periodo', 'T' . Calculo::trimestreDe(date('Y-m-d')));
        if (!in_array($this->periodo, self::PERIODOS, true)) {
            $this->periodo = 'T1';
        }
        $this->previos = (float)str_replace(',', '.', (string)$this->request->queryOrInput('previos', '0'));

        $ej = $this->ejercicioElegido();
        if (null === $ej) {
            return;
        }
        $this->codejercicio = $ej->codejercicio;
        $anyo = (int)date('Y', strtotime($ej->fechainicio));
        $idempresa = (int)$this->empresa->idempresa;
        $this->paquete = Calculo::paquete($anyo, $this->periodo,
            Datos::facturas('cli', $idempresa, $anyo), Datos::facturas('prov', $idempresa, $anyo), [
                'emisor' => ['nombre' => (string)$this->empresa->nombre, 'cifnif' => (string)$this->empresa->cifnif],
                'ejercicio' => $ej->codejercicio, 'pagos_previos' => $this->previos,
            ]);

        $accion = (string)$this->request->queryOrInput('action', '');
        if ('' !== $accion) {
            if (false === $this->permissions->allowExport) {
                Tools::log()->warning('no-print-permission');
            } else {
                $this->exportar($response, $accion);
            }
        }

        $this->ruta = (string)$this->request->queryOrInput('ver', '');
        if ('' !== $this->ruta) {
            $refs = Calculo::refsDe($this->paquete, $this->ruta);
            $this->detalle = null === $refs ? [] : $this->facturasDe($refs);
        }
    }

    private function ejercicioElegido()
    {
        $lista = $this->ejercicios();
        foreach ($lista as $e) {
            if ($e->codejercicio === $this->codejercicio) {
                return $e;
            }
        }
        foreach ($lista as $e) {
            if ($e->isOpened()) {
                return $e;
            }
        }
        return reset($lista) ?: null;
    }

    private function exportar(&$response, string $accion): void
    {
        $p = $this->paquete;
        $libros = ['libro-emitidas' => ['emitidas', 'Libro de facturas emitidas'], 'libro-recibidas' => ['recibidas', 'Libro de facturas recibidas']];
        $salida = null;
        $nombre = '';
        $tipo = '';
        if (preg_match('/^(libro-emitidas|libro-recibidas)-(csv|pdf)$/', $accion, $m)) {
            [$k, $titulo] = $libros[$m[1]];
            $filas = $p['libros'][$k];
            $nombre = Exportar::nombre($p, $m[1]) . '.' . $m[2];
            $salida = 'csv' === $m[2] ? Exportar::libroCsv($filas, $p, $titulo) : Exportar::libroPdf($filas, $p, $titulo);
            $tipo = 'csv' === $m[2] ? 'text/csv; charset=utf-8' : 'application/pdf';
        } elseif ('resumen-csv' === $accion) {
            $nombre = Exportar::nombre($p, 'resumen') . '.csv';
            $salida = Exportar::resumenCsv($p);
            $tipo = 'text/csv; charset=utf-8';
        } elseif ('resumen-pdf' === $accion) {
            $nombre = Exportar::nombre($p, 'resumen') . '.pdf';
            $salida = Exportar::resumenPdf($p);
            $tipo = 'application/pdf';
        } elseif ('json' === $accion) {
            $nombre = Exportar::nombre($p, 'datos') . '.json';
            $salida = Exportar::json($p);
            $tipo = 'application/json; charset=utf-8';
        } elseif ('zip' === $accion) {
            $file = Exportar::zip($p);
            if (null === $file) {
                Tools::log()->error('zip-file-error');
                return;
            }
            $this->setTemplate(false);
            $response->download($file, Exportar::nombre($p, 'completo') . '.zip');
            return;
        }
        if (null === $salida) {
            return;
        }
        $this->setTemplate(false);
        $response->header('Content-Type', $tipo);
        $response->header('Content-Disposition', 'attachment; filename="' . $nombre . '"');
        $response->setContent($salida);
    }

    /** Facturas (código, fecha, tercero, total, enlace) de una lista de refs "cli:12" / "prov:7". */
    private function facturasDe(array $refs): array
    {
        $porRef = [];
        foreach (['emitidas', 'recibidas'] as $l) {
            foreach ($this->paquete['libros'][$l] as $f) {
                $porRef[$f['ref']] = $porRef[$f['ref']] ?? ['codigo' => $f['factura'], 'fecha' => $f['fecha'], 'tercero' => $f['tercero'], 'cifnif' => $f['cifnif'], 'total' => 0.0];
                $porRef[$f['ref']]['total'] = round($porRef[$f['ref']]['total'] + $f['base'] + $f['cuota_iva'] + $f['cuota_recargo'] - $f['retencion'], 2);
            }
        }
        $out = [];
        foreach ($refs as $r) {
            [$t, $id] = explode(':', $r) + [1 => '0'];
            // las refs de 130/347 pueden quedar fuera del periodo y no estar en los libros: se enlazan igualmente
            $fila = $porRef[$r] ?? ['codigo' => $r, 'fecha' => '', 'tercero' => '', 'cifnif' => '', 'total' => null];
            $fila['url'] = ('cli' === $t ? 'EditFacturaCliente' : 'EditFacturaProveedor') . '?code=' . (int)$id;
            $out[] = $fila;
        }
        return $out;
    }

    public function enlace(string $ruta): string
    {
        return 'PaqueteTrimestral?codejercicio=' . urlencode($this->codejercicio) . '&periodo=' . $this->periodo
            . '&previos=' . $this->previos . '&ver=' . urlencode($ruta);
    }

    public function enlaceAccion(string $accion): string
    {
        return 'PaqueteTrimestral?codejercicio=' . urlencode($this->codejercicio) . '&periodo=' . $this->periodo
            . '&previos=' . $this->previos . '&action=' . $accion;
    }

    public function dinero($v): string
    {
        return Tools::number((float)$v, 2);
    }

    /** Texto traducido de un aviso: código + referencia a la factura. */
    public function avisos(): array
    {
        return $this->paquete['avisos'] ?? [];
    }
}
