<?php
namespace FacturaScripts\Plugins\ContabilidadAlta\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Accounting\AccountingPlanImport;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\Retencion;
use FacturaScripts\Dinamic\Model\SecuenciaDocumento;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * MAPA-19: aprovisionamiento contable de una empresa (Anexo A del mapa) y su verificación (A.7).
 * Idempotente: cada paso comprueba antes de escribir y nunca borra ni renombra datos existentes.
 * Opciones: idempresa (def. la por defecto), longsubcuenta (def. 10), anyo (def. año actual),
 * plan_csv (texto del CSV de Clarto; def. el ESP de FS).
 */
final class Aprovisionador
{
    /** @var string[] */
    private array $pasos = [];
    private int $idempresa;
    private int $long;
    private int $anyo;
    private string $csv;

    public function __construct(array $o = [])
    {
        $this->idempresa = (int)($o['idempresa'] ?? Tools::settings('default', 'idempresa', 1));
        $this->long = (int)($o['longsubcuenta'] ?? 10);
        $this->anyo = (int)($o['anyo'] ?? date('Y'));
        $this->csv = (string)($o['plan_csv'] ?? '');
    }

    /** Valida las opciones; devuelve el error o null. */
    public function validar(): ?string
    {
        if ($this->long < 9 || $this->long > 11) {
            return 'longsubcuenta debe estar entre 9 y 11';
        }
        if ($this->anyo < 2000 || $this->anyo > 2100) {
            return 'anyo fuera de rango';
        }
        return strlen($this->csv) > 3000000 ? 'plan_csv demasiado grande' : null;
    }

    public function aprovisionar(): array
    {
        $ej = $this->ejercicio();
        if (null !== $ej) {
            $this->plan($ej);
            $this->impuestos();
            $this->retenciones();
            $this->seriesYSecuencias($ej);
            $this->ajustes();
        }
        return ['pasos' => $this->pasos] + $this->verificar();
    }

    /** Informe de diferencias contra los parámetros esperados (criterio de cierre A.7). */
    public function verificar(): array
    {
        $ej = $this->buscarEjercicio();
        $esperado = Esperado::plano($this->long, $this->anyo, $this->impuestosAbolidos(), $this->ajustesAplicables());
        $dif = Esperado::comparar($esperado, $this->actual($ej, $esperado));
        return [
            'conforme' => empty($dif), 'idempresa' => $this->idempresa, 'codejercicio' => $ej->codejercicio ?? null,
            'longsubcuenta' => $this->long, 'diferencias' => $dif,
        ];
    }

    private function buscarEjercicio(): ?Ejercicio
    {
        $e = new Ejercicio();
        $w = [Where::eq('idempresa', $this->idempresa), Where::eq('fechainicio', $this->anyo . '-01-01')];
        return $e->loadWhere($w) ? $e : null;
    }

    private function ejercicio(): ?Ejercicio
    {
        $e = $this->buscarEjercicio();
        if (null !== $e) {
            if ((int)$e->longsubcuenta !== $this->long) {
                if ($e->hasAccountingPlan()) {
                    $this->pasos[] = 'ejercicio ' . $e->codejercicio . ': longsubcuenta ' . $e->longsubcuenta . ' y ya tiene subcuentas; no se cambia';
                    return $e;
                }
                $e->longsubcuenta = $this->long;
                $this->guardar($e, 'ejercicio ' . $e->codejercicio . ': longsubcuenta=' . $this->long);
            }
            return $e;
        }
        $e = new Ejercicio();
        $e->idempresa = $this->idempresa;
        $e->codejercicio = (string)$this->anyo;
        if ($e->exists()) { // el código ya es de otra empresa
            $e->codejercicio = sprintf('%04s', '0' . $this->idempresa . substr((string)$this->anyo, 2));
        }
        $e->nombre = (string)$this->anyo;
        $e->fechainicio = '01-01-' . $this->anyo;
        $e->fechafin = '31-12-' . $this->anyo;
        $e->estado = Ejercicio::EXERCISE_STATUS_OPEN;
        $e->longsubcuenta = $this->long;
        return $this->guardar($e, 'ejercicio ' . $e->codejercicio . ' creado') ? $e : null;
    }

    private function plan(Ejercicio $e): void
    {
        if ($e->hasAccountingPlan()) {
            $this->pasos[] = 'plan: ya importado, no se toca';
            return;
        }
        $ruta = FS_FOLDER . '/Core/Data/Codpais/ESP/defaultPlan.csv';
        $tmp = null;
        if ('' !== trim($this->csv)) {
            $ruta = $tmp = tempnam(sys_get_temp_dir(), 'plan');
            file_put_contents($tmp, $this->csv);
        }
        $ok = (new AccountingPlanImport())->importCSV($ruta, $e->codejercicio, $this->long);
        $tmp && @unlink($tmp);
        $this->pasos[] = 'plan: importación ' . ($ok ? 'correcta' : 'FALLIDA');
    }

    private function impuestos(): void
    {
        $porCodigo = [];
        foreach (Esperado::plano($this->long, $this->anyo, $this->impuestosAbolidos()) as $k => $v) {
            $p = Esperado::partir($k);
            if ('impuesto' === $p[0]) {
                $porCodigo[$p[1]][$p[2]] = $v;
            }
        }
        foreach ($porCodigo as $cod => $campos) {
            $i = new Impuesto();
            if (!$i->load($cod)) {
                if (!isset(Esperado::IVA[$cod])) {
                    continue;
                }
                $i->codimpuesto = $cod;
                $i->descripcion = 'IVA ' . Esperado::IVA[$cod][0] . '%';
                $i->operacion = 'ES_01';
            }
            foreach ($campos as $campo => $v) {
                $i->{$campo} = $v;
            }
            $this->guardar($i, null);
        }
        $this->pasos[] = 'impuestos: IVA 0/4/10/21 con subcuentas de grupo; IGIC/IPSI desactivados';
    }

    private function retenciones(): void
    {
        foreach (Esperado::IRPF as $cod => $pct) {
            $r = new Retencion();
            if (!$r->load($cod)) {
                $r->codretencion = $cod;
                $r->descripcion = 'IRPF ' . $pct . '%';
            }
            $r->porcentaje = $pct;
            $r->codsubcuentaret = Reglas::subcuenta('473', $this->long);
            $r->codsubcuentaacr = Reglas::subcuenta('4751', $this->long);
            $this->guardar($r, null);
        }
        $this->pasos[] = 'retenciones: IRPF 2/7/15/19/21';
    }

    private function seriesYSecuencias(Ejercicio $e): void
    {
        foreach (Esperado::SERIES as $cod => [$desc, $tipo]) {
            $s = new Serie();
            if (!$s->load($cod)) {
                $s->codserie = $cod;
                $s->descripcion = $desc;
                $s->tipo = $tipo;
                $this->guardar($s, 'serie ' . $cod . ' creada');
            } elseif ((string)$s->tipo !== (string)$tipo) {
                $s->tipo = $tipo;
                $this->guardar($s, 'serie ' . $cod . ': tipo');
            }
            $q = new SecuenciaDocumento();
            $nueva = !$q->loadWhere($this->filtroSecuencia($e->codejercicio, $cod));
            if ($nueva) {
                $q->idempresa = $this->idempresa;
                $q->codejercicio = $e->codejercicio;
                $q->codserie = $cod;
                $q->tipodoc = 'FacturaCliente';
                $q->inicio = $q->numero = 1;
            }
            $w = [Where::eq('idempresa', $this->idempresa), Where::eq('codejercicio', $e->codejercicio), Where::eq('codserie', $cod)];
            if (!$nueva && FacturaCliente::count($w) > 0) {
                // con facturas ya emitidas el patrón no cambia: se mezclarían formatos
                $this->pasos[] = 'secuencia ' . $cod . ': hay facturas emitidas; patrón intacto, usarhuecos=false';
            } else {
                $q->patron = Reglas::PATRON;
                $q->longnumero = Reglas::LONGNUMERO;
            }
            $q->usarhuecos = false;
            $q->mantenerfecha = false;
            $this->guardar($q, null);
        }
        $this->pasos[] = 'series y secuencias A/R/S';
    }

    private function filtroSecuencia(string $codejercicio, string $codserie): array
    {
        return [
            Where::eq('idempresa', $this->idempresa), Where::eq('codejercicio', $codejercicio),
            Where::eq('codserie', $codserie), Where::eq('tipodoc', 'FacturaCliente'),
        ];
    }

    private function ajustes(): void
    {
        foreach ($this->ajustesAplicables() as $k) {
            Tools::settingsSet('default', $k, Esperado::AJUSTES[$k]);
        }
        Tools::settingsSave();
        $this->pasos[] = 'ajustes: ' . implode(', ', $this->ajustesAplicables());
    }

    /** codpago, coddivisa y codimpuesto solo se fijan si existen; no se crean formas de pago ni divisas. */
    private function ajustesAplicables(): array
    {
        $existe = [
            'codpago' => fn($v) => (new FormaPago())->load($v),
            'coddivisa' => fn($v) => (new Divisa())->load($v),
            'codimpuesto' => fn($v) => (new Impuesto())->load($v),
        ];
        return array_values(array_filter(array_keys(Esperado::AJUSTES), function ($k) use ($existe) {
            return !isset($existe[$k]) || $existe[$k](Esperado::AJUSTES[$k]);
        }));
    }

    /** Impuestos IGIC/IPSI (operación ES_03/ES_04) existentes: se desactivan. */
    private function impuestosAbolidos(): array
    {
        $out = [];
        foreach (Impuesto::all() as $i) {
            if (in_array($i->operacion, ['ES_03', 'ES_04'], true)) {
                $out[] = $i->codimpuesto;
            }
        }
        return $out;
    }

    private function actual(?Ejercicio $e, array $esperado): array
    {
        $a = [];
        if (null !== $e) {
            $a['ejercicio.longsubcuenta'] = $e->longsubcuenta;
            $a['ejercicio.fechainicio'] = date('Y-m-d', strtotime($e->fechainicio));
            $a['ejercicio.fechafin'] = date('Y-m-d', strtotime($e->fechafin));
            $a['ejercicio.estado'] = $e->estado;
            $cu = $su = [];
            foreach (Cuenta::all([Where::eq('codejercicio', $e->codejercicio)]) as $c) {
                $cu[$c->codcuenta] = $c->codcuentaesp;
            }
            foreach (Subcuenta::all([Where::eq('codejercicio', $e->codejercicio)]) as $s) {
                $su[$s->codsubcuenta] = $s->codcuentaesp;
            }
            foreach (Esperado::planFaltas($this->filasCsv(), $cu, $su) as $k => $v) {
                $a['plan.' . $k] = $v;
            }
        }
        $modelos = ['impuesto' => Impuesto::class, 'retencion' => Retencion::class, 'serie' => Serie::class];
        foreach (array_keys($esperado) as $k) {
            $p = Esperado::partir($k);
            if (isset($modelos[$p[0]])) {
                $m = new $modelos[$p[0]]();
                if ($m->load($p[1])) {
                    $a[$k] = $m->{$p[2]};
                }
            } elseif ('secuencia' === $p[0] && null !== $e) {
                $q = new SecuenciaDocumento();
                if ($q->loadWhere($this->filtroSecuencia($e->codejercicio, $p[1]))) {
                    $a[$k] = $q->{$p[2]};
                }
            } elseif ('ajuste' === $p[0]) {
                $a[$k] = Tools::settings($p[1], $p[2]);
            }
        }
        return $a;
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function filasCsv(): array
    {
        $txt = '' !== trim($this->csv) ? $this->csv : (string)file_get_contents(FS_FOLDER . '/Core/Data/Codpais/ESP/defaultPlan.csv');
        $filas = [];
        foreach (preg_split('/\r\n|\n|\r/', $txt, -1, PREG_SPLIT_NO_EMPTY) as $n => $linea) {
            $c = str_getcsv($linea, ';', '"', '');
            if ($n > 0 && count($c) >= 2) {
                $filas[] = [trim($c[0]), $c[1], trim($c[2] ?? '')];
            }
        }
        return $filas;
    }

    private function guardar($modelo, ?string $paso): bool
    {
        $ok = $modelo->save();
        if (null !== $paso) {
            $this->pasos[] = $paso . ($ok ? '' : ' — ERROR al guardar');
        } elseif (!$ok) {
            $this->pasos[] = 'ERROR al guardar ' . get_class($modelo) . ' ' . $modelo->id();
        }
        return $ok;
    }
}
