<?php
namespace FacturaScripts\Plugins\RemesasSepaFacturalo\Lib;

use DateTimeImmutable;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\AcreedorSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\MandatoSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\RemesaSepa;
use FacturaScripts\Plugins\RemesasSepaFacturalo\Model\RemesaSepaRecibo;
use Throwable;

/** Lógica de remesas: selección de recibos, generación del XML y anulación. */
final class RemesaService
{
    /** Filtro de recibos remesables: pendientes, de clientes con mandato activo y aún no remesados. */
    public static function filtroPendientes(): array
    {
        return [
            Where::eq('pagado', false),
            Where::notIn('idrecibo', "SELECT idrecibo FROM sepa_remesas_recibos WHERE estado IN ('remesado','cobrado')"),
            Where::in('codcliente', "SELECT codcliente FROM sepa_mandatos WHERE estado = 'activo'"),
        ];
    }

    /**
     * Crea la remesa. Devuelve [RemesaSepa|null, errores[]]. Los errores son claves de traducción
     * (posiblemente con detalle tras «: »). No se registra ningún IBAN en mensajes.
     *
     * @param int[] $idrecibos
     */
    public static function crear(array $idrecibos, ?string $fechaCobro, string $nick, ?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? new DateTimeImmutable('now');
        $hoy = $ahora->setTime(0, 0);
        $idrecibos = array_values(array_unique(array_map('intval', $idrecibos)));
        if (!$idrecibos) {
            return [null, ['sepa-no-operations']];
        }
        // fecha única opcional; si no, cada recibo se cobra en su vencimiento (o lo antes posible)
        $fijo = null;
        if (!empty($fechaCobro)) {
            if (false === strtotime($fechaCobro)) {
                return [null, ['collection-date-invalid']];
            }
            $fijo = DiasHabiles::siguienteHabil(new DateTimeImmutable(date('Y-m-d', strtotime($fechaCobro))));
        }

        $errores = [];
        $ops = [];
        $lineas = [];
        $recibos = ReciboCliente::all([Where::in('idrecibo', $idrecibos)]);
        $idempresa = $recibos ? (int)$recibos[0]->idempresa : null;
        $acreedor = $idempresa === null ? null : AcreedorSepa::findWhere([Where::eq('idempresa', $idempresa)]);
        if (null === $acreedor) {
            return [null, ['creditor-missing']];
        }
        foreach ($recibos as $r) {
            if ((int)$r->idempresa !== $idempresa) {
                $errores[] = 'sepa-mixed-companies';
                continue;
            }
            if ($r->pagado || (float)$r->importe <= 0 || $r->coddivisa !== 'EUR') {
                $errores[] = 'sepa-receipt-not-collectable: ' . $r->idrecibo;
                continue;
            }
            if (RemesaSepaRecibo::count([Where::eq('idrecibo', $r->idrecibo), Where::in('estado', ['remesado', 'cobrado'])]) > 0) {
                $errores[] = 'sepa-receipt-already-remitted: ' . $r->idrecibo;
                continue;
            }
            $m = MandatoSepa::activoDe((string)$r->codcliente);
            if (null === $m) {
                $errores[] = 'sepa-no-active-mandate: ' . $r->codcliente;
                continue;
            }
            $cliente = $m->getCliente();
            $vto = strtotime((string)$r->vencimiento);
            $base = new DateTimeImmutable(date('Y-m-d', $vto ?: $hoy->getTimestamp()));
            $minimo = DiasHabiles::sumar($hoy, SepaXml::plazoMinimo($m->tipo, $m->secuencia, $acreedor->plazos()));
            $cobro = $fijo ?? DiasHabiles::siguienteHabil(max($base, $minimo));
            $ops[] = [
                'endtoend' => 'RC' . $r->idrecibo,
                'importe' => round((float)$r->importe, 2),
                'mandato' => $m->referencia,
                'fechafirma' => date('Y-m-d', strtotime($m->fechafirma)),
                'tipo' => $m->tipo,
                'secuencia' => $m->secuencia,
                'fechacobro' => $cobro->format('Y-m-d'),
                'deudor' => $cliente->razonsocial ?: $cliente->nombre,
                'iban' => $m->iban,
                'bic' => $m->bic,
                'concepto' => trim('Factura ' . $r->codigofactura . ' vto ' . $r->numero),
            ];
            $lineas[] = [$r, $m];
        }
        if (count($lineas) + count($errores) < count($idrecibos) && !$errores) {
            $errores[] = 'sepa-receipt-not-found';
        }
        if ($errores || !$ops) {
            return [null, $errores ?: ['sepa-no-operations']];
        }

        $codigo = SepaTexto::id('REM' . $ahora->format('ymdHis') . $idempresa . strtoupper(bin2hex(random_bytes(2))));
        try {
            $xml = SepaXml::generar($acreedor->paraXml(), $ops, $codigo, $ahora, $acreedor->plazos());
        } catch (\InvalidArgumentException $e) {
            return [null, explode(' | ', $e->getMessage())];
        }
        $fallos = SepaXml::validarXml($xml);
        if ($fallos) {
            return [null, $fallos];
        }

        $total = 0.0;
        foreach ($ops as $o) {
            $total += $o['importe'];
        }

        $db = new DataBase();
        $db->beginTransaction();
        try {
            $remesa = new RemesaSepa();
            $remesa->idempresa = $idempresa;
            $remesa->codigo = $codigo;
            $remesa->nick = $nick;
            $remesa->numrecibos = count($ops);
            $remesa->total = round($total, 2);
            $remesa->xml = $xml;
            if (false === $remesa->save()) {
                throw new \RuntimeException('remesa');
            }
            foreach ($lineas as $i => [$r, $m]) {
                $l = new RemesaSepaRecibo();
                $l->idremesa = $remesa->idremesa;
                $l->idrecibo = $r->idrecibo;
                $l->idmandato = $m->idmandato;
                $l->codcliente = $r->codcliente;
                $l->importe = round((float)$r->importe, 2);
                $l->fechacobro = $ops[$i]['fechacobro'];
                $l->secuencia = $m->secuencia;
                if (false === $l->save()) {
                    throw new \RuntimeException('linea');
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            return [null, ['sepa-save-error']];
        }
        return [$remesa, []];
    }

    /** Anula una remesa sin resultados: libera sus recibos para volver a remesarlos. */
    public static function anular(RemesaSepa $remesa): bool
    {
        if ($remesa->estado !== 'generada'
            || RemesaSepaRecibo::count([Where::eq('idremesa', $remesa->idremesa), Where::notEq('estado', 'remesado')]) > 0) {
            return false;
        }
        RemesaSepaRecibo::deleteWhere([Where::eq('idremesa', $remesa->idremesa)]);
        $remesa->estado = 'anulada';
        return $remesa->save();
    }

    /** Exportación CSV de una remesa (sin IBAN completo). */
    public static function csv(RemesaSepa $remesa): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['remesa', 'recibo', 'cliente', 'importe', 'fecha_cobro', 'secuencia', 'estado', 'motivo', 'fecha_resolucion'], ';');
        foreach (RemesaSepaRecibo::all([Where::eq('idremesa', $remesa->idremesa)], ['id' => 'ASC']) as $l) {
            fputcsv($f, [$remesa->codigo, $l->idrecibo, $l->codcliente, number_format((float)$l->importe, 2, '.', ''), $l->fechacobro,
                $l->secuencia, $l->estado, $l->motivo, $l->fecharesolucion], ';');
        }
        rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);
        return (string)$csv;
    }
}
