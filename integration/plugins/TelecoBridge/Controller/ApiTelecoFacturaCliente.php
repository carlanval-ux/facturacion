<?php
namespace FacturaScripts\Plugins\TelecoBridge\Controller;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Controller\ApiCreateDocument;
use FacturaScripts\Core\Response;

/**
 * POST /api/3/telecoFacturaCliente con cabecera Idempotency-Key. Mismo cuerpo que crearFacturaCliente.
 * La clave se reserva con INSERT sobre PRIMARY KEY (atómico): una repetición nunca crea una segunda factura.
 * Misma clave + mismo cuerpo → misma respuesta (cabecera Idempotent-Replay: true). Misma clave + cuerpo distinto → 422.
 * Con varias empresas en la instalacion (una por tenant de la plataforma) exige codalmacen (la empresa sale del almacen; sin el, el nucleo factura en la empresa por defecto) y la clave de idempotencia se separa por empresa.
 * shortcut: una reserva "pendiente" por caída del proceso exige revisión manual (409); sin reclamación automática.
 */
class ApiTelecoFacturaCliente extends ApiCreateDocument
{
    protected function loadModel(): void
    {
        $this->sales_model = 'FacturaCliente';
    }

    protected function runResource(): void
    {
        if ($this->request->method() !== 'POST') {
            $this->fail(Response::HTTP_METHOD_NOT_ALLOWED, 'method-not-allowed');
            return;
        }

        $key = (string)$this->request->header('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $key)) {
            $this->fail(Response::HTTP_BAD_REQUEST, 'Idempotency-Key header is required (8-80 chars: A-Za-z0-9_.:-)');
            return;
        }

        $scope = null;
        if (FacturaScriptsDinamicModelEmpresa::count() > 1) {
            $almacen = new FacturaScriptsDinamicModelAlmacen();
            $codalmacen = (string)$this->request->input('codalmacen', '');
            if ('' === $codalmacen || false === $almacen->load($codalmacen)) {
                $this->fail(Response::HTTP_BAD_REQUEST, 'codalmacen is required and must exist when the installation has several companies');
                return;
            }
            $scope = (int)$almacen->idempresa;
        }
        $key = self::scopedKey($key, $scope);

        $body = $this->request->request->all();
        ksort($body);
        $hash = hash('sha256', json_encode($body));

        new \FacturaScripts\Dinamic\Model\TelecoIdempotencia(); // asegura la tabla
        $db = new DataBase();
        $k = $db->var2str($key);
        $sql = 'INSERT INTO teleco_idempotencia (clave, hash, estado, creado) VALUES ('
            . $k . ', ' . $db->var2str($hash) . ", 'pending', " . $db->var2str(date('Y-m-d H:i:s')) . ')';
        if (false === @$db->exec($sql)) {
            $this->replay($db, $k, $hash);
            return;
        }

        parent::runResource();

        $http = $this->response->getHttpCode();
        if ($http >= 200 && $http < 300) {
            $db->exec('UPDATE teleco_idempotencia SET estado = ' . $db->var2str('done') . ', http = ' . (int)$http
                . ', respuesta = ' . $db->var2str($this->response->getContent()) . ' WHERE clave = ' . $k);
            return;
        }
        // fallo: se libera la clave para que el cliente pueda reintentar
        $db->exec('DELETE FROM teleco_idempotencia WHERE clave = ' . $k);
    }

    /** Sin varias empresas la clave se guarda tal cual (compatible con lo ya reservado); con varias, se liga a la empresa. */
    public static function scopedKey(string $key, ?int $idempresa): string
    {
        return null === $idempresa ? $key : substr(hash('sha256', $idempresa . '|' . $key), 0, 64);
    }

    private function replay(DataBase $db, string $k, string $hash): void
    {
        $rows = $db->select('SELECT hash, estado, http, respuesta FROM teleco_idempotencia WHERE clave = ' . $k);
        $row = $rows[0] ?? null;
        if (null === $row) {
            $this->fail(Response::HTTP_INTERNAL_SERVER_ERROR, 'idempotency-store-error');
        } elseif ($row['hash'] !== $hash) {
            $this->fail(Response::HTTP_UNPROCESSABLE_ENTITY, 'idempotency-key-reused-with-different-body');
        } elseif ($row['estado'] !== 'done') {
            $this->fail(Response::HTTP_CONFLICT, 'idempotency-request-in-progress');
        } else {
            $this->response->header('Idempotent-Replay', 'true')->setHttpCode((int)$row['http'])->setContent($row['respuesta']);
        }
    }

    private function fail(int $code, string $message): void
    {
        $this->response->setHttpCode($code)->json(['status' => 'error', 'message' => $message]);
    }
}
