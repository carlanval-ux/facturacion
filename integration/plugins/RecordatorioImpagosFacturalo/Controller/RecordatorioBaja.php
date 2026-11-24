<?php
/**
 * RecordatorioImpagosFacturalo - Página pública de baja de recordatorios (sin sesión).
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ImpagoBaja;
use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\Pasos;
use FacturaScripts\Plugins\RecordatorioImpagosFacturalo\Lib\Recordatorios;

class RecordatorioBaja extends Controller
{
    /** @var string form|done|invalid */
    public $estado = 'invalid';
    /** @var string */
    public $codcliente = '';
    /** @var string */
    public $token = '';
    /** @var string */
    public $empresaNombre = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'unsubscribe-reminders';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response)
    {
        parent::publicCore($response);
        $this->procesar();
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->procesar();
    }

    protected function procesar(): void
    {
        $this->setTemplate('RecordatorioBaja');
        $this->empresaNombre = (string)($this->empresa->nombrecorto ?: $this->empresa->nombre);
        $this->codcliente = (string)$this->request->queryOrInput('c', '');
        $this->token = (string)$this->request->queryOrInput('t', '');

        if (false === Pasos::tokenValido($this->codcliente, $this->token, Recordatorios::secreto())) {
            $this->estado = 'invalid';
            return;
        }

        // la baja solo se aplica con POST: los antivirus de correo que abren los enlaces no la disparan
        if ($this->request->method() !== 'POST') {
            $this->estado = 'form';
            return;
        }

        $baja = new ImpagoBaja();
        if (false === $baja->loadWhere([Where::eq('codcliente', $this->codcliente)])) {
            $baja->codcliente = $this->codcliente;
            $baja->fecha = Tools::dateTime();
            $baja->origen = 'enlace';
            $baja->save();
        }
        $this->estado = 'done';
    }
}
