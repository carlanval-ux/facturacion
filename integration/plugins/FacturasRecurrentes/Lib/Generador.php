<?php
namespace FacturaScripts\Plugins\FacturasRecurrentes\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\Recurrente;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\RecurrenteHistorial;
use FacturaScripts\Plugins\FacturasRecurrentes\Model\RecurrenteLinea;

/**
 * Genera los documentos de las plantillas. Idempotente: cada (plantilla, fecha prevista) tiene una fila
 * única en el historial (clave UNIQUE) que se reserva antes de crear nada.
 */
final class Generador
{
    public const MAX_INTENTOS = 5;
    public const MAX_POR_PASADA = 12;
    private const MODELOS = [
        'venta' => ['factura' => 'FacturaCliente', 'albaran' => 'AlbaranCliente', 'pedido' => 'PedidoCliente', 'presupuesto' => 'PresupuestoCliente'],
        'compra' => ['factura' => 'FacturaProveedor', 'albaran' => 'AlbaranProveedor', 'pedido' => 'PedidoProveedor', 'presupuesto' => 'PresupuestoProveedor'],
    ];

    /** Pasada del cron: genera lo vencido hasta hoy. Devuelve cuántos documentos se crearon. */
    public static function procesar(?string $hoy = null): int
    {
        $hoy = $hoy ?? date('Y-m-d');
        $n = 0;
        $where = [
            Where::eq('activo', true),
            Where::eq('esgrupo', false),
            Where::notEq('unidad', 'manual'),
            Where::lte('proxima', $hoy),
        ];
        foreach (Recurrente::all($where, ['proxima' => 'ASC'], 0, 0) as $r) {
            $n += self::procesarPlantilla($r, $hoy);
        }
        return $n;
    }

    public static function procesarPlantilla(Recurrente $r, string $hoy): int
    {
        $n = 0;
        $inicio = Recurrencia::iso($r->fecha_inicio);
        for ($i = 0; $i < self::MAX_POR_PASADA; $i++) {
            $prevista = Recurrencia::iso($r->proxima);
            $fin = Recurrencia::iso($r->fecha_fin);
            if (!$r->activo || !$prevista || $prevista > $hoy) {
                break;
            }
            if ($fin && $prevista > $fin) {
                $r->activo = false;
                $r->save();
                break;
            }
            $g = $r->config();
            if ($g !== $r && !$g->activo) {
                break; // grupo en pausa
            }
            $res = self::generar($r, $hoy, $prevista, $r->idrecurrente . '|' . $prevista);
            if ($res === 'ok') {
                $n++;
            } elseif ($res !== 'hecho') {
                break; // ocupado o error: se reintenta en la siguiente pasada
            }
            $r->proxima = Recurrencia::fmt(Recurrencia::siguiente($prevista, (int)$r->cada, $r->unidad, $inicio));
            $r->save();
        }
        return $n;
    }

    /** Genera ahora un documento extra (botón); no mueve la próxima fecha. */
    public static function generarAhora(Recurrente $r): string
    {
        $hoy = date('Y-m-d');
        return self::generar($r, $hoy, $hoy, $r->idrecurrente . '|m|' . uniqid(), true);
    }

    /** @return string ok|hecho|ocupado|error */
    public static function generar(Recurrente $r, string $hoy, string $prevista, string $clave, bool $manual = false): string
    {
        $h = new RecurrenteHistorial();
        if ($h->loadWhereEq('clave', $clave)) {
            if ($h->estado === 'ok') {
                return 'hecho';
            }
            if ($h->estado === 'procesando' && strtotime($h->actualizado) > time() - 600) {
                return 'ocupado';
            }
            // fecha exacta rechazada hoy: no se insiste hasta mañana (entonces se ajusta la fecha)
            if ($h->estado === 'error' && !$manual && $r->fecha_pasada === 'prevista' && date('Y-m-d', strtotime($h->actualizado)) >= $hoy) {
                return 'ocupado';
            }
            $reintentoAjustado = $h->estado === 'error' && $r->fecha_pasada === 'prevista';
        } else {
            $h->idrecurrente = $r->idrecurrente;
            $h->clave = $clave;
            $h->fecha_prevista = Recurrencia::fmt($prevista);
            $h->intentos = 0;
        }
        $h->estado = 'procesando';
        $h->intentos = (int)$h->intentos + 1;
        $h->mensaje = null;
        $h->actualizado = Tools::dateTime();
        // shortcut: sin bloqueo atómico en reintentos; el job del cron ya impide pasadas simultáneas
        if (false === $h->save()) {
            return 'ocupado';
        }

        $db = new DataBase();
        $db->beginTransaction();
        $avisos = count(Tools::log()::read('', ['critical', 'error', 'warning']));
        try {
            $doc = self::crearDocumento($r, $hoy, $prevista, $manual, $reintentoAjustado ?? false);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            $h->estado = 'error';
            // motivo real: los avisos que el núcleo dejó en el log durante este intento
            $motivos = array_column(array_slice(Tools::log()::read('', ['critical', 'error', 'warning']), $avisos), 'message');
            $h->mensaje = trim(implode(' | ', array_unique($motivos)) ?: $e->getMessage());
            $h->actualizado = Tools::dateTime();
            $h->save();
            // tras demasiados fallos se pausa la plantilla para no insistir
            if ($h->intentos >= self::MAX_INTENTOS && !$manual) {
                $r->activo = false;
                $r->save();
            }
            return 'error';
        }

        $h->estado = 'ok';
        $h->tipodoc = $r->config()->tipodoc;
        $h->iddoc = $doc->id();
        $h->codigo = $doc->codigo;
        $h->fecha_doc = $doc->fecha;
        $h->envio = self::enviar($r, $doc);
        $h->actualizado = Tools::dateTime();
        $h->save();
        return 'ok';
    }

    /** Última fecha (Y-m-d) de factura en la misma serie y empresa dentro del año de $fecha. */
    private static function ultimaFecha($doc, string $fecha): ?string
    {
        $anio = substr($fecha, 0, 4);
        $ult = $doc::all([
            Where::eq('codserie', $doc->codserie),
            Where::eq('idempresa', $doc->idempresa),
            Where::gte('fecha', $anio . '-01-01'),
            Where::lte('fecha', $anio . '-12-31'),
        ], ['fecha' => 'DESC'], 0, 1);
        return empty($ult) ? null : Recurrencia::iso($ult[0]->fecha);
    }

    private static function crearDocumento(Recurrente $r, string $hoy, string $prevista, bool $manual, bool $ajustar = false)
    {
        $cfg = $r->config();
        $clase = '\\FacturaScripts\\Dinamic\\Model\\' . self::MODELOS[$cfg->tipo][$cfg->tipodoc];
        $doc = new $clase();

        $sujeto = $cfg->tipo === 'venta' ? new Cliente() : new Proveedor();
        if (false === $sujeto->load($cfg->tipo === 'venta' ? $r->codcliente : $r->codproveedor)) {
            throw new \RuntimeException(Tools::trans('recurring-subject-not-found'));
        }
        if (false === $doc->setSubject($sujeto)) {
            throw new \RuntimeException(Tools::trans('error-assigning-subject'));
        }
        if (!empty($cfg->codserie)) {
            $doc->codserie = $cfg->codserie;
        }
        if (!empty($cfg->codpago)) {
            $doc->codpago = $cfg->codpago;
        }
        $doc->observaciones = $cfg->observaciones;

        // protección de fecha: nunca posterior a hoy
        $opcion = $ajustar ? 'ajustada' : (string)$r->fecha_pasada;
        $fecha = $manual ? $hoy : Recurrencia::fechaDocumento($prevista, $hoy, $opcion);
        // el núcleo exige facturas en orden de fecha por serie y ejercicio: se sube a la última emitida (sin pasar de hoy)
        if (!$manual && $opcion === 'ajustada' && $cfg->tipodoc === 'factura') {
            $ultima = self::ultimaFecha($doc, $fecha);
            if ($ultima && $ultima > $fecha) {
                $fecha = min($ultima, $hoy);
            }
        }
        if (false === $doc->setDate(Recurrencia::fmt($fecha), date('H:i:s'))) {
            throw new \RuntimeException(Tools::trans('recurring-date-error', ['%date%' => $fecha]));
        }
        if (false === $doc->save()) {
            throw new \RuntimeException(Tools::trans('record-save-error'));
        }

        // prorrateo: solo en el primer documento generado
        $pct = 100.0;
        if (!$manual && (float)$r->pct_primero < 100
            && 0 === RecurrenteHistorial::count([Where::eq('idrecurrente', $r->idrecurrente), Where::eq('estado', 'ok')])) {
            $pct = (float)$r->pct_primero;
        }

        $lineas = [];
        $origen = !empty($r->idgrupo) ? $r->idgrupo : $r->idrecurrente;
        foreach (RecurrenteLinea::all([Where::eq('idrecurrente', $origen)], ['idlinea' => 'ASC'], 0, 0) as $l) {
            $nueva = empty($l->referencia) ? $doc->getNewLine() : $doc->getNewProductLine($l->referencia);
            $nueva->cantidad = (float)$l->cantidad;
            $nueva->descripcion = $l->descripcion ?: $nueva->descripcion;
            $nueva->pvpunitario = (float)$l->pvpunitario * $pct / 100;
            $nueva->dtopor = (float)$l->dtopor;
            if (!empty($l->codimpuesto) && $l->codimpuesto !== $nueva->codimpuesto) {
                $nueva->setTax($l->codimpuesto);
            }
            $lineas[] = $nueva;
        }
        if (empty($lineas)) {
            throw new \RuntimeException(Tools::trans('recurring-no-lines'));
        }
        if (false === Calculator::calculate($doc, $lineas, true)) {
            throw new \RuntimeException(Tools::trans('error-calculating-totals'));
        }
        return $doc;
    }

    /** Envío opcional al cliente con el PDF. Si AutoEnvioFacturas está activo, no se duplica. */
    private static function enviar(Recurrente $r, $doc): string
    {
        if (!$r->enviar_email || $r->config()->tipo !== 'venta') {
            return '';
        }
        if (Plugins::isEnabled('AutoEnvioFacturas')) {
            return 'AutoEnvioFacturas';
        }
        try {
            $cliente = new Cliente();
            $cliente->load($doc->codcliente);
            $mail = NewMail::create();
            if (empty($cliente->email) || false === $mail->canSendMail()) {
                return 'sin-email';
            }
            $export = new ExportManager();
            $export->newDoc('PDF', $doc->codigo, 0, (string)($cliente->langcode ?? ''));
            $export->addBusinessDocPage($doc);
            Tools::folderCheckOrCreate(NewMail::ATTACHMENTS_TMP_PATH);
            $nombre = $doc->codigo . '.pdf';
            $ruta = FS_FOLDER . '/' . NewMail::ATTACHMENTS_TMP_PATH . 'rec_' . uniqid() . '.pdf';
            file_put_contents($ruta, $export->getDoc());
            $mail->to($cliente->email, $cliente->razonsocial)
                ->subject(Tools::trans('recurring-mail-subject', ['%code%' => $doc->codigo]))
                ->body(Tools::trans('recurring-mail-body', ['%code%' => $doc->codigo]))
                ->addAttachment($ruta, $nombre);
            $ok = $mail->send();
            @unlink($ruta);
            return $ok ? 'enviado' : 'fallo-envio';
        } catch (\Throwable $e) {
            return 'fallo-envio';
        }
    }
}
