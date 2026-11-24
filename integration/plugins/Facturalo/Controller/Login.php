<?php
namespace FacturaScripts\Plugins\Facturalo\Controller;

use FacturaScripts\Core\Controller\Login as ParentLogin;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;

/**
 * Login que acepta usuario o email. Resuelve el nick antes de que el login de FS autentique,
 * así el límite de intentos y el error genérico son los de siempre.
 */
class Login extends ParentLogin
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

    protected function loginAction(Request $request): void
    {
        // email inexistente o ambiguo: se deja el texto tal cual y falla como una contraseña mala
        $nick = self::resolveNick((string)$request->input('fsNick', ''));
        if (null !== $nick) {
            $request->request->set('fsNick', $nick);
        }
        parent::loginAction($request);
    }
}
