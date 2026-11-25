<?php
/**
 * This file is part of PersonalizarEmail plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\PersonalizarEmail\Lib;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Plugins\PersonalizarEmail\Lib\Email\PreviewMail;

/**
 * Genera la vista previa de una notificación de email con datos de ejemplo,
 * usando la misma plantilla y el mismo renderizado que el envío real (NewMail).
 * También permite enviar esa misma vista previa como email de prueba.
 */
class EmailPreview
{
    /** Claves de estilo que se pueden sobrescribir al vuelo desde el formulario. */
    const STYLE_KEYS = [
        'emailtitle', 'titlefont', 'titlesize', 'colortitle', 'hidetitle', 'logoposition', 'logoalign', 'logoembed',
        'bodyfont', 'bodysize', 'bodycolor', 'colorbg', 'colorcontainer', 'colorbtn',
        'footertext', 'footerfont', 'footercolor', 'footersize'
    ];

    /** Prefijo del asunto de los emails de prueba. */
    const TEST_SUBJECT_PREFIX = '[TEST] ';

    /**
     * Construye la vista previa a partir de la petición AJAX.
     * Parámetros: code (nombre de la notificación), subject y body opcionales
     * (para previsualizar cambios sin guardar) y style_* (colores sin guardar).
     */
    public static function fromRequest(Request $request): array
    {
        $params = self::requestParams($request);

        return self::render($params['code'], $params['subject'], $params['body'], $params['styles']);
    }

    /**
     * Renderiza la vista previa de la notificación indicada.
     * Los overrides de estilo se aplican solo en memoria (no se guardan).
     */
    public static function render(string $notificationName, string $subject = '', string $body = '', array $styleOverrides = []): array
    {
        $resolved = self::resolve($notificationName, $subject, $body, $styleOverrides);
        if (null === $resolved) {
            return ['ok' => false, 'error' => Tools::trans('record-not-found')];
        }

        $mail = new PreviewMail();
        $mail->subject($resolved['subject'])->body($resolved['body']);

        return [
            'ok' => true,
            'subject' => $resolved['subject'],
            'html' => $mail->preview(),
        ];
    }

    /**
     * Envía la vista previa como email de prueba al destinatario indicado.
     * El contenido es exactamente el mismo que muestra la vista previa,
     * incluidos los cambios sin guardar.
     */
    public static function send(string $email, string $notificationName, string $subject = '', string $body = '', array $styleOverrides = []): array
    {
        if (false === Validator::email($email)) {
            return ['ok' => false, 'error' => Tools::trans('not-valid-email', ['%email%' => $email])];
        }

        $resolved = self::resolve($notificationName, $subject, $body, $styleOverrides);
        if (null === $resolved) {
            return ['ok' => false, 'error' => Tools::trans('record-not-found')];
        }

        $mail = new PreviewMail();
        if (false === $mail->canSendMail()) {
            return ['ok' => false, 'error' => Tools::trans('email-not-configured')];
        }

        $mail->to($email)
            ->subject(self::TEST_SUBJECT_PREFIX . $resolved['subject'])
            ->body($resolved['body']);

        // etiquetamos el email con la notificación de origen para el historial;
        // el método notification() no existe en cores anteriores a 2026.7
        if ('' !== $notificationName && method_exists($mail, 'notification')) {
            $mail->notification($notificationName);
        }

        if (false === $mail->send()) {
            return ['ok' => false, 'error' => Tools::trans('send-mail-error')];
        }

        return ['ok' => true, 'message' => Tools::trans('mail-test-ok')];
    }

    /**
     * Envía el email de prueba a partir de la petición AJAX (parámetro email).
     */
    public static function sendFromRequest(Request $request): array
    {
        $params = self::requestParams($request);
        $email = $request->request->get('email', '') ?? '';

        return self::send($email, $params['code'], $params['subject'], $params['body'], $params['styles']);
    }

    /**
     * Datos de ejemplo para los shortcodes que usa el core (SendMail).
     * Los shortcodes desconocidos se dejan visibles para que el usuario los identifique.
     */
    public static function sampleData(): array
    {
        return [
            '{code}' => 'FAC2026-123',
            '{number2}' => 'REF-0042',
            '{name}' => 'Cliente de Ejemplo S.L.',
            '{date}' => Tools::date(),
            '{total}' => '121.00',
            '{contact_name}' => 'María',
            '{contact_surname}' => 'García López',
            '{email}' => 'cliente@example.com',
        ];
    }

    /**
     * Extrae los parámetros comunes de la petición AJAX.
     */
    private static function requestParams(Request $request): array
    {
        $styles = [];
        foreach (self::STYLE_KEYS as $key) {
            $value = $request->request->get('style_' . $key, '');
            if ('' !== $value && null !== $value) {
                $styles[$key] = $value;
            }
        }

        return [
            'code' => $request->request->get('code', '') ?? '',
            'subject' => $request->request->get('subject', '') ?? '',
            'body' => $request->request->get('body', '') ?? '',
            'styles' => $styles,
        ];
    }

    /**
     * Resuelve asunto y cuerpo definitivos (notificación o texto sin guardar),
     * sustituye los shortcodes y aplica los estilos en memoria.
     * Devuelve null si la notificación no existe.
     */
    private static function resolve(string $notificationName, string $subject, string $body, array $styleOverrides): ?array
    {
        // si no nos pasan asunto/cuerpo sin guardar, usamos los de la notificación
        if (empty($subject) && empty($body)) {
            $notification = new EmailNotification();
            if (false === $notification->load($notificationName)) {
                return null;
            }
            $subject = $notification->subject;
            $body = $notification->body;
        }

        // sustituimos los shortcodes por datos de ejemplo
        $sample = self::sampleData();
        $subject = str_replace(array_keys($sample), array_values($sample), $subject);
        $body = str_replace(array_keys($sample), array_values($sample), $body);

        // aplicamos los estilos sin guardar, solo en memoria de este proceso
        foreach ($styleOverrides as $key => $value) {
            if (in_array($key, self::STYLE_KEYS, true)) {
                Tools::settingsSet('emailstyle', $key, $value);
            }
        }

        return ['subject' => $subject, 'body' => $body];
    }
}
