<?php
namespace FacturaScripts\Plugins\Facturalo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Response;

/**
 * Datos agregados para las gráficas del inicio (JSON). Solo usuarios con acceso a las facturas de cliente;
 * siempre filtrado por la empresa del usuario y, si su rol es «solo sus datos», por su nick.
 */
class FacturaloStats extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'Facturalo stats';
        $data['icon'] = 'fa-solid fa-chart-column';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        // El permiso real es el de ver facturas (no exigimos una fila propia en los roles).
        $this->response = &$response;
        $this->user = $user;
        $this->setTemplate(false);
        $this->setCompany($user->idempresa);
        $response->header('Cache-Control', 'no-store');

        if (false === $user->can('ListFacturaCliente')) {
            $response->setHttpCode(Response::HTTP_FORBIDDEN)->json(['error' => 'forbidden']);
            return;
        }

        try {
            $response->json($this->build());
        } catch (\Throwable $e) {
            $response->setHttpCode(Response::HTTP_INTERNAL_SERVER_ERROR)->json(['error' => 'stats-failed']);
        }
    }

    private function build(): array
    {
        $db = $this->dataBase;
        $where = 'f.idempresa = ' . $db->var2str($this->empresa->idempresa);
        if ($this->user->can('ListFacturaCliente', 'only-owner-data')) {
            $where .= ' AND f.nick = ' . $db->var2str($this->user->nick);
        }

        // ventas mensuales: últimos 12 meses (mes actual incluido)
        $from = date('Y-m-01', strtotime('-11 months'));
        $rows = $db->select('SELECT EXTRACT(YEAR FROM f.fecha) AS y, EXTRACT(MONTH FROM f.fecha) AS m, SUM(f.neto) AS t, COUNT(*) AS n'
            . ' FROM facturascli f WHERE ' . $where . ' AND f.fecha >= ' . $db->var2str($from)
            . ' GROUP BY EXTRACT(YEAR FROM f.fecha), EXTRACT(MONTH FROM f.fecha)');
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[sprintf('%04d-%02d', $r['y'], $r['m'])] = (float)$r['t'];
        }
        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
            $monthly[] = ['month' => $key, 'total' => round($byMonth[$key] ?? 0, 2)];
        }

        // ejercicio actual (si no hay, los últimos 12 meses)
        $today = date('Y-m-d');
        $ex = $db->select('SELECT codejercicio FROM ejercicios WHERE idempresa = ' . $db->var2str($this->empresa->idempresa)
            . ' AND fechainicio <= ' . $db->var2str($today) . ' AND fechafin >= ' . $db->var2str($today) . ' LIMIT 1');
        $period = empty($ex)
            ? ' AND f.fecha >= ' . $db->var2str($from)
            : ' AND f.codejercicio = ' . $db->var2str($ex[0]['codejercicio']);

        // estado de las facturas: cobrada / vencida (algún recibo impagado ya vencido) / pendiente
        $st = $db->select('SELECT SUM(CASE WHEN f.pagada THEN 1 ELSE 0 END) AS paid,'
            . ' SUM(CASE WHEN f.pagada THEN 0 WHEN EXISTS (SELECT 1 FROM recibospagoscli r WHERE r.idfactura = f.idfactura'
            . ' AND r.pagado = FALSE AND r.vencimiento < ' . $db->var2str($today) . ') THEN 1 ELSE 0 END) AS overdue,'
            . ' COUNT(*) AS total FROM facturascli f WHERE ' . $where . $period)[0] ?? [];
        $paid = (int)($st['paid'] ?? 0);
        $overdue = (int)($st['overdue'] ?? 0);
        $total = (int)($st['total'] ?? 0);

        // top 5 clientes por facturación (neto)
        $top = [];
        foreach ($db->select('SELECT f.codcliente, MAX(f.nombrecliente) AS nombre, SUM(f.neto) AS t FROM facturascli f WHERE '
            . $where . $period . ' GROUP BY f.codcliente ORDER BY t DESC LIMIT 5') as $r) {
            $top[] = ['name' => (string)$r['nombre'], 'total' => round((float)$r['t'], 2)];
        }

        return [
            'currency' => $this->empresa->coddivisa ?? 'EUR',
            'monthly' => $monthly,
            'status' => ['paid' => $paid, 'overdue' => $overdue, 'pending' => max(0, $total - $paid - $overdue)],
            'top' => $top,
        ];
    }
}
