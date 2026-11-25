<?php
namespace FacturaScripts\Plugins\CobrosRapidosFacturalo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\CuentaBanco;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Cobros;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Modulo;
use FacturaScripts\Plugins\CobrosRapidosFacturalo\Lib\Reparto;

/** Ventana de cobro: uno o varios recibos pendientes (de una o varias facturas), mismo método y fecha. */
class CobrarFacturas extends Controller
{
    /** @var \FacturaScripts\Dinamic\Model\ReciboCliente[] */
    public $recibos = [];
    public $formasPago = [];
    public $cuentas = [];
    public $activo = false;
    public $hoy = '';
    public $codpago = '';
    public $total = 0.0;
    /** ids de entrada, para volver a pintar el formulario tras un error */
    public $paramFacturas = '';
    public $paramRecibos = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'cobros-mark-paid';
        $data['icon'] = 'fa-solid fa-hand-holding-dollar';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->activo = Modulo::activo();
        $this->hoy = date('Y-m-d');
        $this->paramFacturas = $this->soloIds((string)$this->request->inputOrQuery('facturas', ''));
        $this->paramRecibos = $this->soloIds((string)$this->request->inputOrQuery('recibos', ''));
        $this->formasPago = FormaPago::all([], ['descripcion' => 'ASC'], 0, 0);
        $this->cuentas = CuentaBanco::all([], ['descripcion' => 'ASC'], 0, 0);

        if (false === $this->activo) {
            Tools::log()->warning('cobros-module-inactive');
            return;
        }
        $this->cargarRecibos();
        if ('cobrar' === $this->request->input('action')) {
            $this->cobrar();
        }
    }

    private function soloIds(string $csv): string
    {
        return implode(',', array_filter(array_map('intval', explode(',', $csv))));
    }

    private function cargarRecibos(): void
    {
        $todos = array_merge(
            Cobros::pendientesDeFacturas(explode(',', $this->paramFacturas)),
            Cobros::pendientesDeRecibos(explode(',', $this->paramRecibos))
        );
        // sin duplicados si un recibo llega por factura y por recibo
        $this->recibos = [];
        foreach ($todos as $r) {
            $this->recibos[(int)$r->idrecibo] = $r;
        }
        $this->recibos = array_values($this->recibos);
        $this->total = array_sum(array_map(fn($r) => (float)$r->importe, $this->recibos));
        $this->codpago = empty($this->recibos) ? '' : (string)$this->recibos[0]->codpago;
        if (empty($this->recibos)) {
            Tools::log()->warning('cobros-nothing-to-pay');
        }
    }

    private function cobrar(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pendientes = [];
        foreach ($this->recibos as $r) {
            $pendientes[(int)$r->idrecibo] = (float)$r->importe;
        }

        // con total indicado se reparte por vencimiento; si no, cada recibo con su importe
        $totalTxt = trim((string)$this->request->input('total', ''));
        if ('' !== $totalTxt) {
            $importes = Reparto::distribuir((float)str_replace(',', '.', $totalTxt), $pendientes, (int)Tools::settings('default', 'decimals', 2));
            if (null === $importes) {
                Tools::log()->warning('cobros-amount-exceeds');
                return;
            }
        } else {
            $importes = [];
            $enviados = $this->request->request->getArray('importe');
            foreach ($pendientes as $id => $pend) {
                $v = (float)str_replace(',', '.', (string)($enviados[$id] ?? $pend));
                if ($v > 0) {
                    $importes[$id] = $v;
                }
            }
        }

        $lote = '';
        $error = Cobros::cobrar(
            $importes,
            (string)$this->request->input('fecha', ''),
            (string)$this->request->input('codpago', ''),
            (string)$this->request->input('codcuenta', ''),
            (string)$this->request->input('observaciones', ''),
            $this->user->nick,
            $lote
        );
        if ('' !== $error) {
            Tools::log()->warning($error);
            $this->cargarRecibos();
            return;
        }

        Tools::log()->notice('cobros-done', ['%count%' => count($importes)]);
        $this->redirect('ListFacturaCliente');
    }
}
