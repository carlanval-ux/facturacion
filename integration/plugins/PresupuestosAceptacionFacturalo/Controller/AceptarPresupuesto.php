<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Controller;

use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\MultiRequestProtection;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Aceptacion;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Embudo;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Limite;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\ModuloActivo;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib\Token;

/**
 * Página pública (sin sesión) de aceptación de UN presupuesto: /AceptarPresupuesto?t=TOKEN
 * El token identifica un único documento; nunca se muestran datos de otros.
 */
class AceptarPresupuesto implements ControllerInterface
{
    /** @var string ver|aceptado|rechazado|usado|caducado|invalidado|invalido|limite */
    public $step = 'invalido';

    /** @var string */
    public $title = 'Presupuesto';

    /** @var string */
    public $token = '';

    public $doc;
    public $lines = [];
    public $empresa;
    public $row;
    /** @var string error de formulario (clave de traducción) */
    public $formError = '';

    public function __construct(string $className, string $url = '')
    {
    }

    public function getPageData(): array
    {
        return [];
    }

    public function run(): void
    {
        // la URL lleva el token: ni indexar, ni cachear, ni filtrarlo por Referer
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');

        $request = Request::createFromGlobals();
        $this->empresa = Empresas::default();
        $this->token = (string)$request->inputOrQuery('t', '');

        if (false === ModuloActivo::estaActivo('aceptacion') || false === Limite::permitir(Session::getClientIp())) {
            $this->step = 'limite';
        } else {
            $this->cargar($request);
        }

        echo Html::render('AceptarPresupuesto.html.twig', [
            'controllerName' => 'AceptarPresupuesto',
            'debugBarRender' => false,
            'fsc' => $this,
        ]);
    }

    protected function cargar(Request $request): void
    {
        $this->row = Aceptacion::porToken($this->token);
        $doc = new PresupuestoCliente();
        if (null === $this->row || false === $doc->load($this->row->idpresupuesto)) {
            $this->step = 'invalido';
            return;
        }
        $this->doc = $doc;
        $this->title = Tools::trans('aceptacion-titulo', ['%codigo%' => $doc->codigo]);

        $accion = (string)$request->input('action', '');
        if ($request->isMethod('POST') && in_array($accion, ['aceptar', 'rechazar'], true)) {
            if ($this->tokenFormularioValido($request)) {
                $res = Aceptacion::resolver(
                    $this->token,
                    $accion,
                    (string)$request->input('nombre', ''),
                    '1' === (string)$request->input('conforme', ''),
                    (string)$request->input('motivo', ''),
                    Session::getClientIp(),
                    (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
                );
                if ($res['ok']) {
                    $this->row = Aceptacion::porToken($this->token);
                    $this->step = 'aceptar' === $accion ? 'aceptado' : 'rechazado';
                    return;
                }
                if ('datos' === $res['error']) {
                    $this->formError = 'aceptacion-error-datos';
                } elseif ('guardar' === $res['error']) {
                    $this->formError = 'aceptacion-error-guardar';
                } else {
                    $this->row = Aceptacion::porToken($this->token);
                }
            }
        }

        $estado = Aceptacion::estadoDe($this->row, $doc);
        switch ($estado) {
            case Embudo::ACEPTADO:
                $this->step = 'aceptado';
                return;
            case Embudo::RECHAZADO:
                $this->step = 'rechazado';
                return;
            case Embudo::CADUCADO:
            case Embudo::INVALIDADO:
                $this->step = $estado;
                return;
        }
        Aceptacion::marcarVisto($this->row);
        $this->lines = $doc->getLines();
        $this->step = 'ver';
    }

    /** Mismo esquema que el login/recuperación: formulario generado sin sesión y token de un solo uso (CSRF). */
    protected function tokenFormularioValido(Request $request): bool
    {
        $protection = new MultiRequestProtection();
        $protection->clearSeed();
        $token = (string)$request->input('multireqtoken', '');
        if ('' === $token || false === $protection->validate($token) || $protection->tokenExist($token)) {
            Tools::log()->warning('invalid-request');
            return false;
        }
        return true;
    }

    /** Código de comprobación mostrado en la evidencia (huella del contenido aceptado). */
    public function huella(): string
    {
        return null === $this->row ? '' : (string)($this->row->hashresuelto ?: $this->row->hashdoc);
    }

    public function valido(): bool
    {
        return Token::valido($this->token);
    }
}
