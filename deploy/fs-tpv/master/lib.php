<?php
// Partes PURAS del agente MASTER de Facturalo (sin E/S del host): validacion, firma, maquina de estados, plan de pasos.
// Contrato: docs/FACTURALO-CONTROL-MASTER.md. Sin dependencias (solo nucleo de PHP: json, hash).
declare(strict_types=1);

namespace FsMaster;

const VERSION = 1;
const BASE_DOMAIN = 'facturalo.redtelecom.app';
const ACTIONS = ['provision', 'suspend', 'reactivate', 'status', 'entitlements', 'ejercicio', 'preflight', 'deprovision-dry-run'];
const MODULES = ['impagos', 'sepa', 'cobros', 'trimestral', 'aceptacion', 'anticipos', 'recurrentes'];
// Clave de modulo -> plugin de FS que la implementa (el plugin siempre se activa; el ajuste facturalo.modulos.<clave> lo enciende o apaga).
const MODULE_PLUGINS = [
    'impagos' => 'RecordatorioImpagosFacturalo', 'sepa' => 'RemesasSepaFacturalo', 'cobros' => 'CobrosRapidosFacturalo',
    'trimestral' => 'PaqueteTrimestralFacturalo', 'aceptacion' => 'PresupuestosAceptacionFacturalo',
    'anticipos' => 'AnticiposFacturalo', 'recurrentes' => 'FacturasRecurrentes',
];
const RESERVED_SLUGS = [
    'master', 'www', 'api', 'admin', 'mail', 'smtp', 'imap', 'ftp', 'ns1', 'ns2', 'app', 'panel', 'portal', 'status', 'staging',
    'test', 'demo', 'motor', 'facturalo', 'teleco', 'clarto', 'redtelecom', 'tpv', 'root', 'localhost', 'default', 'cdn', 'static',
    'assets', 'dev', 'prod', 'backup', 'dns', 'vpn', 'git', 'ci', 'support', 'soporte', 'billing', 'ploi',
];

/** Slug de tenant: 2-28 car., a-z0-9 y guion, sin guion inicial/final ni "--" (IDN/xn--), no reservado. Devuelve motivo o null si vale. */
function slug_error(mixed $s): ?string
{
    if (!is_string($s) || !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,26}[a-z0-9])\z/', $s)) {
        return 'slug: 2-28 caracteres a-z, 0-9 y guion, sin guion al inicio ni al final';
    }
    if (str_contains($s, '--')) {
        return 'slug: no se admite "--"';
    }
    if (in_array($s, RESERVED_SLUGS, true) || str_starts_with($s, 'teleco-') || str_starts_with($s, 'fs-')) {
        return 'slug reservado';
    }
    return null;
}

function hostname_for(string $slug): string
{
    return $slug . '.' . BASE_DOMAIN;
}

function is_ulid(mixed $v): bool
{
    return is_string($v) && (bool)preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $v);
}

/** JSON canonico: claves ordenadas recursivamente, sin escapar "/" ni unicode, sin espacios. Es lo que se firma. */
function canonical(mixed $v): string
{
    $sort = function (mixed $x) use (&$sort) {
        if (!is_array($x)) {
            return $x;
        }
        $x = array_map($sort, $x);
        if (!array_is_list($x)) {
            ksort($x, SORT_STRING);
        }
        return $x;
    };
    return json_encode($sort($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
}

/** Firma HMAC-SHA256 con separacion de dominio ('req' / 'resp'): una respuesta nunca vale como peticion. */
function sign(array $doc, string $key, string $domain): string
{
    unset($doc['sig']);
    return hash_hmac('sha256', $domain . "\n" . canonical($doc), $key);
}

/** @param array<string,string> $keys kid => clave (>=32 bytes). Devuelve motivo de error o null si la firma es valida. */
function verify(array $doc, array $keys, string $domain): ?string
{
    $s = $doc['sig'] ?? null;
    if (!is_array($s) || ($s['alg'] ?? null) !== 'hmac-sha256' || !is_string($s['kid'] ?? null) || !is_string($s['value'] ?? null)) {
        return 'firma ausente o con formato invalido';
    }
    $key = $keys[$s['kid']] ?? null;
    if ($key === null || strlen($key) < 32) {
        return 'kid desconocido';
    }
    return hash_equals(sign($doc, $key, $domain), $s['value']) ? null : 'firma no valida';
}

function text_ok(mixed $v, int $max, bool $required = true): bool
{
    if ($v === null || $v === '') {
        return !$required;
    }
    return is_string($v) && strlen($v) <= $max && !preg_match('/[\x00-\x1F\x7F]/', $v) && trim($v) === $v;
}

/** Validacion estricta del sobre ya parseado. Devuelve lista de errores (vacia = valido). Campos desconocidos = error. */
function validate_request(array $r, int $now, string $fileId): array
{
    $e = [];
    $allowed = ['version', 'request_id', 'action', 'tenant', 'entitlements', 'release', 'requested_at', 'requested_by', 'sig'];
    if ($x = array_diff(array_keys($r), $allowed)) {
        $e[] = 'campos desconocidos: ' . implode(',', $x);
    }
    if (($r['version'] ?? null) !== VERSION) {
        $e[] = 'version no soportada';
    }
    if (!is_ulid($r['request_id'] ?? null) || $r['request_id'] !== $fileId) {
        $e[] = 'request_id invalido o distinto del nombre de fichero';
    }
    $action = $r['action'] ?? null;
    if (!in_array($action, ACTIONS, true)) {
        $e[] = 'accion invalida';
        return $e;
    }
    if (!text_ok($r['requested_by'] ?? null, 100)) {
        $e[] = 'requested_by invalido';
    }
    $at = (string)($r['requested_at'] ?? '');
    $t = preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $at) ? strtotime($at) : false;
    if ($t === false) {
        $e[] = 'requested_at: ISO-8601 UTC (YYYY-MM-DDThh:mm:ssZ)';
    } elseif ($t > $now + 300) {
        $e[] = 'requested_at en el futuro';
    } elseif ($t < $now - 86400) {
        $e[] = 'peticion caducada (>24 h)';
    }
    $tn = $r['tenant'] ?? null;
    if (!is_array($tn)) {
        $e[] = 'tenant requerido';
        return $e;
    }
    $tallowed = $action === 'provision' ? ['id', 'slug', 'hostname', 'trade_name', 'legal_name', 'admin_email', 'company'] : ['id', 'slug', 'hostname'];
    if ($x = array_diff(array_keys($tn), $tallowed)) {
        $e[] = 'tenant: campos no permitidos para ' . $action . ': ' . implode(',', $x);
    }
    if (!is_ulid($tn['id'] ?? null)) {
        $e[] = 'tenant.id: ULID';
    }
    if ($m = slug_error($tn['slug'] ?? null)) {
        $e[] = $m;
    } elseif (($tn['hostname'] ?? null) !== hostname_for($tn['slug'])) {
        $e[] = 'tenant.hostname debe ser <slug>.' . BASE_DOMAIN;
    }
    if ($action === 'provision') {
        if (!text_ok($tn['trade_name'] ?? null, 100)) {
            $e[] = 'trade_name invalido';
        }
        if ($m = legal_name_error($tn['legal_name'] ?? null)) {
            $e[] = $m;
        }
        if (!is_string($tn['admin_email'] ?? null) || !preg_match('/\A[A-Za-z0-9._%+-]{1,64}@([a-z0-9-]+\.)+[a-z]{2,63}\z/', $tn['admin_email'])) {
            $e[] = 'admin_email invalido';
        }
        $c = $tn['company'] ?? null;
        $cf = ['cifnif' => 20, 'direccion' => 100, 'ciudad' => 100, 'provincia' => 100, 'codpostal' => 10, 'email' => 100];
        $req = ['cifnif', 'direccion', 'ciudad', 'provincia', 'codpostal'];
        if (!is_array($c) || array_diff(array_keys($c), array_keys($cf))) {
            $e[] = 'company requerida (' . implode(',', array_keys($cf)) . ')';
        } else {
            foreach ($cf as $k => $max) {
                if (!text_ok($c[$k] ?? null, $max, in_array($k, $req, true))) {
                    $e[] = "company.$k invalido";
                }
            }
        }
    }
    if (in_array($action, ['provision', 'entitlements'], true)) {
        $e = array_merge($e, entitlements_errors($r['entitlements'] ?? null, $action === 'entitlements'));
    } elseif (isset($r['entitlements'])) {
        $e[] = 'entitlements solo en provision y entitlements';
    }
    if (isset($r['release'])) {
        $rel = $r['release'];
        if ($action !== 'provision' || !is_array($rel) || array_keys($rel) !== ['tag'] || !is_string($rel['tag'])
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $rel['tag'])) {
            $e[] = 'release: solo en provision, {"tag":"<etiqueta>"}';
        }
    }
    return $e;
}

/** entitlements = {"serial": int>=1, "modulos": {las 7 claves: bool}}. En provision puede omitirse (todo apagado). */
function entitlements_errors(mixed $en, bool $required): array
{
    if ($en === null) {
        return $required ? ['entitlements requerido'] : [];
    }
    if (!is_array($en) || array_diff(array_keys($en), ['serial', 'modulos']) || !isset($en['serial'], $en['modulos'])) {
        return ['entitlements: {serial, modulos}'];
    }
    $e = [];
    if (!is_int($en['serial']) || $en['serial'] < 1) {
        $e[] = 'entitlements.serial: entero >= 1';
    }
    $m = $en['modulos'];
    if (!is_array($m) || array_diff(array_keys($m), MODULES) || array_diff(MODULES, array_keys($m))) {
        $e[] = 'entitlements.modulos: exactamente ' . implode(',', MODULES);
    } else {
        foreach ($m as $k => $v) {
            if (!is_bool($v)) {
                $e[] = "entitlements.modulos.$k: booleano";
            }
        }
    }
    return $e;
}

/** Ajustes a escribir en settings(name='facturalo'): siempre las 7 claves explicitas ('1'/'0'); sin valor los plugins asumen ACTIVO. */
function entitlement_settings(?array $en): array
{
    $out = [];
    foreach (MODULES as $k) {
        $out['modulos.' . $k] = ($en['modulos'][$k] ?? false) === true ? '1' : '0';
    }
    return $out;
}

// ---- Maquina de estados del tenant: absent -> provisioning -> active <-> suspended ; provisioning -> error -> provisioning
const STATES = ['absent', 'provisioning', 'active', 'suspended', 'error'];

/** @return array{ok:bool,noop:bool,next:?string,reason:?string} */
function transition(string $state, string $action): array
{
    $ok = fn(string $n, bool $noop = false) => ['ok' => true, 'noop' => $noop, 'next' => $n, 'reason' => null];
    $no = fn(string $why) => ['ok' => false, 'noop' => false, 'next' => null, 'reason' => $why];
    return match ($action) {
        'provision' => match ($state) {
            'absent', 'error', 'provisioning' => $ok('active'),
            'active', 'suspended' => $ok($state, true),
            default => $no('estado desconocido'),
        },
        'suspend' => match ($state) {
            'active' => $ok('suspended'), 'suspended' => $ok('suspended', true),
            default => $no("no se puede suspender un tenant en estado $state"),
        },
        'reactivate' => match ($state) {
            'suspended' => $ok('active'), 'active' => $ok('active', true),
            default => $no("no se puede reactivar un tenant en estado $state"),
        },
        'entitlements', 'ejercicio' => in_array($state, ['active', 'suspended'], true) ? $ok($state) : $no("la accion requiere active o suspended (hay $state)"),
        'status', 'preflight', 'deprovision-dry-run' => $ok($state),
        default => $no('accion invalida'),
    };
}

/** Plan ordenado de pasos por accion. Cada paso es idempotente; el agente registra los hechos y los salta al reanudar. */
function plan(string $action): array
{
    return match ($action) {
        'provision' => ['preflight', 'bind', 'provision', 'bootstrap', 'deploy', 'company', 'ejercicio', 'email', 'entitlements', 'verify', 'admin'],
        'suspend' => ['suspend'], 'ejercicio' => ['ejercicio'], 'reactivate' => ['reactivate'], 'entitlements' => ['entitlements'],
        'status' => ['status'], 'preflight' => ['preflight'], 'deprovision-dry-run' => ['deprovision-dry-run'],
    };
}

/** Nick de FS para el propietario a partir del correo: a-z0-9._- , max 30. */
function owner_nick(string $email): string
{
    $n = preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email)[0]));
    return substr($n === '' ? 'propietario' : $n, 0, 30);
}

/** Respuesta (sin firmar). $steps: [{name,status,ms,detail}] */
function response(array $req, string $status, string $tenantState, array $steps, array $result = [], ?array $error = null, int $now = 0): array
{
    return [
        'version' => VERSION, 'request_id' => $req['request_id'], 'action' => $req['action'], 'tenant_id' => $req['tenant']['id'] ?? null,
        'status' => $status, // succeeded | failed | rejected
        'tenant_state' => $tenantState, 'steps' => $steps, 'result' => $result, 'error' => $error,
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?: time()),
    ];
}

/** Plantilla de correo (grupo de ajustes `email` de FS) guardada en un fichero de root. Devuelve errores SIN incluir valores (pueden ser secretos). */
function email_template_errors(?string $json): array
{
    if ($json === null || $json === '') {
        return ['plantilla de correo ausente'];
    }
    $t = strlen($json) <= 4096 ? json_decode($json, true) : null;
    if (!is_array($t) || array_is_list($t)) {
        return ['plantilla de correo: JSON objeto de hasta 4 KiB'];
    }
    $e = [];
    foreach (['email', 'host', 'password'] as $k) {
        if (!is_string($t[$k] ?? null) || $t[$k] === '') {
            $e[] = "plantilla de correo: falta $k";
        }
    }
    foreach ($t as $k => $v) {
        if (!is_string($k) || !preg_match('/\A[a-z_][a-z0-9_]{0,40}\z/i', $k) || !(is_scalar($v) || $v === null)) {
            $e[] = 'plantilla de correo: claves y valores escalares simples';
            break;
        }
    }
    if (isset($t['host']) && is_string($t['host']) && !preg_match('/\A[A-Za-z0-9]([A-Za-z0-9.-]{0,253}[A-Za-z0-9])?\z/', $t['host'])) {
        $e[] = 'plantilla de correo: host invalido';
    }
    if (isset($t['port']) && (!is_numeric($t['port']) || (int)$t['port'] < 1 || (int)$t['port'] > 65535)) {
        $e[] = 'plantilla de correo: port invalido';
    }
    return $e;
}

/** Razon social CENSAL (la que valida la AEAT contra NIF): en mayusculas como en el censo/certificado, p.ej. "APELLIDOS NOMBRE" o "ACME SOCIEDAD LIMITADA". Distinta del nombre comercial. */
function legal_name_error(mixed $v): ?string
{
    if (!is_string($v) || $v === '' || strlen($v) > 100) {
        return 'legal_name: razon social censal obligatoria (1-100 caracteres), distinta del nombre comercial';
    }
    if (!text_ok($v, 100) || preg_match('/\s{2,}/', $v) || !preg_match('/\A[\p{Lu}\p{N}][\p{Lu}\p{N} .,&\'\/()ºª-]*\z/u', $v) || preg_match('/\p{Ll}/u', $v)) {
        return 'legal_name: solo mayusculas (como en el censo/certificado), digitos y . , & \' / ( ) - ; sin minusculas ni espacios dobles';
    }
    return null;
}

/**
 * Ejercicios que faltan para cubrir el ano en curso y los $count-1 siguientes. Nunca toca los existentes: si el rango del ano
 * se solapa con alguno, no se crea. Codigo = ano (como FS: Ejercicio::createNew; alternativa "00yy"). longsubcuenta = el del mas reciente.
 * @param list<array{codejercicio:string,fechainicio:string,fechafin:string,longsubcuenta:mixed}> $existing fechas Y-m-d
 * @return array{crear:list<array<string,mixed>>,existentes:list<string>,error:?string}
 */
function exercises_to_create(array $existing, int $year, int $count = 2): array
{
    $long = 10;
    $latest = '';
    $codes = [];
    foreach ($existing as $e) {
        $codes[$e['codejercicio']] = true;
        if ($e['fechainicio'] > $latest) {
            $latest = $e['fechainicio'];
            $long = (int)$e['longsubcuenta'] ?: 10;
        }
    }
    $out = ['crear' => [], 'existentes' => [], 'error' => null];
    for ($y = $year; $y < $year + $count; $y++) {
        [$ini, $fin] = ["$y-01-01", "$y-12-31"];
        $hit = null;
        foreach ($existing as $e) {
            if ($e['fechainicio'] <= $fin && $e['fechafin'] >= $ini) {
                $hit = $e['codejercicio'];
                break;
            }
        }
        if ($hit !== null) {
            $out['existentes'][] = $hit;
            continue;
        }
        $code = (string)$y;
        if (isset($codes[$code])) {
            $code = sprintf('00%02d', $y % 100);
        }
        if (isset($codes[$code])) {
            $out['error'] = "sin codigo libre para el ejercicio $y";
            return $out;
        }
        $codes[$code] = true;
        $out['crear'][] = ['codejercicio' => $code, 'nombre' => (string)$y, 'fechainicio' => $ini, 'fechafin' => $fin, 'longsubcuenta' => $long];
    }
    return $out;
}
