<?php
// Pruebas del agente MASTER: partes puras + dry-run completo con host simulado (SimHost). No toca ningun servidor ni DNS.
// Ejecutar:  php deploy/fs-tpv/master/tests/run.php     (sale con codigo != 0 si algo falla)
declare(strict_types=1);

namespace FsMaster;

require __DIR__ . '/../agent.php';

$fail = 0;
$total = 0;
function ok(bool $c, string $name, string $extra = ''): void
{
    global $fail, $total;
    $total++;
    if (!$c) {
        $fail++;
        echo "FAIL  $name $extra\n";
    }
}

const KEY = 'k-de-prueba-0123456789abcdef0123456789abcdef';
const T0 = 1795600000; // instante fijo del reloj simulado
$ULIDS = ['01JD0000000000000000000001', '01JD0000000000000000000002', '01JD0000000000000000000003', '01JD0000000000000000000004', '01JD0000000000000000000005',
    '01JD0000000000000000000006', '01JD0000000000000000000007', '01JD0000000000000000000008', '01JD0000000000000000000009', '01JD000000000000000000000A'];

// ---------- slug ----------
foreach (['acme', 'a1', 'mi-gestoria', 'x' . str_repeat('a', 26) . 'z'] as $s) {
    ok(slug_error($s) === null, "slug valido $s");
}
foreach (['a', '-ab', 'ab-', 'A', 'a_b', 'a.b', 'a b', '', str_repeat('a', 29), 'a--b', 'xn--abc', 'master', 'www', 'redtelecom', 'motor', 'teleco-test', 'fs-x', '../x', "a\nb", 'ab;rm'] as $s) {
    ok(slug_error($s) !== null, 'slug rechazado ' . json_encode($s));
}
ok(slug_error(5) !== null && slug_error(null) !== null, 'slug no cadena');

// ---------- firma ----------
$doc = ['b' => 1, 'a' => ['z' => '/ñ', 'y' => [3, 2]], 'sig' => 'x'];
ok(canonical($doc) === '{"a":{"y":[3,2],"z":"/ñ"},"b":1,"sig":"x"}', 'canonico: claves ordenadas, listas intactas, sin escapes', canonical($doc));
$sig = fn(array $d, string $dom = 'req', string $kid = 'm1', string $key = KEY) => ['alg' => 'hmac-sha256', 'kid' => $kid, 'value' => sign($d, $key, $dom)];
$d = ['a' => 1];
$d['sig'] = $sig($d);
ok(verify($d, ['m1' => KEY], 'req') === null, 'firma valida');
ok(verify($d, ['m1' => KEY], 'resp') !== null, 'separacion de dominio req/resp');
ok(verify($d, ['m2' => KEY], 'req') !== null, 'kid desconocido');
ok(verify($d, ['m1' => 'corta'], 'req') !== null, 'clave corta rechazada');
$t = $d; $t['a'] = 2;
ok(verify($t, ['m1' => KEY], 'req') !== null, 'documento manipulado');
$t = $d; unset($t['sig']);
ok(verify($t, ['m1' => KEY], 'req') !== null, 'sin firma');
$t = $d; $t['sig']['alg'] = 'none';
ok(verify($t, ['m1' => KEY], 'req') !== null, 'alg none rechazado');
echo 'vector de prueba (para el cliente del MASTER): canonical=' . canonical(['b' => 1, 'a' => 'x/y']) . ' hmac=' . hash_hmac('sha256', "req\n" . canonical(['b' => 1, 'a' => 'x/y']), KEY) . "\n";

// ---------- validacion de peticiones ----------
function req(string $action, array $over = [], ?string $id = null): array
{
    global $ULIDS;
    $slug = $over['slug'] ?? 'acme';
    unset($over['slug']);
    $tn = ['id' => '01JD00000000000000000000T1', 'slug' => $slug, 'hostname' => hostname_for($slug)];
    if ($action === 'provision') {
        $tn += ['trade_name' => 'Acme Gestion SL', 'admin_email' => 'ana.lopez@acme.example', 'company' => ['cifnif' => 'B12345674', 'direccion' => 'Calle Mayor 1', 'ciudad' => 'Madrid', 'provincia' => 'Madrid', 'codpostal' => '28001']];
    }
    $r = ['version' => 1, 'request_id' => $id ?? $ULIDS[0], 'action' => $action, 'tenant' => $tn, 'requested_at' => gmdate('Y-m-d\TH:i:s\Z', T0), 'requested_by' => 'operador:ana'];
    if (in_array($action, ['provision', 'entitlements'], true)) {
        $r['entitlements'] = ['serial' => 1, 'modulos' => array_fill_keys(MODULES, false)];
        $r['entitlements']['modulos']['impagos'] = true;
    }
    return array_replace_recursive($r, $over);
}
$v = fn(array $r) => validate_request($r, T0 + 10, $r['request_id']);
ok($v(req('provision')) === [], 'provision valido', json_encode($v(req('provision'))));
foreach (ACTIONS as $a) {
    ok($v(req($a)) === [], "accion $a valida", json_encode($v(req($a))));
}
$bad = [
    'campo extra' => req('suspend') + ['x' => 1],
    'version' => req('suspend', ['version' => 2]),
    'accion' => req('suspend', ['action' => 'deprovision']),
    'hostname ajeno' => req('suspend', ['tenant' => ['hostname' => 'acme.evil.example']]),
    'hostname otro slug' => req('suspend', ['tenant' => ['hostname' => 'otro.facturalo.redtelecom.app']]),
    'tenant.id' => req('suspend', ['tenant' => ['id' => 'abc']]),
    'caducada' => req('suspend', ['requested_at' => gmdate('Y-m-d\TH:i:s\Z', T0 - 90000)]),
    'futuro' => req('suspend', ['requested_at' => gmdate('Y-m-d\TH:i:s\Z', T0 + 3600)]),
    'fecha formato' => req('suspend', ['requested_at' => '2026-11-25 10:00:00']),
    'requested_by con control' => req('suspend', ['requested_by' => "a\nb"]),
    'entitlements en suspend' => req('suspend') + ['entitlements' => ['serial' => 1, 'modulos' => []]],
    'modulo desconocido' => req('entitlements', ['entitlements' => ['modulos' => ['hack' => true]]]),
    'modulo no bool' => req('entitlements', ['entitlements' => ['modulos' => ['sepa' => 'si']]]),
    'serial 0' => req('entitlements', ['entitlements' => ['serial' => 0]]),
    'release en suspend' => req('suspend') + ['release' => ['tag' => 'v1']],
    'release traversal' => req('provision') + ['release' => ['tag' => '../../etc/passwd']],
    'email malo' => req('provision', ['tenant' => ['admin_email' => 'x@y']]),
    'company falta' => (function () { $r = req('provision'); unset($r['tenant']['company']['cifnif']); return $r; })(),
    'company extra' => req('provision', ['tenant' => ['company' => ['x' => 'y']]]),
    'slug reservado' => req('suspend', ['slug' => 'master', 'tenant' => ['hostname' => 'master.facturalo.redtelecom.app']]),
];
foreach ($bad as $n => $r) {
    ok($v($r) !== [], "rechaza: $n");
}
ok(validate_request(req('suspend'), T0, '01JD0000000000000000000009') !== [], 'request_id distinto del nombre de fichero');
ok(entitlement_settings(null) === array_combine(array_map(fn($k) => "modulos.$k", MODULES), array_fill(0, 7, '0')), 'sin entitlements = los 7 modulos a 0');
ok(owner_nick('Ana.Lopez+x@acme.example') === 'ana.lopezx' && owner_nick('@x.es') === 'propietario', 'nick del propietario');

// ---------- maquina de estados ----------
$T = [
    ['absent', 'provision', true, false, 'active'], ['error', 'provision', true, false, 'active'], ['provisioning', 'provision', true, false, 'active'],
    ['active', 'provision', true, true, 'active'], ['active', 'suspend', true, false, 'suspended'], ['suspended', 'suspend', true, true, 'suspended'],
    ['absent', 'suspend', false, false, null], ['error', 'suspend', false, false, null], ['suspended', 'reactivate', true, false, 'active'],
    ['active', 'reactivate', true, true, 'active'], ['absent', 'reactivate', false, false, null], ['active', 'entitlements', true, false, 'active'],
    ['suspended', 'entitlements', true, false, 'suspended'], ['absent', 'entitlements', false, false, null], ['provisioning', 'entitlements', false, false, null],
    ['absent', 'status', true, false, 'absent'], ['active', 'deprovision-dry-run', true, false, 'active'],
];
foreach ($T as [$s, $a, $okv, $noop, $next]) {
    $r = transition($s, $a);
    ok($r['ok'] === $okv && $r['noop'] === $noop && $r['next'] === $next, "transicion $s --$a-->", json_encode($r));
}

// ---------- agente en dry-run con SimHost ----------
$tmp = sys_get_temp_dir() . '/fsm-' . bin2hex(random_bytes(4));
$mk = function () use ($tmp): array {
    $d = $tmp . '/' . bin2hex(random_bytes(3));
    foreach (['q/requests', 'q/responses', 'keys', 'w'] as $s) {
        mkdir("$d/$s", 0700, true);
    }
    file_put_contents("$d/keys/m1.key", KEY);
    $h = new SimHost();
    $h->dns['acme.facturalo.redtelecom.app'] = ['151.80.149.132'];
    $h->files['/home/ploi/fs-releases/v1.tar.gz'] = 'x';
    $h->files['/home/ploi/fs-releases/v1.tar.gz.sha256'] = 'x';
    $h->files['/etc/fs-master/default-release'] = "v1\n";
    $now = T0 + 20;
    $a = new Agent(array_merge(Agent::CONF_DEFAULTS, ['queue' => "$d/q", 'work' => "$d/w", 'keys' => "$d/keys"]), $h, function () use (&$now) { return $now; });
    return [$d, $h, $a];
};
$drop = function (string $d, array $r, ?string $key = KEY) {
    if ($key) {
        $r['sig'] = ['alg' => 'hmac-sha256', 'kid' => 'm1', 'value' => sign($r, $key, 'req')];
    }
    file_put_contents("$d/q/requests/{$r['request_id']}.json", json_encode($r));
};
$resp = fn(string $d, string $id) => json_decode((string)file_get_contents("$d/q/responses/$id.json"), true);
$names = fn(array $r) => array_map(fn($s) => $s['name'] . ':' . $s['status'], $r['steps']);

// 1) provision completo
[$d, $h, $a] = $mk();
$drop($d, req('provision'));
ok($a->runOnce() === 1, 'se atiende 1 peticion');
$r = $resp($d, $ULIDS[0]);
ok($r['status'] === 'succeeded' && $r['tenant_state'] === 'active', 'provision: succeeded/active', json_encode($r['error'] ?? null));
ok(verify($r, ['m1' => KEY], 'resp') === null, 'la respuesta va firmada (dominio resp)');
ok($names($r) === ['preflight:ok', 'bind:ok', 'provision:ok', 'bootstrap:ok', 'deploy:ok', 'company:ok', 'entitlements:ok', 'verify:ok', 'admin:ok'], 'orden de pasos', implode(',', $names($r)));
$pw = $r['result']['admin']['initial_password'] ?? '';
ok(strlen($pw) === 20 && $r['result']['admin']['nick'] === 'ana.lopez', 'admin creado con clave inicial de 20 car.');
ok(!str_contains(json_encode($h->log), $pw), 'la clave en claro no aparece en comandos ni SQL (solo hash)');
$cmds = array_values(array_filter($h->log, fn($l) => str_starts_with($l, 'exec /usr/local/sbin')));
ok(count($cmds) === 3 && str_contains($cmds[0], 'fs-provision-tenant acme acme.facturalo.redtelecom.app /home/ploi/fs-releases/v1.tar.gz') && str_contains($cmds[1], 'fs-bootstrap-tenant acme') && str_contains($cmds[1], 'FS_EXTRA_PLUGINS') && str_contains($cmds[2], 'fs-deploy-release acme'), 'comandos de host en orden', implode(' | ', $cmds));
$settings = array_filter($h->log, fn($l) => str_starts_with($l, 'sql fs_acme: INSERT INTO settings'));
ok(count($settings) === 7, '7 ajustes facturalo.modulos.* escritos', (string)count($settings));
ok(str_contains(implode("\n", $settings), '["modulos.impagos","1"') && str_contains(implode("\n", $settings), '["modulos.sepa","0"'), 'impagos=1, sepa=0');
$bind = json_decode($h->files['/etc/fs/tenants/acme.master.json'], true);
ok($bind['state'] === 'active' && $bind['tenant_id'] === '01JD00000000000000000000T1' && $bind['entitlements_serial'] === 1, 'registro slug<->tenant en active');
ok(!str_contains((string)file_get_contents("$d/w/state/{$ULIDS[0]}.json"), $pw), 'la clave inicial se borra del estado tras entregarla');
// auditoria: cadena de hashes
$lines = file("$d/w/audit.log", FILE_IGNORE_NEW_LINES);
$prev = '';
$chain = true;
foreach ($lines as $l) {
    $j = json_decode($l, true);
    $h2 = $j['hash'];
    unset($j['hash']);
    $chain = $chain && $j['prev'] === $prev && hash('sha256', canonical($j)) === $h2;
    $prev = $h2;
}
ok($lines && $chain, 'auditoria encadenada e integra');

// 2) idempotencia: misma peticion otra vez -> sin comandos nuevos, respuesta intacta
$before = count($h->log);
$respRaw = file_get_contents("$d/q/responses/{$ULIDS[0]}.json");
$drop($d, req('provision'));
$a->runOnce();
ok(count($h->log) === $before && $respRaw === file_get_contents("$d/q/responses/{$ULIDS[0]}.json") && !is_file("$d/q/requests/{$ULIDS[0]}.json"), 'replay del mismo request_id: no se re-ejecuta');
// provision otra vez con id nuevo sobre tenant activo = noop
$drop($d, req('provision', [], $ULIDS[1]));
$a->runOnce();
$r = $resp($d, $ULIDS[1]);
ok($r['status'] === 'succeeded' && $r['result']['noop'] === true && count($h->log) === $before, 'provision sobre tenant activo: noop sin comandos');
// request_id reutilizado con otro contenido (misma id, otro payload, sin respuesta previa de ese contenido)
$drop($d, req('suspend', [], $ULIDS[2]));
$a->runOnce();
ok($resp($d, $ULIDS[2])['status'] === 'succeeded' && $resp($d, $ULIDS[2])['tenant_state'] === 'suspended', 'suspend: active -> suspended');
ok(str_contains($h->files['/etc/fs/suspend/acme.conf'], 'return 503') && str_contains($h->files['/etc/nginx/sites-available/fs-acme.conf'], 'include /etc/fs/suspend/acme.conf;'), 'suspension: fragmento 503 enganchado al vhost');
$drop($d, req('suspend', [], $ULIDS[3]));
$a->runOnce();
ok($resp($d, $ULIDS[3])['result']['noop'] === true, 'suspend sobre suspendido: noop');
$drop($d, req('reactivate', [], $ULIDS[4]));
$a->runOnce();
ok($resp($d, $ULIDS[4])['tenant_state'] === 'active' && str_contains($h->files['/etc/fs/suspend/acme.conf'], 'activo') && substr_count($h->files['/etc/nginx/sites-available/fs-acme.conf'], 'include /etc/fs/suspend') === 1, 'reactivate: vuelve a activo y el include no se duplica');
// entitlements: serial creciente
$drop($d, req('entitlements', ['entitlements' => ['serial' => 2, 'modulos' => ['sepa' => true]]], $ULIDS[5]));
$a->runOnce();
$r = $resp($d, $ULIDS[5]);
ok($r['status'] === 'succeeded' && $r['result']['entitlements']['modulos']['sepa'] === true, 'entitlements aplicados');
$drop($d, req('entitlements', ['entitlements' => ['serial' => 2]], $ULIDS[6]));
$a->runOnce();
$r = $resp($d, $ULIDS[6]);
ok($r['status'] === 'failed' && $r['error']['code'] === 'SERIAL_NOT_NEWER', 'entitlements con serial no creciente: rechazado');
// status y dry-run de baja
$drop($d, req('status', [], $ULIDS[7]));
$drop($d, req('deprovision-dry-run', [], $ULIDS[8]));
$n0 = count(array_filter($h->log, fn($l) => str_starts_with($l, 'remove ')));
$a->runOnce();
ok($resp($d, $ULIDS[7])['result']['state'] === 'active', 'status');
$dr = $resp($d, $ULIDS[8]);
ok($dr['status'] === 'succeeded' && count($dr['result']['would_remove']) >= 5 && count(array_filter($h->log, fn($l) => str_starts_with($l, 'remove '))) === $n0, 'deprovision: solo simulacion, no borra nada');
ok(!array_filter($h->log, fn($l) => preg_match('/userdel|rm -rf|DROP |rmdir/', $l)), 'ningun comando destructivo en todo el log');

// 3) rechazos: firma, protegidos, slug de otro tenant, slug ocupado, estado
[$d, $h, $a] = $mk();
$drop($d, req('provision'), 'otra-clave-distinta-0123456789abcdef0123456789');
$a->runOnce();
$r = $resp($d, $ULIDS[0]);
ok($r['status'] === 'rejected' && $r['error']['code'] === 'BAD_SIGNATURE' && $h->log === [], 'firma incorrecta: rechazada sin tocar el host');
$drop($d, req('provision', [], $ULIDS[1]), null);
$a->runOnce();
ok($resp($d, $ULIDS[1])['error']['code'] === 'BAD_SIGNATURE', 'sin firma: rechazada');
file_put_contents("$d/q/requests/{$ULIDS[2]}.json", '{no json');
$a->runOnce();
ok($resp($d, $ULIDS[2])['error']['code'] === 'BAD_JSON', 'JSON roto: rechazado');
$p = req('suspend', ['tenant' => ['slug' => 'carlos', 'hostname' => 'carlos.facturalo.redtelecom.app']], $ULIDS[3]);
$drop($d, $p);
$a->runOnce();
ok($resp($d, $ULIDS[3])['error']['code'] === 'PROTECTED' && $h->log === [], 'tenant protegido (carlos): intocable');
$h->files['/srv/fs/acme/current'] = 'x';
$drop($d, req('provision', [], $ULIDS[4]));
$a->runOnce();
ok($resp($d, $ULIDS[4])['error']['code'] === 'SLUG_TAKEN' && $h->log === [], 'slug ocupado por instalacion no gestionada: rechazado');
unset($h->files['/srv/fs/acme/current']);
$h->files['/etc/fs/tenants/acme.master.json'] = json_encode(['tenant_id' => '01JD00000000000000000000T9', 'state' => 'active']);
$drop($d, req('suspend', [], $ULIDS[5]));
$a->runOnce();
ok($resp($d, $ULIDS[5])['error']['code'] === 'SLUG_BOUND', 'slug ligado a otro tenant: rechazado');
unset($h->files['/etc/fs/tenants/acme.master.json']);
$drop($d, req('suspend', [], $ULIDS[6]));
$a->runOnce();
ok($resp($d, $ULIDS[6])['error']['code'] === 'BAD_STATE', 'suspend de tenant inexistente: BAD_STATE');
$drop($d, req('provision', ['requested_at' => gmdate('Y-m-d\TH:i:s\Z', T0 - 90000)], $ULIDS[7]));
$a->runOnce();
ok($resp($d, $ULIDS[7])['error']['code'] === 'INVALID', 'peticion caducada firmada: INVALID');

// 4) fallo a mitad de alta: rollback reversible y reintento con id nuevo reanuda
[$d, $h, $a] = $mk();
$h->scripted['/usr/local/sbin/fs-bootstrap-tenant'] = [1, 'certbot: fallo de desafio'];
$drop($d, req('provision'));
$a->runOnce();
$r = $resp($d, $ULIDS[0]);
ok($r['status'] === 'failed' && $r['tenant_state'] === 'error' && $r['error']['step'] === 'bootstrap' && $r['error']['code'] === 'BOOTSTRAP_FAILED', 'fallo en bootstrap: failed/error', json_encode($r['error'] ?? null));
ok(str_contains($r['result']['rollback'], 'vhost deshabilitado') && in_array('remove /etc/nginx/sites-enabled/fs-acme.conf', $h->log, true), 'rollback: vhost deshabilitado');
ok(!array_filter($h->log, fn($l) => preg_match('/userdel|rm -rf|DROP /', $l)) && isset($h->files['/srv/fs/acme/current']), 'rollback no borra datos');
ok(json_decode($h->files['/etc/fs/tenants/acme.master.json'], true)['state'] === 'error', 'registro en error');
unset($h->scripted['/usr/local/sbin/fs-bootstrap-tenant']);
$h->log = [];
$drop($d, req('provision', [], $ULIDS[1]));
$a->runOnce();
$r = $resp($d, $ULIDS[1]);
ok($r['status'] === 'succeeded' && $r['tenant_state'] === 'active', 'reintento con request_id nuevo: succeeded');
ok(!array_filter($h->log, fn($l) => str_contains($l, 'fs-provision-tenant')) && in_array('exec ln -sfn /etc/nginx/sites-available/fs-acme.conf /etc/nginx/sites-enabled/fs-acme.conf', $h->log, true), 'el reintento no re-aprovisiona: reactiva el vhost y sigue en bootstrap');

// 5) DNS no listo y release ausente
[$d, $h, $a] = $mk();
$h->dns = [];
$drop($d, req('provision'));
$a->runOnce();
$r = $resp($d, $ULIDS[0]);
ok($r['status'] === 'failed' && $r['error']['code'] === 'DNS_NOT_READY' && $r['error']['retryable'] === true && !array_filter($h->log, fn($l) => str_starts_with($l, 'exec /usr/local/sbin')), 'DNS sin resolver: DNS_NOT_READY reintentable, nada creado');
$h->dns['acme.facturalo.redtelecom.app'] = ['151.80.149.132'];
unset($h->files['/home/ploi/fs-releases/v1.tar.gz.sha256']);
$drop($d, req('provision', [], $ULIDS[1]));
$a->runOnce();
ok($resp($d, $ULIDS[1])['error']['code'] === 'RELEASE_MISSING', 'release sin .sha256: RELEASE_MISSING');
ok(!array_filter($h->log, fn($l) => str_contains($l, 'fs-provision-tenant')), 'sin release no se aprovisiona');

echo "\n$total comprobaciones, $fail fallos\n";
exit($fail ? 1 : 0);
