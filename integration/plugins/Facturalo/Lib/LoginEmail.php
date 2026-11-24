<?php
namespace FacturaScripts\Plugins\Facturalo\Lib;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;

/**
 * El login acepta usuario o email. Se resuelve el nick en Init (antes de que el login de FS lea $_POST),
 * sin depender de qué clase de Login atienda la ruta: así el límite de intentos y el error genérico son los de siempre.
 */
final class LoginEmail
{
    /**
     * Nick del único usuario habilitado con ese email (sin distinguir mayúsculas), o null.
     * Si el texto ya es un nick existente, se respeta tal cual.
     */
    public static function resolveNick(string $input): ?string
    {
        $input = trim($input);
        if (false === str_contains($input, '@')) {
            return null;
        }
        if ((new User())->load($input)) {
            return null;
        }
        $users = User::all([Where::eq('email', mb_strtolower($input)), Where::eq('enabled', true)], [], 0, 2);
        return 1 === count($users) ? $users[0]->nick : null;
    }

    /** Si la petición es el envío del login con un email, sustituye fsNick por el nick resuelto. */
    public static function apply(): void
    {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        if (false === str_ends_with($path, '/login') || ($_POST['action'] ?? '') !== 'login') {
            return;
        }
        $nick = $_POST['fsNick'] ?? '';
        if (!is_string($nick)) {
            return;
        }
        // email inexistente o ambiguo: se deja el texto tal cual y falla como una contraseña mala
        $resolved = self::resolveNick($nick);
        if (null !== $resolved) {
            $_POST['fsNick'] = $resolved;
        }
    }
}
