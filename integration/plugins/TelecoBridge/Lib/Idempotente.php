<?php
namespace FacturaScripts\Plugins\TelecoBridge\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Response;

/**
 * MAPA-23: idempotencia por cabecera `Idempotency-Key` para controladores de la API (misma tabla teleco_idempotencia
 * y mismas reglas que telecoFacturaCliente: la clave se reserva con INSERT sobre la PRIMARY KEY; misma clave + mismo cuerpo
 * = misma respuesta con `Idempotent-Replay: true`; mismo cuerpo distinto = 422; en curso = 409; un fallo libera la clave).
 * La clave se liga al endpoint para que una misma clave no choque entre pagar y rectificar.
 */
trait Idempotente
{
    public static function claveIdempotente(string $endpoint, string $key): string
    {
        return substr(hash('sha256', $endpoint . '|' . $key), 0, 64);
    }

    public static function hashCuerpo(array $body, array $extra = []): string
    {
        $todo = $body + ['__extra' => $extra];
        ksort($todo);
        return hash('sha256', json_encode($todo));
    }

    protected function idempotente(string $endpoint, array $extra, callable $ejecutar): void
    {
        $key = (string)$this->request->header('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $key)) {
            $this->idemFallo(Response::HTTP_BAD_REQUEST, 'Idempotency-Key header is required (8-80 chars: A-Za-z0-9_.:-)');
            return;
        }
        $hash = self::hashCuerpo($this->request->request->all(), $extra);

        new \FacturaScripts\Dinamic\Model\TelecoIdempotencia(); // asegura la tabla
        $db = new DataBase();
        $k = $db->var2str(self::claveIdempotente($endpoint, $key));
        $sql = 'INSERT INTO teleco_idempotencia (clave, hash, estado, creado) VALUES ('
            . $k . ', ' . $db->var2str($hash) . ", 'pending', " . $db->var2str(date('Y-m-d H:i:s')) . ')';
        if (false === @$db->exec($sql)) {
            $this->idemReplay($db, $k, $hash);
            return;
        }

        $ejecutar();

        $http = $this->response->getHttpCode();
        if ($http >= 200 && $http < 300) {
            $db->exec('UPDATE teleco_idempotencia SET estado = ' . $db->var2str('done') . ', http = ' . (int)$http
                . ', respuesta = ' . $db->var2str($this->response->getContent()) . ' WHERE clave = ' . $k);
            return;
        }
        $db->exec('DELETE FROM teleco_idempotencia WHERE clave = ' . $k); // fallo: se puede reintentar
    }

    private function idemReplay(DataBase $db, string $k, string $hash): void
    {
        $row = $db->select('SELECT hash, estado, http, respuesta FROM teleco_idempotencia WHERE clave = ' . $k)[0] ?? null;
        if (null === $row) {
            $this->idemFallo(Response::HTTP_INTERNAL_SERVER_ERROR, 'idempotency-store-error');
        } elseif ($row['hash'] !== $hash) {
            $this->idemFallo(Response::HTTP_UNPROCESSABLE_ENTITY, 'idempotency-key-reused-with-different-body');
        } elseif ($row['estado'] !== 'done') {
            $this->idemFallo(Response::HTTP_CONFLICT, 'idempotency-request-in-progress');
        } else {
            $this->response->header('Idempotent-Replay', 'true')->setHttpCode((int)$row['http'])->setContent($row['respuesta']);
        }
    }

    private function idemFallo(int $code, string $message): void
    {
        $this->response->setHttpCode($code)->json(['status' => 'error', 'message' => $message]);
    }
}
