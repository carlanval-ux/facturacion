<?php
/**
 * RecordatorioImpagosFacturalo - Motor de reclamación de recibos pendientes.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\ImpagoBaja;
use FacturaScripts\Dinamic\Model\ImpagoRecordatorio;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use Throwable;

class Recordatorios
{
    const GRUPO = 'recordatorioimpagos';
    const MODULO = 'impagos';
    /** Intentos por paso y recibo antes de darlo por perdido */
    const MAX_INTENTOS = 3;

    public static function cfg(string $clave, $defecto = '')
    {
        return Tools::settings(self::GRUPO, $clave, $defecto);
    }

    /** Pasos activos: paso => desplazamiento en días. */
    public static function offsets(bool $todos = false): array
    {
        $out = [];
        foreach (Pasos::PASOS as $paso) {
            if ($todos || (bool)self::cfg($paso . '_activo', false)) {
                $out[$paso] = Pasos::desplazamiento($paso, (int)self::cfg($paso . '_dias', 0));
            }
        }
        return $out;
    }

    /** Pasada del cron. Devuelve el número de correos enviados. */
    public static function run(): int
    {
        if (false === ModuloActivo::estaActivo(self::MODULO) || false === (bool)self::cfg('activo', false)) {
            return 0;
        }

        $offsets = self::offsets();
        if (empty($offsets)) {
            return 0;
        }

        if (false === Pasos::enHorario((int)date('G'), (int)date('N'), (int)self::cfg('hora_ini', 9),
                (int)self::cfg('hora_fin', 19), (bool)self::cfg('laborables', true))) {
            return 0;
        }

        if (false === NewMail::create()->canSendMail()) {
            Tools::log()->warning('RecordatorioImpagos: el envío de emails no está configurado.');
            return 0;
        }
        if (false !== stripos(Tools::siteUrl(), 'localhost')) {
            Tools::log()->warning('RecordatorioImpagos: la URL del sitio no está definida; sin ella no se puede incluir el enlace de baja.');
            return 0;
        }

        $desde = strtotime((string)self::cfg('desde', '')) ?: strtotime('today');
        $maxAntes = isset($offsets['previo']) ? abs($offsets['previo']) : 0;
        $hasta = strtotime('+' . $maxAntes . ' days');

        $recibos = ReciboCliente::all([
            Where::eq('pagado', false),
            Where::gte('vencimiento', date('Y-m-d', $desde)),
            Where::lte('vencimiento', date('Y-m-d', $hasta)),
        ], ['vencimiento' => 'ASC'], 0, 500);

        $max = max(1, (int)self::cfg('max_por_pasada', 50));
        $enviados = 0;
        foreach ($recibos as $recibo) {
            if ($enviados >= $max) {
                break;
            }
            if (self::procesar($recibo, $offsets)) {
                $enviados++;
            }
        }
        return $enviados;
    }

    /** Evalúa un recibo en el cron. true si se ha enviado un correo. */
    public static function procesar(ReciboCliente $recibo, array $offsets): bool
    {
        $ctx = [];
        $motivo = self::elegibilidad($recibo, $ctx);
        if ('' !== $motivo && 'sin-email' !== $motivo) {
            return false;
        }

        $filas = [];
        foreach (ImpagoRecordatorio::all([Where::eq('idrecibo', $recibo->idrecibo)]) as $f) {
            if (false === empty($f->clave)) {
                $filas[$f->paso] = $f;
            }
        }
        $hechos = [];
        foreach ($filas as $paso => $f) {
            if ($f->resultado !== 'error' || $f->intentos >= self::MAX_INTENTOS) {
                $hechos[] = $paso;
            }
        }

        $venc = self::iso($recibo->vencimiento);
        if (null === $venc) {
            return false;
        }
        $decision = Pasos::decidir($offsets, $hechos, $venc, date('Y-m-d'));

        foreach ($decision['omitir'] as $paso) {
            self::registrar($recibo, $ctx, $paso, 'omitido', '', 'cron', '', true);
        }
        if (null === $decision['enviar']) {
            return false;
        }

        $paso = $decision['enviar'];
        if ('sin-email' === $motivo) {
            self::registrar($recibo, $ctx, $paso, 'sin-email', '', 'cron', '', true);
            return false;
        }

        return self::enviar($recibo, $ctx, $paso, $filas[$paso] ?? null, 'cron', '');
    }

    /**
     * Botón «enviar recordatorio ahora». Devuelve '' si se envió o la clave de traducción del motivo.
     */
    public static function enviarAhora(ReciboCliente $recibo, string $nick): string
    {
        if (false === ModuloActivo::estaActivo(self::MODULO)) {
            return 'impagos-module-inactive';
        }
        if (false === NewMail::create()->canSendMail()) {
            return 'impagos-email-not-configured';
        }

        $ctx = [];
        $motivo = self::elegibilidad($recibo, $ctx);
        if ('' !== $motivo) {
            return $motivo;
        }

        $venc = self::iso($recibo->vencimiento);
        $paso = Pasos::pasoActual(self::offsets() ?: self::offsets(true), $venc ?? date('Y-m-d'), date('Y-m-d'));
        return self::enviar($recibo, $ctx, $paso, null, 'manual', $nick) ? '' : 'impagos-send-failed';
    }

    /**
     * Comprueba que el recibo se puede reclamar. '' = sí; si no, clave de traducción del motivo.
     * Rellena $ctx con factura, cliente y email.
     */
    public static function elegibilidad(ReciboCliente $recibo, array &$ctx): string
    {
        if ($recibo->pagado || $recibo->importe - $recibo->liquidado <= 0.004) {
            return 'impagos-not-pending';
        }

        $factura = new FacturaCliente();
        if (empty($recibo->idfactura) || false === $factura->load($recibo->idfactura)) {
            return 'impagos-no-invoice';
        }
        // se detiene si la factura es una rectificativa o ya tiene abonos/rectificativas
        if ($factura->isRefund() || false === empty($factura->getRefunds())) {
            return 'impagos-refunded';
        }
        $ejercicio = new Ejercicio();
        if (false === $ejercicio->load($factura->codejercicio)) {
            return 'impagos-no-exercise';
        }

        $cod = mb_strtoupper((string)$recibo->codcliente);
        $baja = new ImpagoBaja();
        if (in_array($cod, Pasos::lista((string)self::cfg('excl_clientes', '')), true)
            || in_array(mb_strtoupper((string)$factura->codigo), Pasos::lista((string)self::cfg('excl_facturas', '')), true)
            || $baja->loadWhere([Where::eq('codcliente', $recibo->codcliente)])) {
            return 'impagos-excluded';
        }

        $cliente = $recibo->getSubject();
        $ctx = ['factura' => $factura, 'cliente' => $cliente, 'email' => trim((string)$cliente->email)];
        if ('' === $ctx['email'] || false === filter_var($ctx['email'], FILTER_VALIDATE_EMAIL)) {
            return 'sin-email';
        }
        return '';
    }

    /** Envía el correo de un paso. true si salió. */
    protected static function enviar(ReciboCliente $recibo, array $ctx, string $paso, ?ImpagoRecordatorio $fila, string $origen, string $nick): bool
    {
        $manual = $origen === 'manual';
        $log = $fila ?? new ImpagoRecordatorio();
        $log->idrecibo = $recibo->idrecibo;
        $log->idfactura = $recibo->idfactura;
        $log->codigofactura = $recibo->codigofactura;
        $log->codcliente = $recibo->codcliente;
        $log->paso = $paso;
        $log->clave = $manual ? null : $recibo->idrecibo . '-' . $paso;
        $log->email = $ctx['email'];
        $log->origen = $origen;
        $log->nick = $nick;
        $log->intentos = (int)$log->intentos + 1;
        $log->resultado = 'enviando';
        $log->fecha = Tools::dateTime();
        $log->mensaje = '';
        // reserva antes de enviar: la clave única impide que dos procesos manden lo mismo
        if (false === $log->save()) {
            return false;
        }

        $problema = '';
        $pdf = '';
        try {
            $mail = self::componer($recibo, $ctx, $paso);
            if ((bool)self::cfg('adjuntar_pdf', true)) {
                $pdf = self::exportarPdf($ctx['factura']);
                if ('' !== $pdf) {
                    $mail->addAttachment($pdf, 'factura-' . $ctx['factura']->codigo . '.pdf');
                } else {
                    $log->mensaje = 'PDF no adjuntado';
                }
            }
            if (false === $mail->send()) {
                $problema = 'No se pudo enviar el correo (ver registro de emails).';
            }
        } catch (Throwable $e) {
            $problema = $e->getMessage();
        }
        if ('' !== $pdf && file_exists($pdf)) {
            unlink($pdf);
        }

        $log->fecha = Tools::dateTime();
        $log->resultado = '' === $problema ? 'enviado' : 'error';
        if ('' !== $problema) {
            $log->mensaje = $problema;
        }
        $log->save();
        return '' === $problema;
    }

    public static function componer(ReciboCliente $recibo, array $ctx, string $paso): NewMail
    {
        $cliente = $ctx['cliente'];
        $empresa = Empresas::get($ctx['factura']->idempresa) ?: Empresas::default();
        $enlace = self::urlBaja((string)$recibo->codcliente);
        $vars = [
            'cliente' => $cliente->razonsocial ?: $cliente->nombre,
            'numero' => $recibo->codigofactura,
            'importe' => Tools::money($recibo->importe - $recibo->liquidado, $recibo->coddivisa),
            'vencimiento' => $recibo->vencimiento,
            'enlace' => $enlace,
            'empresa' => $empresa->nombrecorto ?: $empresa->nombre,
        ];

        $asunto = Pasos::render((string)self::cfg($paso . '_asunto', ''), $vars, false);
        $cuerpo = Pasos::render((string)self::cfg($paso . '_cuerpo', ''), $vars, true);
        $pie = Tools::lang()->trans('impagos-footer', [
            '%company%' => $empresa->nombre,
            '%cifnif%' => $empresa->cifnif,
        ]);
        $baja = Tools::lang()->trans('impagos-unsubscribe');
        $pie = htmlspecialchars($pie, ENT_QUOTES, 'UTF-8')
            . ' <a href="' . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($baja, ENT_QUOTES, 'UTF-8') . '</a>';

        $mail = NewMail::create()->to($ctx['email'], (string)$vars['cliente']);
        $mail->title = $asunto;
        $mail->text = $cuerpo . '<br/><br/><small style="color:#6b7280">' . $pie . '</small>';

        $nombre = trim((string)self::cfg('remitente_nombre', ''));
        if ('' !== $nombre) {
            $mail->fromName = $nombre;
        }
        $replyTo = trim((string)self::cfg('remitente_email', ''));
        if ('' !== $replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($replyTo, $nombre);
        }
        return $mail;
    }

    public static function urlBaja(string $codcliente): string
    {
        return rtrim(Tools::siteUrl(), '/') . '/RecordatorioBaja?c=' . rawurlencode($codcliente)
            . '&t=' . Pasos::token($codcliente, self::secreto());
    }

    public static function secreto(): string
    {
        $s = (string)self::cfg('secreto', '');
        if ('' === $s) {
            $s = Tools::randomString(40);
            Tools::settingsSet(self::GRUPO, 'secreto', $s);
            Tools::settingsSave();
        }
        return $s;
    }

    /** Fila de historial sin envío (omitido / sin-email). */
    protected static function registrar(ReciboCliente $recibo, array $ctx, string $paso, string $resultado, string $mensaje, string $origen, string $nick, bool $conClave): void
    {
        $log = new ImpagoRecordatorio();
        $log->idrecibo = $recibo->idrecibo;
        $log->idfactura = $recibo->idfactura;
        $log->codigofactura = $recibo->codigofactura;
        $log->codcliente = $recibo->codcliente;
        $log->paso = $paso;
        $log->clave = $conClave ? $recibo->idrecibo . '-' . $paso : null;
        $log->email = $ctx['email'] ?? '';
        $log->fecha = Tools::dateTime();
        $log->resultado = $resultado;
        $log->origen = $origen;
        $log->nick = $nick;
        $log->mensaje = $mensaje;
        $log->save();
    }

    /** Fecha del core (d-m-Y) a Y-m-d; null si no es válida. */
    protected static function iso(?string $fecha): ?string
    {
        $t = $fecha ? strtotime($fecha) : false;
        return false === $t ? null : date('Y-m-d', $t);
    }

    /** PDF temporal de la factura con el formato por defecto; '' si falla. */
    protected static function exportarPdf(FacturaCliente $factura): string
    {
        $carpeta = FS_FOLDER . '/MyFiles/Tmp/RecordatorioImpagos';
        if (false === is_dir($carpeta) && false === @mkdir($carpeta, 0777, true) && false === is_dir($carpeta)) {
            return '';
        }
        // rospdf escribe su caché de fuentes aquí sin crearla
        $cache = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($cache) && false === @mkdir($cache, 0777, true) && false === is_dir($cache)) {
            return '';
        }

        $export = new ExportManager();
        $export->newDoc('PDF', $factura->codigo);
        $export->addBusinessDocPage($factura); // devuelve false por diseño; el PDF sale de getDoc()
        $doc = $export->getDoc();
        if (empty($doc)) {
            return '';
        }

        $ruta = $carpeta . '/factura-' . $factura->idfactura . '-' . uniqid() . '.pdf';
        return false === file_put_contents($ruta, $doc) ? '' : $ruta;
    }
}
