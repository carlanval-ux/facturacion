<?php
namespace FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Lib\Email\ButtonBlock;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\EmailSent;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Facturalo\Controller\RecuperarClave;
use FacturaScripts\Plugins\PresupuestosAceptacionFacturalo\Model\PresupuestoAceptacion;
use Throwable;

/** Lógica del módulo: envío del enlace, aceptación/rechazo con evidencia y conversión con el flujo estándar de estados. */
final class Aceptacion
{
    const DESTINOS = ['factura' => 'FacturaCliente', 'pedido' => 'PedidoCliente', 'albaran' => 'AlbaranCliente'];

    public static function dias(): int
    {
        return max(1, min(365, (int)Tools::settings('presupuestosaceptacion', 'dias', 30)));
    }

    public static function urlPublica(string $base, string $token): string
    {
        return rtrim($base, '/') . '/AceptarPresupuesto?t=' . $token;
    }

    public static function porToken(string $token): ?PresupuestoAceptacion
    {
        if (false === Token::valido($token)) {
            return null;
        }
        $row = new PresupuestoAceptacion();
        return $row->loadWhere([Where::eq('tokenhash', Token::hash($token))]) ? $row : null;
    }

    /** Último enlace emitido para un presupuesto (o null). */
    public static function ultimo(int $idpresupuesto): ?PresupuestoAceptacion
    {
        $filas = PresupuestoAceptacion::all([Where::eq('idpresupuesto', $idpresupuesto)], ['id' => 'DESC'], 0, 1);
        return $filas[0] ?? null;
    }

    /** Estado efectivo; con $doc se comprueba además que el presupuesto no haya cambiado desde el envío. */
    public static function estadoDe(PresupuestoAceptacion $row, ?PresupuestoCliente $doc = null): string
    {
        $hash = null === $doc ? null : Contenido::deDocumento($doc);
        return Embudo::estado((array)$row, time(), $hash);
    }

    /** @return array{ok:bool,msg:string} msg = clave de traducción */
    public static function enviar(PresupuestoCliente $doc, string $nick): array
    {
        if (false === ModuloActivo::estaActivo('aceptacion')) {
            return self::res(false, 'aceptacion-modulo-inactivo');
        }
        if (false === $doc->editable) {
            return self::res(false, 'aceptacion-no-editable');
        }
        $cliente = $doc->getSubject();
        $email = trim((string)$cliente->email);
        if (false === Validator::email($email)) {
            return self::res(false, 'aceptacion-sin-email');
        }
        $base = RecuperarClave::baseUrl();
        $mail = NewMail::create();
        if ('' === $base || false === $mail->canSendMail()) {
            return self::res(false, 'aceptacion-sin-correo');
        }

        $token = Token::generar();
        $row = new PresupuestoAceptacion();
        $row->idpresupuesto = $doc->id();
        $row->codigo = $doc->codigo;
        $row->tokenhash = Token::hash($token);
        $row->hashdoc = Contenido::deDocumento($doc);
        $row->email = $email;
        $row->nick = $nick;
        $row->creado = time();
        $row->caduca = time() + self::dias() * 86400;
        if (false === $row->save()) {
            return self::res(false, 'record-save-error');
        }

        $empresa = Empresas::default();
        $nombre = $empresa->nombrecorto ?: $empresa->nombre;
        $tmp = '';
        try {
            $export = new ExportManager();
            $export->newDoc('PDF', (string)$doc->codigo, 0, (string)($cliente->langcode ?? ''));
            $export->addBusinessDocPage($doc);
            Tools::folderCheckOrCreate(FS_FOLDER . '/' . NewMail::ATTACHMENTS_TMP_PATH);
            $fichero = 'presupuesto-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$doc->codigo) . '-' . Tools::randomString(8) . '.pdf';
            $tmp = FS_FOLDER . '/' . NewMail::ATTACHMENTS_TMP_PATH . $fichero;
            file_put_contents($tmp, $export->getDoc());

            $mail->to($email, (string)$cliente->razonsocial)
                ->subject(Tools::trans('aceptacion-mail-asunto', ['%codigo%' => $doc->codigo, '%empresa%' => $nombre]))
                ->addMainBlock(new TextBlock($nombre))
                ->addMainBlock(new TextBlock(Tools::trans('aceptacion-mail-texto', ['%codigo%' => $doc->codigo, '%dias%' => self::dias()])))
                ->addMainBlock(new ButtonBlock(Tools::trans('aceptacion-mail-boton'), self::urlPublica($base, $token)))
                ->addMainBlock(new TextBlock(Tools::trans('aceptacion-mail-pie')))
                ->addAttachment($tmp, $fichero);
            $user = new User();
            if ($user->load($nick) && Validator::email((string)$user->email)) {
                $mail->replyTo($user->email, $user->nick);
            }
            $enviado = $mail->send();
        } catch (Throwable $e) {
            Tools::log()->error('Aceptación: ' . $e->getMessage());
            $enviado = false;
        }
        if ('' !== $tmp && is_file($tmp)) {
            @unlink($tmp);
        }
        if (false === $enviado) {
            $row->delete();
            return self::res(false, 'aceptacion-fallo-envio');
        }

        // el registro de correos guarda el HTML con el enlace: se borra el token en claro
        foreach (EmailSent::all([Where::like('html', '%' . $token . '%')]) as $sent) {
            $sent->delete();
        }
        // enlaces anteriores sin resolver: invalidados
        foreach (PresupuestoAceptacion::all([Where::eq('idpresupuesto', $doc->id()), Where::in('estado', ['enviado', 'visto'])]) as $old) {
            if ($old->id !== $row->id) {
                $old->estado = Embudo::INVALIDADO;
                $old->save();
            }
        }
        Tools::log('audit')->notice('Presupuesto enviado para aceptación', ['codigo' => $doc->codigo, 'nick' => $nick]);
        return self::res(true, 'aceptacion-enviado');
    }

    /** Primera visita: enviado -> visto (atómico, no pisa una resolución concurrente). */
    public static function marcarVisto(PresupuestoAceptacion $row): void
    {
        if ($row->estado !== Embudo::ENVIADO) {
            return;
        }
        $db = new DataBase();
        $db->exec('UPDATE presupuestos_aceptacion SET estado = ' . $db->var2str(Embudo::VISTO) . ', visto = ' . time()
            . ' WHERE id = ' . (int)$row->id . ' AND estado = ' . $db->var2str(Embudo::ENVIADO));
    }

    /**
     * Acción final de un solo uso. $accion = aceptar|rechazar. Bloquea la fila: el segundo clic recibe 'usado'.
     * @return array{ok:bool,error:string}
     */
    public static function resolver(string $token, string $accion, string $nombre, bool $conforme, string $motivo, string $ip, string $ua): array
    {
        if (false === Token::valido($token) || false === in_array($accion, ['aceptar', 'rechazar'], true)) {
            return ['ok' => false, 'error' => 'invalido'];
        }
        $nombre = trim((string)preg_replace('/\s+/u', ' ', $nombre));
        if ('aceptar' === $accion && (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150 || false === $conforme)) {
            return ['ok' => false, 'error' => 'datos'];
        }

        $db = new DataBase();
        $db->beginTransaction();
        $filas = $db->select('SELECT id FROM presupuestos_aceptacion WHERE tokenhash = ' . $db->var2str(Token::hash($token)) . ' FOR UPDATE');
        $row = new PresupuestoAceptacion();
        $doc = new PresupuestoCliente();
        if (empty($filas) || false === $row->load($filas[0]['id']) || false === $doc->load($row->idpresupuesto)) {
            $db->rollback();
            return ['ok' => false, 'error' => 'invalido'];
        }

        $hash = Contenido::deDocumento($doc);
        $estado = Embudo::estado((array)$row, time(), $hash);
        if (false === Embudo::resoluble($estado)) {
            if ($estado === Embudo::INVALIDADO && $row->estado !== Embudo::INVALIDADO) {
                $row->estado = Embudo::INVALIDADO;
                $row->save();
                $db->commit();
            } else {
                $db->rollback();
            }
            // aceptado/rechazado -> 'usado'; caducado e invalidado se informan tal cual
            $usado = in_array($estado, [Embudo::ACEPTADO, Embudo::RECHAZADO], true);
            return ['ok' => false, 'error' => $usado ? 'usado' : $estado];
        }

        $row->estado = 'aceptar' === $accion ? Embudo::ACEPTADO : Embudo::RECHAZADO;
        $row->resuelto = time();
        $row->nombre = 'aceptar' === $accion ? $nombre : null;
        $row->motivo = 'rechazar' === $accion ? mb_substr(trim($motivo), 0, 1000) : null;
        $row->ip = mb_substr($ip, 0, 45);
        $row->useragent = mb_substr($ua, 0, 255);
        $row->hashresuelto = $hash;
        $nuevo = 'aceptar' === $accion ? self::estadoAceptado() : self::estadoRechazado($doc);
        if (null !== $nuevo) {
            $doc->idestado = $nuevo->idestado;
        }
        if (false === $row->save() || (null !== $nuevo && false === $doc->save())) {
            $db->rollback();
            return ['ok' => false, 'error' => 'guardar'];
        }
        $db->commit();

        self::avisarEmisor($row, $doc);
        if ('aceptar' === $accion) {
            self::autoConvertir($row, $doc);
        }
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool,msg:string} */
    public static function convertir(PresupuestoCliente $doc, string $destino): array
    {
        if (false === ModuloActivo::estaActivo('aceptacion')) {
            return self::res(false, 'aceptacion-modulo-inactivo');
        }
        $estado = self::estadoPara($doc, self::DESTINOS[$destino] ?? '');
        if (null === $estado || false === self::convertible($doc)) {
            return self::res(false, 'aceptacion-no-convertible');
        }
        $db = new DataBase();
        $db->beginTransaction();
        $doc->idestado = $estado->idestado;
        try {
            // flujo estándar: al cambiar a un estado con "generadoc" el núcleo genera el documento (fecha, Verifactu y validaciones incluidas)
            $ok = $doc->save();
        } catch (Throwable $e) {
            Tools::log()->error($e->getMessage());
            $ok = false;
        }
        if (false === $ok) {
            $db->rollback();
            return self::res(false, 'aceptacion-fallo-conversion');
        }
        $db->commit();
        return self::res(true, 'aceptacion-convertido');
    }

    /** Aceptado por el cliente y aún sin convertir. */
    public static function convertible(PresupuestoCliente $doc): bool
    {
        $row = self::ultimo((int)$doc->id());
        $aceptado = self::estadoAceptado(false);
        return null !== $row && $row->estado === Embudo::ACEPTADO && null !== $aceptado
            && (int)$doc->idestado === (int)$aceptado->idestado;
    }

    /** Destinos disponibles (con estado activo que genere ese documento). */
    public static function destinos(PresupuestoCliente $doc): array
    {
        $out = [];
        foreach (self::DESTINOS as $clave => $clase) {
            if (null !== self::estadoPara($doc, $clase)) {
                $out[] = $clave;
            }
        }
        return $out;
    }

    public static function estadoPara(PresupuestoCliente $doc, string $clase): ?EstadoDocumento
    {
        foreach ($doc->getAvailableStatus() as $estado) {
            if ($estado->activo && '' !== $clase && $estado->generadoc === $clase) {
                return $estado;
            }
        }
        return null;
    }

    public static function estadoAceptado(bool $crear = true): ?EstadoDocumento
    {
        $found = EstadoDocumento::all([Where::eq('tipodoc', 'PresupuestoCliente'), Where::eq('nombre', 'Aceptado')]);
        if (false === empty($found)) {
            return $found[0];
        }
        if (false === $crear) {
            return null;
        }
        $e = new EstadoDocumento();
        $e->tipodoc = 'PresupuestoCliente';
        $e->nombre = 'Aceptado';
        $e->editable = false;
        $e->activo = true;
        $e->predeterminado = false;
        $e->icon = 'fa-solid fa-handshake';
        $e->orden = 55;
        return $e->save() ? $e : null;
    }

    protected static function estadoRechazado(PresupuestoCliente $doc): ?EstadoDocumento
    {
        foreach ($doc->getAvailableStatus() as $estado) {
            if ($estado->activo && false === $estado->editable && empty($estado->generadoc)
                && ((int)$estado->idestado === 3 || 1 === preg_match('/rechaz|reject/i', (string)$estado->nombre))) {
                return $estado;
            }
        }
        return null;
    }

    protected static function autoConvertir(PresupuestoAceptacion $row, PresupuestoCliente $doc): void
    {
        $destino = (string)Tools::settings('presupuestosaceptacion', 'autoconvertir', '');
        if ('' === $destino || false === isset(self::DESTINOS[$destino])) {
            return; // desactivada por defecto
        }
        $previo = Session::get('user');
        $user = new User();
        if ($user->load((string)$row->nick)) {
            Session::set('user', $user);
        }
        $doc->load($doc->id());
        self::convertir($doc, $destino);
        Session::set('user', $previo);
    }

    protected static function avisarEmisor(PresupuestoAceptacion $row, PresupuestoCliente $doc): void
    {
        try {
            $user = new User();
            $para = $user->load((string)$row->nick) ? (string)$user->email : '';
            if (false === Validator::email($para)) {
                $para = (string)Empresas::default()->email;
            }
            $mail = NewMail::create();
            if (false === Validator::email($para) || false === $mail->canSendMail()) {
                return;
            }
            $ok = Embudo::ACEPTADO === $row->estado;
            $base = RecuperarClave::baseUrl();
            $mail->to($para)
                ->subject(Tools::trans($ok ? 'aceptacion-aviso-aceptado' : 'aceptacion-aviso-rechazado', ['%codigo%' => $doc->codigo]))
                ->addMainBlock(new TextBlock(Tools::trans($ok ? 'aceptacion-aviso-texto-ok' : 'aceptacion-aviso-texto-ko', [
                    '%codigo%' => $doc->codigo, '%cliente%' => $doc->nombrecliente,
                    '%nombre%' => (string)$row->nombre, '%motivo%' => (string)$row->motivo,
                    '%fecha%' => date('d-m-Y H:i', (int)$row->resuelto), '%ip%' => (string)$row->ip,
                ])));
            if ('' !== $base) {
                $mail->addMainBlock(new ButtonBlock(Tools::trans('aceptacion-aviso-abrir'), $base . '/EditPresupuestoCliente?code=' . $doc->id()));
            }
            $mail->send();
        } catch (Throwable $e) {
            Tools::log()->error('Aceptación: aviso al emisor: ' . $e->getMessage());
        }
    }

    private static function res(bool $ok, string $msg): array
    {
        return ['ok' => $ok, 'msg' => $msg];
    }
}
