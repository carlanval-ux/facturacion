<?php
namespace FacturaScripts\Plugins\Facturalo\Lib;

use FacturaScripts\Core\Lib\MenuItem;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ApiKey;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Dinamic\Model\User;

/**
 * «Personal de plataforma» (staff): lista de nicks en los ajustes (grupo facturalo, clave staff_nicks).
 * Solo el staff ve y usa lo técnico (plugins, actualizador, SMTP, claves API, logs); los clientes
 * administran lo suyo (usuarios de su empresa, series, formas de pago...).
 */
final class Staff
{
    const DEFAULT_NICKS = 'admin,Carlos';

    /** Controladores solo para staff (minúsculas). */
    const BLOCKED = [
        'adminplugins', 'updater', 'about', 'configemail', 'editapikey',
        'listlogmessage', 'editlogmessage', 'editcronjob',
    ];

    /** Controladores que también usan procesos sin sesión (cron, despliegue): solo se bloquean a una sesión de cliente. */
    const SESSION_ONLY = ['cron', 'deploy'];

    /** Lista guardada, leída siempre de la base de datos (no de la caché de ajustes): quitar a alguien del staff debe surtir efecto al instante. */
    private static function stored(): ?string
    {
        $model = new Settings();
        $value = $model->load('facturalo') ? ($model->getProperties()['staff_nicks'] ?? null) : null;
        return is_string($value) ? $value : null;
    }

    public static function nicks(): array
    {
        $list = (string)self::stored();
        return array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
    }

    public static function seed(): void
    {
        if (null === self::stored()) {
            Tools::settingsSet('facturalo', 'staff_nicks', self::DEFAULT_NICKS);
            Tools::settingsSave();
        }
    }

    public static function isStaff(?string $nick): bool
    {
        return null !== $nick && in_array(mb_strtolower($nick), array_map('mb_strtolower', self::nicks()), true);
    }

    /** Usuario de la sesión por cookies (mismas comprobaciones que el controlador de FS), o null. */
    public static function sessionUser(): ?User
    {
        $nick = (string)($_COOKIE['fsNick'] ?? '');
        if ('' === $nick) {
            return null;
        }
        $user = new User();
        if (false === $user->load($nick) || false === $user->enabled || false === $user->verifyLogkey((string)($_COOKIE['fsLogkey'] ?? ''))) {
            return null;
        }
        return $user;
    }

    /** Devuelve true si la petición web actual hay que rechazarla. */
    public static function mustBlock(string $path, array $headers): bool
    {
        $segments = explode('/', trim($path, '/'));
        $name = strtolower($segments[0] ?? '');

        // API de plugins: solo la clave de la instalación o una clave de un usuario staff
        if ('api' === $name) {
            if (1 !== preg_match('#^api/\d+/plugins(/|$)#i', trim($path, '/'))) {
                return false;
            }
            $token = (string)($headers['Token'] ?? $headers['X-Auth-Token'] ?? '');
            $config = (string)Tools::config('api_key', '');
            if ('' !== $config && '' !== $token && hash_equals($config, $token)) {
                return false;
            }
            $key = new ApiKey();
            return false === ('' !== $token && $key->loadWhere([Where::eq('apikey', $token), Where::eq('enabled', true)])
                    && self::isStaff($key->nick));
        }

        if (in_array($name, self::BLOCKED, true) || 'plataformastaff' === $name) {
            $user = self::sessionUser();
            // sin sesión válida deja pasar: el controlador pedirá el login
            return null !== $user && false === self::isStaff($user->nick);
        }
        if (in_array($name, self::SESSION_ONLY, true)) {
            $user = self::sessionUser();
            return null !== $user && false === self::isStaff($user->nick);
        }
        return false;
    }

    /** Copia del menú sin las páginas técnicas, salvo para el staff. */
    public static function filterMenu(MenuItem $item): MenuItem
    {
        $user = self::sessionUser();
        if (null !== $user && self::isStaff($user->nick)) {
            return $item;
        }
        $copy = clone $item;
        $copy->menu = [];
        foreach ($item->menu as $key => $child) {
            if (in_array(strtolower($child->name), self::BLOCKED, true) || 'plataformastaff' === strtolower($child->name)) {
                continue;
            }
            $copy->menu[$key] = empty($child->menu) ? $child : self::filterMenu($child);
        }
        return $copy;
    }

    /** Aplica el bloqueo a la petición actual (se llama desde Init). */
    public static function guard(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (false === self::mustBlock($path, function_exists('getallheaders') ? (getallheaders() ?: []) : [])) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && 0 !== stripos($path, '/api/')) {
            header('Location: ' . (Tools::config('route') ?: '') . '/');
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Acceso restringido';
        exit;
    }
}
