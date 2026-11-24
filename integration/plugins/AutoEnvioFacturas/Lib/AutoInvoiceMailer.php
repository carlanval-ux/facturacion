<?php
/**
 * AutoEnvioFacturas - Envía automáticamente por email las facturas emitidas (compatible con Verifactu). Nunca envía bocetos y, en ejercicios en modo Verifactu, solo envía facturas ya registradas en Verifactu.
 *
 * Copyright (C) 2026 TI53, SLU - Antonio Martín Buitrago Laguna <antoniomartin@ti53.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @author    Antonio Martín Buitrago Laguna <antoniomartin@ti53.es>
 * @copyright 2026 TI53, SLU
 * @license   LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\AutoEnvioFacturas\Lib;

use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\AutoEnvioFacturas\Model\EnvioFacturaEmail;
use Throwable;

class AutoInvoiceMailer
{
    /** Máximo de reintentos por factura antes de abandonar y avisar */
    const MAX_ATTEMPTS = 3;

    /** Nombre de la notificación de email (editable desde Administrador → Emails) */
    const NOTIFICATION = 'autoenviofacturas-factura';

    /** Canal propio en Administrador → Logs (el canal por defecto solo guarda errores) */
    const LOG_CHANNEL = 'autoenviofacturas';

    /** Un mismo aviso repetido se anota como mucho una vez por este intervalo (Cache::EXPIRATION, 1 h) */
    const LOG_ONCE_PREFIX = 'autoenviofacturas-log-';

    /** Facturas que se piden a la base de datos en cada página de candidatas */
    const PAGE_SIZE = 100;

    /** Máximo de candidatas revisadas por pasada, para acotar el trabajo de cada minuto */
    const MAX_SCAN = 500;

    public static function run(): void
    {
        // ¿está activado?
        if (false === (bool)Tools::settings('autoenviofacturas', 'enabled', false)) {
            return;
        }

        // Cerrojo propio: withoutOverlapping() del core consulta la tabla de
        // trabajos y luego la marca, así que dos crones lanzados en el mismo
        // instante pueden pasar los dos y enviar dos veces la misma factura.
        // flock() es atómico: si otra pasada está en marcha, esta no hace nada.
        $lock = static::acquireLock();
        if (false === $lock) {
            return;
        }

        try {
            static::runLocked();
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Devuelve el cerrojo (recurso), null si no se puede crear el fichero
     * (se sigue sin cerrojo, como en la 1.1) o false si otra pasada lo tiene.
     *
     * @return resource|false|null
     */
    protected static function acquireLock()
    {
        $folder = FS_FOLDER . '/MyFiles/Tmp';
        if (false === is_dir($folder)) {
            @mkdir($folder, 0777, true);
        }

        $handle = @fopen($folder . '/autoenviofacturas.lock', 'c');
        if (false === $handle) {
            return null;
        }

        if (false === flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        return $handle;
    }

    protected static function runLocked(): void
    {

        // ¿está configurado el email en FacturaScripts?
        if (false === NewMail::create()->canSendMail()) {
            static::logOnce('warning', 'AutoEnvioFacturas: el envío de emails no está configurado en Administrador → Emails.');
            return;
        }

        // ¿la notificación existe y está activa? Es un problema de configuración
        // global: no tocamos ninguna factura ni gastamos sus intentos.
        $problem = static::notificationProblem();
        if ('' !== $problem) {
            static::logOnce('warning', 'AutoEnvioFacturas: ' . $problem);
            return;
        }

        $limit = max(1, (int)Tools::settings('autoenviofacturas', 'maxemails', 25));

        // Dos fases, para que las facturas bloqueadas no tapen a las demás:
        // 1) las que pueden salir (nuevas, programadas, en error, reenvíos...);
        // 2) con el cupo que sobre, se reevalúan las bloqueadas (sin email o
        //    esperando a Verifactu), por si ya se han resuelto.
        // Las que agotaron sus reintentos no se miran hasta que se pulse Repetir.
        $exhausted = static::invoiceIdsWhere([
            Where::eq('resultado', 'error'),
            Where::gte('intentos', self::MAX_ATTEMPTS),
        ]);
        $blocked = static::invoiceIdsWhere([Where::in('resultado', ['sin-email', 'esperando-verifactu'])]);

        $sent = static::sendBatch(array_merge($exhausted, $blocked), [], $limit);
        if ($sent < $limit && false === empty($blocked)) {
            $sent += static::sendBatch([], $blocked, $limit - $sent);
        }

        if ($sent > 0) {
            static::log()->notice('AutoEnvioFacturas: ' . $sent . ' factura(s) enviadas por email.');
        }
    }

    /** Logger del canal propio del plugin. */
    public static function log()
    {
        return Tools::log(self::LOG_CHANNEL);
    }

    /**
     * Anota un aviso en el canal propio como mucho una vez por hora: el cron
     * pasa cada minuto y un problema de configuración llenaría los Logs.
     */
    public static function logOnce(string $level, string $message): void
    {
        $key = self::LOG_ONCE_PREFIX . md5($level . '|' . $message);
        if (Cache::get($key)) {
            return;
        }

        Cache::set($key, true);
        static::log()->{$level}($message);
    }

    /**
     * Envía hasta $limit facturas candidatas, recorriéndolas por páginas
     * (como mucho MAX_SCAN revisadas). Devuelve cuántas se han enviado.
     *
     * @param int[] $excludeIds facturas que no se miran
     * @param int[] $onlyIds    si no está vacío, solo se miran estas facturas
     */
    protected static function sendBatch(array $excludeIds, array $onlyIds, int $limit): int
    {
        $sent = 0;
        $scanned = 0;
        $offset = 0;
        while ($sent < $limit && $scanned < self::MAX_SCAN) {
            $candidates = static::getCandidates($offset, self::PAGE_SIZE, $excludeIds, $onlyIds);
            $sentInPage = 0;
            foreach ($candidates as $invoice) {
                $scanned++;
                if (static::sendInvoice($invoice)) {
                    $sent++;
                    $sentInPage++;
                }
                if ($sent >= $limit || $scanned >= self::MAX_SCAN) {
                    break;
                }
            }
            if (count($candidates) < self::PAGE_SIZE) {
                break;
            }

            // las enviadas salen de la consulta (femail ya no es NULL): la
            // siguiente página avanza solo lo que se ha quedado sin enviar
            $offset += self::PAGE_SIZE - $sentInPage;
        }

        return $sent;
    }

    /**
     * Facturas candidatas: estado no editable (emitida...), nunca boceto ni anulada,
     * sin email enviado (femail vacío) y no anteriores a la fecha configurada.
     *
     * @param int[] $excludeIds
     * @param int[] $onlyIds
     *
     * @return FacturaCliente[]
     */
    protected static function getCandidates(int $offset, int $limit, array $excludeIds = [], array $onlyIds = []): array
    {
        // estados no editables de factura de cliente, excluyendo anuladas
        $idestados = [];
        $whereStatus = [
            Where::eq('tipodoc', 'FacturaCliente'),
            Where::eq('editable', false),
        ];
        foreach (EstadoDocumento::all($whereStatus) as $status) {
            if (false !== stripos($status->nombre, 'anulad')) {
                continue;
            }
            $idestados[] = $status->idestado;
        }
        if (empty($idestados)) {
            return [];
        }

        $where = [
            Where::isNull('femail'),
            Where::in('idestado', $idestados),
            Where::gte('fecha', Tools::settings('autoenviofacturas', 'fromdate', Tools::date())),
        ];
        if (false === empty($excludeIds)) {
            $where[] = Where::notIn('idfactura', $excludeIds);
        }
        if (false === empty($onlyIds)) {
            $where[] = Where::in('idfactura', $onlyIds);
        }

        return FacturaCliente::all($where, ['fecha' => 'ASC', 'idfactura' => 'ASC'], $offset, $limit);
    }

    /**
     * Facturas del historial que cumplen la condición.
     *
     * @return int[]
     */
    protected static function invoiceIdsWhere(array $where): array
    {
        $ids = [];
        foreach (EnvioFacturaEmail::all($where, [], 0, 0) as $log) {
            $ids[] = (int)$log->idfactura;
        }
        return $ids;
    }

    protected static function sendInvoice(FacturaCliente $invoice): bool
    {
        // ¿incluimos rectificativas?
        $includeRect = (bool)Tools::settings('autoenviofacturas', 'rectificativas', true);
        if (false === $includeRect && false === empty($invoice->idfacturarect)) {
            return false;
        }

        // log previo y control de reintentos
        $log = EnvioFacturaEmail::findWhere([Where::eq('idfactura', $invoice->idfactura)]);
        if ($log && $log->intentos >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (null === $log) {
            $log = new EnvioFacturaEmail();
            $log->idfactura = $invoice->idfactura;
            $log->codigo = $invoice->codigo;
        }

        // Blindaje contra el reenvío doble: si el historial dice "enviado" pero
        // la factura no tiene femail (no se pudo guardar tras el envío), NO se
        // vuelve a enviar. Solo se repara la marca. Para reenviar de verdad
        // está el botón Reenvío, que pasa la fila a "reenvio".
        if ($log->resultado === 'enviado') {
            static::markSent($invoice, $log->fecha);
            return false;
        }

        // puerta Verifactu: si el ejercicio lo requiere, la factura debe
        // estar ya registrada (QR generado) antes de poder enviarse
        if (false === VerifactuGate::canSend($invoice)) {
            // lo anotamos en el log una sola vez, para dar visibilidad
            if ($log->resultado !== 'esperando-verifactu') {
                $log->email = '';
                $log->fecha = Tools::dateTime();
                $log->resultado = 'esperando-verifactu';
                $log->mensaje = 'A la espera del registro Verifactu (QR) o anulada.';
                $log->save();
            }
            return false;
        }

        // email de destino
        $email = static::getEmail($invoice);
        if (empty($email)) {
            // lo anotamos en el log una sola vez; si el cliente añade email
            // más tarde, la factura se enviará en la siguiente pasada
            if ($log->resultado !== 'sin-email') {
                $log->email = '';
                $log->fecha = Tools::dateTime();
                $log->resultado = 'sin-email';
                $log->mensaje = 'El cliente no tiene email registrado.';
                $log->save();
            }
            return false;
        }

        // margen de gracia configurable antes del envío (minutos).
        // Cuenta desde que la factura está totalmente lista para enviarse:
        // emitida, puerta Verifactu superada (QR disponible) y con email.
        // Los reintentos tras un error y los reenvíos manuales no esperan el margen.
        $margin = max(0, (int)Tools::settings('autoenviofacturas', 'delayminutes', 1));
        if ($margin > 0 && false === in_array($log->resultado, ['error', 'reenvio'], true)) {
            if ($log->resultado !== 'programado') {
                $log->email = $email;
                $log->fecha = Tools::dateTime();
                $log->resultado = 'programado';
                $log->mensaje = 'Envío programado (margen de ' . $margin . ' min).';
                $log->save();
                return false;
            }
            if (strtotime($log->fecha) + ($margin * 60) > time()) {
                return false;
            }
        }

        // generamos el PDF
        $filePath = static::exportPdf($invoice);
        if (empty($filePath)) {
            // registramos el fallo (cuenta como intento) para que la factura
            // no se quede muda en "programado" reintentándolo cada pasada
            $log->intentos++;
            $log->email = $email;
            $log->fecha = Tools::dateTime();
            $log->resultado = 'error';
            $log->mensaje = 'No se pudo generar el PDF. Revise el formato de impresión y los logs.';
            $log->save();

            if ($log->intentos >= self::MAX_ATTEMPTS) {
                static::log()->error('AutoEnvioFacturas: agotados los reintentos (PDF) para la factura ' . $invoice->codigo);
            }
            return false;
        }

        // componemos y enviamos el email usando la notificación editable
        $mail = NewMail::create()
            ->to($email, $invoice->nombrecliente ?? '')
            ->addAttachment($filePath, 'factura-' . $invoice->codigo . '.pdf');

        $cco = trim((string)Tools::settings('autoenviofacturas', 'cco', ''));
        foreach (NewMail::splitEmails($cco) as $bcc) {
            $mail->bcc($bcc);
        }

        $problem = static::applyNotification($mail, InvoiceMailParams::build($invoice, $email));

        $done = false;
        if ('' === $problem) {
            $logBefore = static::logSnapshot();
            try {
                $done = $mail->send();
            } catch (Throwable $exc) {
                $problem = $exc->getMessage();
            }
            if (false === $done && '' === $problem) {
                $problem = static::newLogProblem($logBefore);
            }
        }

        // limpiamos el PDF temporal
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // registramos el resultado
        $log->intentos++;
        $log->email = $email;
        $log->fecha = Tools::dateTime();
        if ($done) {
            $log->resultado = 'enviado';
            $log->mensaje = '';
            $log->save();

            static::markSent($invoice, $log->fecha);
            return true;
        }

        $log->resultado = 'error';
        $problem = trim(preg_replace('/\s+/', ' ', $problem));
        $log->mensaje = static::shorten('Fallo al enviar el email: '
            . ('' !== $problem ? $problem : 'motivo desconocido, revise Administrador → Logs.'));
        $log->save();

        if ($log->intentos >= self::MAX_ATTEMPTS) {
            static::log()->error('AutoEnvioFacturas: agotados los reintentos para la factura ' . $invoice->codigo);
        }
        return false;
    }

    /**
     * Marca la factura como enviada (femail). femail es un campo desbloqueado:
     * se puede rellenar aunque la factura ya no sea editable. Si no se puede
     * guardar, se avisa (una vez por hora) y el historial, en "enviado",
     * impide que se vuelva a enviar.
     */
    protected static function markSent(FacturaCliente $invoice, ?string $when): void
    {
        $invoice->femail = empty($when) ? Tools::date() : Tools::date($when);
        if ($invoice->save()) {
            return;
        }

        static::logOnce('error', 'AutoEnvioFacturas: la factura ' . $invoice->codigo
            . ' se envió, pero no se pudo marcar como enviada. No se volverá a enviar sola;'
            . ' revise los avisos de la factura en Administrador → Logs.');
    }

    /**
     * Rellena asunto y cuerpo del email con la notificación editable.
     *
     * Sustituye a NewMail::sendNotification(), deprecado desde 2023.09 y
     * eliminado del core en la 2026.65. No usamos MailNotifier::send()
     * porque crea su propio NewMail y no admite CCO ni nombre de adjunto.
     */
    protected static function applyNotification(NewMail $mail, array $params): string
    {
        $problem = static::notificationProblem();
        if ('' !== $problem) {
            return $problem;
        }

        $notification = new EmailNotification();
        $notification->load(self::NOTIFICATION);

        if (false === isset($params['verificode'])) {
            $params['verificode'] = $mail->verificode;
        }

        $mail->title = MailNotifier::getText($notification->subject, $params);
        $mail->text = MailNotifier::getText($notification->body, $params);
        $mail->notification(self::NOTIFICATION);
        return '';
    }

    /**
     * Motivo por el que no se puede usar la notificación ('' si está bien).
     */
    public static function notificationProblem(): string
    {
        $notification = new EmailNotification();
        if (false === $notification->load(self::NOTIFICATION)) {
            return 'la notificación "' . self::NOTIFICATION . '" no existe. Desactive y vuelva a activar el plugin para recrearla.';
        }

        if (false === (bool)$notification->enabled) {
            return 'la notificación "' . self::NOTIFICATION . '" está desactivada. Actívela en Administrador → Emails → Notificaciones.';
        }

        return '';
    }

    /**
     * Pide reintentar un envío fallido: pone los intentos a cero y la
     * siguiente pasada del cron lo vuelve a enviar (sin esperar el margen).
     * Solo para filas en 'error'. Devuelve '' si se aplicó, o el motivo.
     */
    public static function requestRetry(EnvioFacturaEmail $log, string $nick): string
    {
        if ($log->resultado !== 'error') {
            return 'solo se puede reintentar un envío con resultado "error"';
        }

        $previous = (string)$log->mensaje;
        $log->intentos = 0;
        $log->fecha = Tools::dateTime();
        $log->mensaje = static::shorten('Reintento solicitado por ' . $nick . '. Último error: ' . $previous);
        return $log->save() ? '' : 'no se pudo guardar el historial';
    }

    /**
     * Pide reenviar una factura ya enviada: vacía su femail y la siguiente
     * pasada del cron la vuelve a enviar (sin esperar el margen; la puerta
     * Verifactu sí se vuelve a comprobar). Solo para filas en 'enviado'.
     * Devuelve '' si se aplicó, o el motivo.
     */
    public static function requestResend(EnvioFacturaEmail $log, string $nick): string
    {
        if ($log->resultado !== 'enviado') {
            return 'solo se puede reenviar una factura con resultado "enviado"';
        }

        $invoice = new FacturaCliente();
        if (false === $invoice->load($log->idfactura)) {
            return 'la factura ya no existe';
        }

        // el cron no mira facturas anteriores a la fecha configurada
        $fromDate = Tools::settings('autoenviofacturas', 'fromdate', Tools::date());
        if (strtotime($invoice->fecha) < strtotime($fromDate)) {
            return 'la factura es anterior a la fecha "No enviar facturas anteriores a" de los ajustes';
        }

        // femail es un campo desbloqueado: se puede vaciar aunque la
        // factura no sea editable
        $invoice->femail = null;
        if (false === $invoice->save()) {
            return 'no se pudo modificar la factura';
        }

        $log->intentos = 0;
        $log->fecha = Tools::dateTime();
        $log->resultado = 'reenvio';
        $log->mensaje = 'Reenvío solicitado por ' . $nick . '. Saldrá en la próxima pasada del cron.';
        return $log->save() ? '' : 'no se pudo guardar el historial';
    }

    /**
     * Foto de los mensajes de log en memoria (clave => contador), para saber
     * qué avisos ha generado el core durante el envío.
     */
    protected static function logSnapshot(): array
    {
        $snapshot = [];
        foreach (MiniLog::read('', ['critical', 'error', 'warning']) as $item) {
            $snapshot[$item['channel'] . '|' . $item['level'] . '|' . $item['message']] = $item['count'];
        }
        return $snapshot;
    }

    /**
     * Último aviso o error nuevo desde la foto (p. ej. "Error al conectar al
     * servidor de correo" o el ErrorInfo de PHPMailer).
     */
    protected static function newLogProblem(array $before): string
    {
        $problem = '';
        foreach (MiniLog::read('', ['critical', 'error', 'warning']) as $item) {
            $key = $item['channel'] . '|' . $item['level'] . '|' . $item['message'];
            if ($item['count'] > ($before[$key] ?? 0)) {
                $problem = strip_tags((string)$item['message']);
            }
        }
        return $problem;
    }

    /** Mensajes legibles en el listado del historial: recortamos los muy largos. */
    protected static function shorten(string $text): string
    {
        return mb_substr($text, 0, 500);
    }

    /**
     * Email de destino: primero el contacto de facturación de la factura,
     * si no, el email del cliente.
     */
    protected static function getEmail(FacturaCliente $invoice): string
    {
        if (false === empty($invoice->idcontactofact)) {
            $contacto = new Contacto();
            if ($contacto->load($invoice->idcontactofact) && false === empty($contacto->email)) {
                return $contacto->email;
            }
        }

        $cliente = $invoice->getSubject();
        return (string)($cliente->email ?? '');
    }

    /**
     * Genera el PDF de la factura (con el formato de impresión predeterminado,
     * que en modo Verifactu ya incluye el QR) y devuelve la ruta temporal.
     */
    protected static function exportPdf(FacturaCliente $invoice): string
    {
        $folder = FS_FOLDER . '/MyFiles/Tmp/AutoEnvioFacturas';
        if (false === file_exists($folder) && false === mkdir($folder, 0777, true)) {
            static::log()->error('AutoEnvioFacturas: no se pudo crear la carpeta temporal ' . $folder);
            return '';
        }

        // rospdf escribe su caché de fuentes en MyFiles/Cache sin crearla.
        // Plugins::deploy() borra esa carpeta (al actualizar core/plugins) y solo
        // se recrea con una visita web: si el cron llega antes, error fatal.
        $cacheFolder = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($cacheFolder) && false === @mkdir($cacheFolder, 0777, true) && false === is_dir($cacheFolder)) {
            static::log()->error('AutoEnvioFacturas: no se pudo crear la carpeta ' . $cacheFolder);
            return '';
        }

        $export = new ExportManager();
        $export->newDoc('PDF', $invoice->codigo);

        // OJO: addBusinessDocPage() devuelve false POR DISEÑO ("documento
        // completo, no añadir más páginas"), NO es un indicador de error.
        // El propio core lo llama sin comprobar el retorno (ver ListView).
        // El PDF real se obtiene con getDoc().
        $export->addBusinessDocPage($invoice);

        $doc = $export->getDoc();
        if (empty($doc)) {
            static::log()->error('AutoEnvioFacturas: no se pudo generar el PDF de la factura ' . $invoice->codigo);
            return '';
        }

        $filePath = $folder . '/factura-' . $invoice->idfactura . '.pdf';
        if (false === file_put_contents($filePath, $doc)) {
            static::log()->error('AutoEnvioFacturas: no se pudo escribir el PDF temporal de la factura ' . $invoice->codigo);
            return '';
        }

        return $filePath;
    }
}
