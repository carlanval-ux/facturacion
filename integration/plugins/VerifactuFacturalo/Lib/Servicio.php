<?php
/**
 * VerifactuFacturalo - Orquestación: genera los registros de alta/anulación al emitir facturas, los encadena, los envía a la AEAT y guarda el resultado.
 * El destino (preproducción o producción) lo decide ÚNICAMENTE el check «Modo pruebas» de la empresa (VfpConfig::modo_pruebas) a través de Endpoint.
 * Licencia LGPL-3.0-or-later.
 */

namespace FacturaScripts\Plugins\VerifactuFacturalo\Lib;

use DateTimeImmutable;
use DateTimeZone;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpCertificado;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpConfig;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpEvento;
use FacturaScripts\Plugins\VerifactuFacturalo\Model\VfpRegistro;
use Throwable;

class Servicio
{
    const GRUPO = 'verifactufacturalo';

    /** Campos de la factura que, una vez registrada, no se pueden cambiar. */
    const CAMPOS_BLOQUEADOS = ['fecha', 'codcliente', 'cifnif', 'nombrecliente', 'neto', 'totaliva', 'totalrecargo', 'total', 'codserie', 'numero', 'codigo', 'idfacturarect', 'idempresa', 'codejercicio'];

    /** Solo para pruebas: sustituye al transporte real. callable(string $url, bool $pruebas, string $xml, array $cert): string */
    public static $transporte = null;

    /** El plugin comercial «Verifactu» y este no pueden estar activos a la vez. */
    public static function conflicto(): bool
    {
        return \FacturaScripts\Core\Plugins::isEnabled('Verifactu');
    }

    // ---------------------------------------------------------------- registros

    /** Registra la factura si procede. Nunca rompe el guardado de la factura: cualquier fallo queda en el registro de eventos. */
    public static function registrarSiProcede(FacturaCliente $f): ?VfpRegistro
    {
        try {
            if ($f->editable || empty($f->idfactura)) {
                return null;
            }
            $cfg = VfpConfig::deEmpresa((int)$f->idempresa);
            if (false === $cfg->activo() || (!empty($cfg->desde) && strtotime($f->fecha) < strtotime($cfg->desde))) {
                return null;
            }
            if (self::registroDeAlta((int)$f->idfactura)) {
                return null;
            }
            return self::crearAlta($f, $cfg);
        } catch (Throwable $e) {
            VfpEvento::registrar((int)$f->idempresa, 'error-registro', $f->codigo . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Registro de alta vigente (no rechazado) de una factura, en cualquier entorno. */
    public static function registroDeAlta(int $idfactura): ?VfpRegistro
    {
        foreach (VfpRegistro::all([Where::eq('idfactura', $idfactura), Where::eq('tipo', 'alta')], ['id' => 'DESC']) as $r) {
            if ($r->bloqueaFactura()) {
                return $r;
            }
        }
        return null;
    }

    public static function bloqueada(int $idfactura): bool
    {
        return null !== self::registroDeAlta($idfactura);
    }

    private static function crearAlta(FacturaCliente $f, VfpConfig $cfg): ?VfpRegistro
    {
        $emisor = self::emisor((int)$f->idempresa);
        if (null === $emisor) {
            VfpEvento::registrar((int)$f->idempresa, 'error-registro', $f->codigo . ': la empresa no tiene NIF');
            return null;
        }
        $nifCliente = self::nif($f->cifnif);
        $rectificada = null;
        if (!empty($f->idfacturarect)) {
            $orig = new FacturaCliente();
            if ($orig->load($f->idfacturarect)) {
                $rectificada = ['numserie' => $orig->codigo, 'fecha' => date('Y-m-d', strtotime($orig->fecha))];
            }
        }
        $factura = [
            'numserie' => $f->codigo,
            'fecha' => date('Y-m-d', strtotime($f->fecha)),
            'tipo' => $rectificada ? 'R1' : ('' === $nifCliente ? 'F2' : 'F1'),
            'descripcion' => '',
            'desglose' => self::desglose($f),
            'destinatario' => '' === $nifCliente ? null : ['nombre' => $f->nombrecliente ?: $nifCliente, 'nif' => $nifCliente],
            'rectificada' => $rectificada,
        ];
        if (empty($factura['desglose'])) {
            return null;
        }
        $entorno = $cfg->entorno();
        for ($intento = 0; $intento < 2; $intento++) { // el UNIQUE de la cadena evita bifurcaciones por concurrencia: se reintenta con el nuevo último
            $ant = self::ultimo((int)$f->idempresa, $entorno);
            $r = Xml::alta([
                'emisor' => $emisor, 'sistema' => self::sistema($emisor, (int)$f->idempresa), 'factura' => $factura,
                'anterior' => $ant ? ['nif' => $emisor['nif'], 'numserie' => $ant->numserie, 'fecha' => $ant->fecha_expedicion, 'huella' => $ant->huella] : null,
                'momento' => self::ahora(),
            ]);
            $reg = new VfpRegistro();
            $reg->idempresa = (int)$f->idempresa;
            $reg->idfactura = (int)$f->idfactura;
            $reg->entorno = $entorno;
            $reg->tipo = 'alta';
            $reg->numserie = $f->codigo;
            $reg->fecha_expedicion = $factura['fecha'];
            $reg->importe_total = $r['importe_total'];
            $reg->huella = $r['huella'];
            $reg->huella_anterior = $ant->huella ?? '';
            $reg->fecha_hora = $r['fecha_hora'];
            $reg->xml = $r['xml'];
            $reg->creado = Tools::dateTime();
            $reg->qr_url = self::qrUrl($cfg->modo_pruebas, $emisor['nif'], $f->codigo, $factura['fecha'], $r['importe_total']);
            if ($reg->save()) {
                VfpEvento::registrar((int)$f->idempresa, 'registro-alta', $f->codigo . ' (' . $entorno . ')');
                return $reg;
            }
        }
        VfpEvento::registrar((int)$f->idempresa, 'error-registro', $f->codigo . ': no se pudo guardar el registro');
        return null;
    }

    /** Anulación del alta indicada (la factura queda anulada en el registro de la AEAT). */
    public static function anular(VfpRegistro $alta, ?string $nick = null): ?VfpRegistro
    {
        $cfg = VfpConfig::deEmpresa((int)$alta->idempresa);
        $emisor = self::emisor((int)$alta->idempresa);
        if ($alta->tipo !== 'alta' || null === $emisor || $alta->entorno !== $cfg->entorno()) {
            return null;
        }
        foreach (VfpRegistro::all([Where::eq('idempresa', $alta->idempresa), Where::eq('tipo', 'anulacion'), Where::eq('numserie', $alta->numserie), Where::eq('entorno', $alta->entorno)]) as $x) {
            return null; // ya anulada
        }
        $ant = self::ultimo((int)$alta->idempresa, $alta->entorno);
        $r = Xml::anulacion([
            'emisor' => $emisor, 'sistema' => self::sistema($emisor, (int)$alta->idempresa),
            'factura' => ['numserie' => $alta->numserie, 'fecha' => $alta->fecha_expedicion],
            'anterior' => $ant ? ['nif' => $emisor['nif'], 'numserie' => $ant->numserie, 'fecha' => $ant->fecha_expedicion, 'huella' => $ant->huella] : null,
            'momento' => self::ahora(),
        ]);
        $reg = new VfpRegistro();
        $reg->idempresa = (int)$alta->idempresa;
        $reg->idfactura = $alta->idfactura;
        $reg->entorno = $alta->entorno;
        $reg->tipo = 'anulacion';
        $reg->numserie = $alta->numserie;
        $reg->fecha_expedicion = $alta->fecha_expedicion;
        $reg->huella = $r['huella'];
        $reg->huella_anterior = $ant->huella ?? '';
        $reg->fecha_hora = $r['fecha_hora'];
        $reg->xml = $r['xml'];
        $reg->creado = Tools::dateTime();
        if (false === $reg->save()) {
            return null;
        }
        VfpEvento::registrar((int)$alta->idempresa, 'registro-anulacion', $alta->numserie, $nick);
        return $reg;
    }

    // ---------------------------------------------------------------- envío

    /** Envía los registros pendientes (o con error por reintentar) de las empresas activas. Devuelve el número de registros procesados. */
    public static function enviarPendientes(?int $idempresa = null, int $max = 100): int
    {
        $total = 0;
        foreach (VfpConfig::all(null === $idempresa ? [] : [Where::eq('idempresa', $idempresa)]) as $cfg) {
            if (false === $cfg->activo() || false === $cfg->tieneCertificado()) {
                continue;
            }
            if (!empty($cfg->proximo_envio) && strtotime($cfg->proximo_envio) > time()) {
                continue;
            }
            $total += self::enviarEmpresa($cfg, $max);
        }
        return $total;
    }

    private static function enviarEmpresa(VfpConfig $cfg, int $max): int
    {
        $ahora = Tools::dateTime();
        $where = [
            Where::eq('idempresa', $cfg->idempresa),
            Where::eq('entorno', $cfg->entorno()), // un registro generado en pruebas jamás sale a real ni al revés
            Where::in('estado', [VfpRegistro::PENDIENTE, VfpRegistro::ERROR]),
        ];
        $lote = array_slice(array_values(array_filter(VfpRegistro::all($where, ['id' => 'ASC']),
            fn($r) => empty($r->proximo_intento) || $r->proximo_intento <= $ahora)), 0, min($max, Xml::MAX_REGISTROS));
        if (empty($lote)) {
            return 0;
        }
        $emisor = self::emisor((int)$cfg->idempresa);
        $cert = VfpCertificado::descifrar((int)$cfg->idempresa);
        if (null === $emisor || null === $cert) {
            VfpEvento::registrar((int)$cfg->idempresa, 'error-envio', 'Falta el NIF de la empresa o no se puede descifrar el certificado');
            return 0;
        }
        $url = Endpoint::soap((bool)$cfg->modo_pruebas, (bool)$cfg->sello); // ÚNICA decisión de destino
        $xml = Xml::envio($emisor, array_map(fn($r) => $r->xml, $lote));
        try {
            $envio = self::$transporte ?? [Transporte::class, 'enviar'];
            $resp = Xml::respuesta($envio($url, (bool)$cfg->modo_pruebas, $xml, $cert));
        } catch (Throwable $e) {
            self::fallo($lote, 'RED', $e->getMessage());
            VfpEvento::registrar((int)$cfg->idempresa, 'error-envio', $e->getMessage());
            return count($lote);
        }
        if ($resp['fault']) {
            self::fallo($lote, 'FAULT', $resp['mensaje']);
            VfpEvento::registrar((int)$cfg->idempresa, 'error-envio', $resp['mensaje']);
            return count($lote);
        }
        $cfg->proximo_envio = date('Y-m-d H:i:s', time() + $resp['espera']);
        $cfg->save();
        self::aplicarRespuesta($lote, $resp);
        VfpEvento::registrar((int)$cfg->idempresa, 'envio', count($lote) . ' registros (' . $cfg->entorno() . '): ' . $resp['estado_envio']);
        return count($lote);
    }

    /** @param VfpRegistro[] $lote */
    public static function aplicarRespuesta(array $lote, array $resp): void
    {
        $mapa = ['Correcto' => VfpRegistro::ACEPTADO, 'AceptadoConErrores' => VfpRegistro::ACEPTADO_ERRORES, 'Incorrecto' => VfpRegistro::RECHAZADO];
        $usadas = [];
        foreach ($lote as $i => $r) {
            $linea = null;
            if (isset($resp['lineas'][$i]) && $resp['lineas'][$i]['numserie'] === $r->numserie) {
                $linea = $resp['lineas'][$i];
                $usadas[$i] = true;
            } else {
                foreach ($resp['lineas'] as $j => $l) {
                    if (empty($usadas[$j]) && $l['numserie'] === $r->numserie) {
                        $linea = $l;
                        $usadas[$j] = true;
                        break;
                    }
                }
            }
            $r->intentos++;
            $r->enviado = Tools::dateTime();
            if (null === $linea) { // sin línea de respuesta: el envío entero fue incorrecto (cabecera/estructura)
                $r->estado = VfpRegistro::ERROR;
                $r->mensaje = 'Envío ' . $resp['estado_envio'] . ' sin detalle para este registro';
                $r->proximo_intento = date('Y-m-d H:i:s', time() + self::espera($r->intentos));
            } else {
                $r->estado = $mapa[$linea['estado']] ?? VfpRegistro::ERROR;
                $r->csv = $resp['csv'];
                $r->codigo_error = $linea['codigo'];
                $r->mensaje = $linea['descripcion'];
                $r->proximo_intento = null;
            }
            $r->save();
        }
    }

    /** @param VfpRegistro[] $lote */
    private static function fallo(array $lote, string $codigo, string $mensaje): void
    {
        foreach ($lote as $r) {
            $r->intentos++;
            $r->estado = VfpRegistro::ERROR;
            $r->codigo_error = $codigo;
            $r->mensaje = mb_substr($mensaje, 0, 500);
            $r->proximo_intento = date('Y-m-d H:i:s', time() + self::espera($r->intentos));
            $r->save();
        }
    }

    /** Backoff exponencial: 1, 2, 4… minutos con tope de 6 horas. */
    public static function espera(int $intentos): int
    {
        return min(60 * (2 ** max(0, $intentos - 1)), 21600);
    }

    /** Cron: registra las facturas emitidas que se hayan escapado y envía los pendientes. */
    public static function cron(): void
    {
        if (self::conflicto()) {
            return;
        }
        foreach (VfpConfig::all([Where::eq('modo', VfpConfig::MODO_VERIFACTU)]) as $cfg) {
            $where = [Where::eq('idempresa', $cfg->idempresa), Where::eq('editable', false)];
            if (!empty($cfg->desde)) {
                $where[] = Where::gte('fecha', $cfg->desde);
            }
            foreach (FacturaCliente::all($where, ['idfactura' => 'ASC'], 0, 200) as $f) {
                self::registrarSiProcede($f);
            }
        }
        self::enviarPendientes();
    }

    // ---------------------------------------------------------------- certificado y comprobación

    /** @return string '' si todo bien; si no, el motivo */
    public static function guardarCertificado(int $idempresa, string $p12, string $clave, ?string $nick = null): string
    {
        $info = Certificado::leer($p12, $clave);
        if (is_string($info)) {
            return $info;
        }
        if (false === VfpCertificado::guardar($idempresa, $p12, $clave)) {
            return 'No se puede guardar cifrado (falta FS_APP_KEY en config.php o la extensión openssl); el certificado NO se ha guardado.';
        }
        $cfg = VfpConfig::deEmpresa($idempresa);
        $cfg->cert_nombre = $info['nombre'];
        $cfg->cert_vencimiento = $info['vencimiento'];
        $cfg->save();
        VfpEvento::registrar($idempresa, 'certificado', 'Certificado «' . $info['nombre'] . '» válido hasta ' . $info['vencimiento'], $nick);
        return '';
    }

    /** @return array<int,array{0:bool,1:string}> lista de [ok, texto] */
    public static function comprobar(int $idempresa, bool $conexion = true): array
    {
        $r = [];
        $cfg = VfpConfig::deEmpresa($idempresa);
        $r[] = [extension_loaded('openssl'), 'Extensión openssl de PHP'];
        $r[] = [\FacturaScripts\Core\AppKey::canEncrypt(), 'FS_APP_KEY presente (necesaria para guardar el certificado cifrado)'];
        $r[] = [false === self::conflicto(), 'Plugin comercial «Verifactu» no activo'];
        $emisor = self::emisor($idempresa);
        $r[] = [null !== $emisor, 'NIF de la empresa' . ($emisor ? ' (' . $emisor['nif'] . ')' : '')];
        $cert = VfpCertificado::descifrar($idempresa);
        $info = $cert ? Certificado::leer($cert['p12'], $cert['clave']) : 'No hay certificado cargado o no se puede descifrar.';
        $r[] = [is_array($info), is_array($info) ? 'Certificado «' . $info['nombre'] . '» válido hasta ' . $info['vencimiento'] : (string)$info];
        $r[] = [true, 'Destino: ' . ($cfg->modo_pruebas ? 'PREPRODUCCIÓN (modo pruebas)' : 'PRODUCCIÓN (real)') . ' → ' . Endpoint::host((bool)$cfg->modo_pruebas, (bool)$cfg->sello)];
        if ($conexion && is_array($info)) {
            $err = Transporte::handshake(Endpoint::soap((bool)$cfg->modo_pruebas, (bool)$cfg->sello), (bool)$cfg->modo_pruebas, $cert);
            $r[] = [null === $err, null === $err ? 'Conexión TLS con la AEAT con el certificado de cliente' : (string)$err];
        }
        return $r;
    }

    // ---------------------------------------------------------------- utilidades

    public static function ultimo(int $idempresa, string $entorno): ?VfpRegistro
    {
        $l = VfpRegistro::all([Where::eq('idempresa', $idempresa), Where::eq('entorno', $entorno)], ['id' => 'DESC'], 0, 1);
        return $l[0] ?? null;
    }

    public static function emisor(int $idempresa): ?array
    {
        $e = new Empresa();
        if (false === $e->load($idempresa) || '' === self::nif($e->cifnif)) {
            return null;
        }
        return ['nif' => self::nif($e->cifnif), 'nombre' => $e->nombre];
    }

    /** Identificación del sistema informático (productor del software): ajustes verifactufacturalo.sif_nombre / sif_nif. */
    public static function sistema(array $emisor, int $idempresa): array
    {
        return [
            'nombre' => (string)(Tools::settings(self::GRUPO, 'sif_nombre') ?: 'RED TELECOM'),
            'nif' => (string)(Tools::settings(self::GRUPO, 'sif_nif') ?: $emisor['nif']),
            'id' => 'FV',
            'version' => '0.1',
            'instalacion' => (string)$idempresa,
        ];
    }

    public static function nif(?string $v): string
    {
        return strtoupper(preg_replace('/[\s\-.]/', '', (string)$v));
    }

    public static function qrUrl(bool $pruebas, string $nif, string $numserie, string $fechaYmd, string $importe): string
    {
        return Endpoint::qr($pruebas) . '?' . http_build_query(['nif' => $nif, 'numserie' => $numserie, 'fecha' => Xml::fecha($fechaYmd), 'importe' => $importe], '', '&', PHP_QUERY_RFC3986);
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
    }

    /** Desglose por tipo de IVA/recargo desde los subtotales del propio cálculo de FacturaScripts (descuentos globales incluidos). */
    private static function desglose(FacturaCliente $f): array
    {
        $out = [];
        $sub = Calculator::getSubtotals($f, $f->getLines());
        foreach ($sub['iva'] as $g) {
            $base = (float)$g['neto'];
            if (abs($base) < 0.005 && abs((float)$g['totaliva']) < 0.005) {
                continue;
            }
            $out[] = ['iva' => (float)$g['iva'], 'base' => $base, 'cuota' => (float)$g['totaliva'], 'recargo' => (float)$g['recargo'], 'cuota_recargo' => (float)$g['totalrecargo']];
        }
        return $out;
    }
}
