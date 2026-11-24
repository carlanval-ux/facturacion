<?php
namespace FacturaScripts\Plugins\Facturalo\Controller;

use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\Controller\Login;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\Email\ButtonBlock;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Core\Lib\MultiRequestProtection;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Facturalo\Model\FacturaloResetToken;

/**
 * Recuperación de contraseña por correo (pública, sin sesión).
 * /RecuperarClave           -> formulario "usuario o email"
 * /RecuperarClave?t=TOKEN   -> formulario de contraseña nueva
 */
class RecuperarClave implements ControllerInterface
{
    const TOKEN_TTL = 3600;

    /** @var string request|sent|reset|done|invalid */
    public $step = 'request';

    /** @var string */
    public $title = 'Recupera tu acceso';

    /** @var string */
    public $token = '';

    public function __construct(string $className, string $url = '')
    {
    }

    public function getPageData(): array
    {
        return [];
    }

    public function run(): void
    {
        $request = Request::createFromGlobals();
        $this->token = (string)$request->inputOrQuery('t', '');
        $action = $request->input('action', '');

        if ('' !== $this->token) {
            $this->step = self::findToken($this->token) ? 'reset' : 'invalid';
        }

        if ('request' === $action && '' === $this->token) {
            $this->requestAction($request);
        } elseif ('reset' === $action && 'reset' === $this->step) {
            $this->resetAction($request);
        }

        echo Html::render('RecuperarClave.html.twig', [
            'controllerName' => 'RecuperarClave',
            'debugBarRender' => false,
            'fsc' => $this,
        ]);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Devuelve el token vigente (borrando antes los caducados) o null. */
    public static function findToken(string $token): ?FacturaloResetToken
    {
        foreach (FacturaloResetToken::all([Where::lt('expira', time())]) as $old) {
            $old->delete();
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = new FacturaloResetToken();
        return $row->load(self::hashToken($token)) ? $row : null;
    }

    /**
     * URL base propia de la instalación: ajuste site_url, variable FS_SITE_URL o, en último caso,
     * la cabecera Host solo si es un nombre de host sintácticamente válido.
     */
    public static function baseUrl(): string
    {
        $url = (string)Tools::settings('default', 'site_url', '');
        if ('' === $url) {
            $url = (string)Tools::env('FS_SITE_URL', '');
        }
        if ('' === $url) {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (1 !== preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i', $host)) {
                return '';
            }
            $https = !empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS'];
            $url = ($https ? 'https://' : 'http://') . $host;
        }
        return rtrim($url, '/');
    }

    protected function requestAction(Request $request): void
    {
        if (false === $this->checkFormToken($request)) {
            return;
        }

        // cada petición cuenta como incidente de la IP (sin usuario: así no se puede bloquear a nadie por su nick)
        $login = new Login('Login');
        if (null === $login->reserveAttempt(Session::getClientIp())) {
            Tools::log()->warning('ip-banned');
            return;
        }

        // respuesta idéntica exista o no la cuenta
        $this->step = 'sent';
        $id = trim((string)$request->input('usuario', ''));
        if ('' === $id || mb_strlen($id) > 100) {
            return;
        }

        $user = new User();
        $found = $user->load($id);
        if (false === $found) {
            $found = $user->loadWhere([Where::eq('email', mb_strtolower($id))]);
        }
        if (false === $found || false === $user->enabled || empty($user->email)) {
            return;
        }

        $base = self::baseUrl();
        if ('' === $base) {
            Tools::log()->error('Recuperar clave: sin URL base válida, no se envía el correo');
            return;
        }

        // un único token vigente por usuario
        foreach (FacturaloResetToken::all([Where::eq('nick', $user->nick)]) as $old) {
            $old->delete();
        }
        $token = bin2hex(random_bytes(32));
        $row = new FacturaloResetToken();
        $row->tokenhash = self::hashToken($token);
        $row->nick = $user->nick;
        $row->expira = time() + self::TOKEN_TTL;
        $row->save();

        $mail = NewMail::create()
            ->to($user->email)
            ->subject('Recupera tu acceso a Facturalo')
            ->addMainBlock(new TextBlock('Hemos recibido una solicitud para restablecer la contraseña de tu cuenta en Facturalo. '
                . 'Pulsa el botón para elegir una nueva. El enlace es de un solo uso y caduca en 1 hora.'))
            ->addMainBlock(new ButtonBlock('Elegir nueva contraseña', $base . '/RecuperarClave?t=' . $token))
            ->addMainBlock(new TextBlock('Si no lo has solicitado tú, ignora este correo: tu contraseña no cambiará.'));
        if (false === $mail->send()) {
            $row->delete();
            Tools::log()->error('Recuperar clave: fallo al enviar el correo');
            return;
        }
        Tools::log('audit')->notice('Recuperar clave: enlace enviado', ['nick' => $user->nick, 'ip' => Session::getClientIp()]);
    }

    protected function resetAction(Request $request): void
    {
        if (false === $this->checkFormToken($request)) {
            return;
        }

        $login = new Login('Login');
        $attempt = $login->reserveAttempt(Session::getClientIp());
        if (null === $attempt) {
            Tools::log()->warning('ip-banned');
            return;
        }

        $row = self::findToken($this->token);
        if (null === $row) {
            $this->step = 'invalid';
            return;
        }
        $login->releaseAttempt($attempt);

        $password = (string)$request->input('fsNewPasswd', '');
        if ('' === $password) {
            Tools::log()->warning('login-empty-fields');
            return;
        }
        if ($password !== (string)$request->input('fsNewPasswd2', '')) {
            Tools::log()->warning('different-passwords', ['%userNick%' => htmlspecialchars($row->nick)]);
            return;
        }

        $user = new User();
        if (false === $user->load($row->nick) || false === $user->enabled) {
            $row->delete();
            $this->step = 'invalid';
            return;
        }
        if (false === $user->setPassword($password)) {
            Tools::log()->warning('weak-password', ['%userNick%' => htmlspecialchars($row->nick)]);
            return;
        }
        // igual que el cambio de contraseña de emergencia del login de FS
        if ($user->two_factor_enabled) {
            $user->disableTwoFactor();
        }
        if (false === $user->save()) {
            Tools::log()->warning('login-user-not-saved');
            return;
        }

        $row->delete();
        Tools::log('audit')->notice('Recuperar clave: contraseña restablecida', ['nick' => $user->nick, 'ip' => Session::getClientIp()]);
        $this->step = 'done';
    }

    private function checkFormToken(Request $request): bool
    {
        // mismo esquema que el login: formulario generado sin sesión, token de un solo uso
        $protection = new MultiRequestProtection();
        $protection->clearSeed();
        $token = (string)$request->input('multireqtoken', '');
        if ('' === $token || false === $protection->validate($token) || $protection->tokenExist($token)) {
            Tools::log()->warning('invalid-request');
            return false;
        }
        return true;
    }
}
