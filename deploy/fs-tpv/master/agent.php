<?php
// Agente MASTER de Facturalo en el host (rt-tpv-prod-01). Consume la cola requests/<request_id>.json, valida firma y contrato,
// ejecuta el plan de pasos de forma idempotente y escribe responses/<request_id>.json firmada. Contrato: docs/FACTURALO-CONTROL-MASTER.md
// Uso (root; lo lanza el temporizador systemd):  php agent.php --conf=/etc/fs-master/agent.json
//        ensayo sin tocar nada:                    php agent.php --dry-run --queue=<dir temporal> --keys=<dir con kid.key>
declare(strict_types=1);

namespace FsMaster;

require_once __DIR__ . '/lib.php';

/** Frontera con el host: todo lo que toca el sistema pasa por aqui, asi el ensayo y los tests usan SimHost. */
interface Host
{
    /** @return array{0:int,1:string} codigo de salida y salida combinada. Sin shell: argv. */
    public function exec(array $argv, array $env = [], int $timeout = 900): array;
    public function exists(string $path): bool;
    public function read(string $path): ?string;
    public function write(string $path, string $data, int $mode = 0600): void;
    public function remove(string $path): void;
    public function sql(string $db, string $sql, array $params = [], bool $sensitive = false): array;
    public function dns(string $hostname): array;
}

final class RealHost implements Host
{
    public function exec(array $argv, array $env = [], int $timeout = 900): array
    {
        $p = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env ? array_merge(getenv(), $env) : null);
        if (!is_resource($p)) {
            return [127, 'no se pudo lanzar ' . $argv[0]];
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $end = time() + $timeout;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                break;
            }
            if (time() > $end) {
                proc_terminate($p, 15);
                return [124, substr($out, -2000) . "\n[timeout]"];
            }
            usleep(200000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        proc_close($p);
        return [(int)$st['exitcode'], substr($out, -4000)];
    }

    public function exists(string $path): bool { return file_exists($path) || is_link($path); }

    public function read(string $path): ?string { $d = @file_get_contents($path); return $d === false ? null : $d; }

    public function write(string $path, string $data, int $mode = 0600): void
    {
        $tmp = $path . '.tmp' . getmypid();
        file_put_contents($tmp, $data);
        chmod($tmp, $mode);
        rename($tmp, $path);
    }

    public function remove(string $path): void { @unlink($path); }

    public function sql(string $db, string $sql, array $params = [], bool $sensitive = false): array
    {
        $pdo = new \PDO("mysql:dbname=$db;charset=utf8mb4", 'root', null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->columnCount() ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function dns(string $hostname): array { return gethostbynamel($hostname) ?: []; }
}

/** Host simulado: memoria + registro de lo que se HARIA. $scripted: prefijo de comando => [codigo, salida] (para forzar fallos). */
final class SimHost implements Host
{
    public array $log = [];
    public array $files = [];
    public array $scripted = [];
    public array $dns = [];
    public array $sqlRows = [];
    public array $emailRow = [['em' => 'noreply@x.example', 'host' => 'smtp.x.example', 'pw' => 's']];

    public function exec(array $argv, array $env = [], int $timeout = 900): array
    {
        $line = implode(' ', array_map(fn($a) => preg_match('/\A[\w@%+=:,.\/-]+\z/', $a) ? $a : "'$a'", $argv)) . ($env ? ' [env ' . implode(',', array_keys($env)) . ']' : '');
        $this->log[] = 'exec ' . $line;
        foreach ($this->scripted as $prefix => $res) {
            if (str_starts_with($line, $prefix)) {
                return $res;
            }
        }
        if ($argv[0] === 'curl') {
            return [0, '200'];
        }
        // efectos que el resto del plan observa en un host real
        if (str_ends_with($argv[0], 'fs-provision-tenant')) {
            $this->files["/srv/fs/{$argv[1]}/current"] = 'release';
            $this->files["/etc/nginx/sites-available/fs-{$argv[1]}.conf"] = "server {\n    listen 80;\n    server_name {$argv[2]};\n}\n";
        }
        if (str_ends_with($argv[0], 'fs-bootstrap-tenant')) {
            $this->files["/etc/fs/tenants/{$argv[1]}.secrets"] = 'FS_ADMIN=admin';
        }
        return [0, 'ok (simulado)'];
    }

    public function exists(string $path): bool { return isset($this->files[$path]); }
    public function read(string $path): ?string { return $this->files[$path] ?? null; }
    public function write(string $path, string $data, int $mode = 0600): void { $this->log[] = "write $path"; $this->files[$path] = $data; }
    public function remove(string $path): void { $this->log[] = "remove $path"; unset($this->files[$path]); }

    public function sql(string $db, string $sql, array $params = [], bool $sensitive = false): array
    {
        $this->log[] = "sql $db: " . preg_replace('/\s+/', ' ', trim($sql)) . ($params ? ($sensitive ? ' [params ocultos]' : ' ' . json_encode($params, JSON_UNESCAPED_UNICODE)) : '');
        if (str_contains($sql, "FROM settings WHERE name = 'email'")) {
            return $this->emailRow;
        }
        return str_starts_with(trim($sql), 'SELECT') ? ($this->sqlRows[$db][trim($sql)] ?? []) : [];
    }

    public function dns(string $hostname): array { return $this->dns[$hostname] ?? []; }
}

final class StepFailed extends \RuntimeException
{
    public function __construct(public readonly string $codigo, string $msg, public readonly bool $reintentable = false) { parent::__construct($msg); }
}

final class Agent
{
    /** @param array<string,mixed> $c Configuracion (ver CONF_DEFAULTS) */
    public function __construct(private array $c, private Host $h, private \Closure $clock) {}

    public const CONF_DEFAULTS = [
        'queue' => '/var/lib/fs-master/sftp',           // requests/ y responses/ (chroot SFTP del operador MASTER)
        'work' => '/var/lib/fs-master',                 // processing/, state/, audit.log (solo root)
        'keys' => '/etc/fs-master/keys',                // <kid>.key (>=32 bytes, root 600)
        'server_ip' => '151.80.149.132',
        'releases' => '/home/ploi/fs-releases',         // <tag>.tar.gz + <tag>.tar.gz.sha256
        'default_release' => '/etc/fs-master/default-release', // fichero con la etiqueta por defecto
        'email_template' => '/etc/fs-master/email-template.json',   // grupo de ajustes `email` de FS (SMTP noreply); root 600, nunca en peticiones ni logs
        'tenants' => '/etc/fs/tenants', 'base' => '/srv/fs', 'suspend_dir' => '/etc/fs/suspend',
        'protected' => ['redtelecom', 'teleco-test', 'carlos'], // nunca se tocan (existian antes del agente)
        'sbin' => '/usr/local/sbin',
        'plugins_base' => ['Facturalo', 'TelecoBridge'],
    ];

    private function now(): int { return ($this->clock)(); }
    private function d(string $k): string { return $this->c[$k]; }

    /** Procesa todas las peticiones pendientes en orden (los ULID ordenan por tiempo). Devuelve cuantas atendio. */
    public function runOnce(): int
    {
        $n = 0;
        $files = glob($this->d('queue') . '/requests/*.json') ?: [];
        sort($files);
        foreach ($files as $f) {
            $id = basename($f, '.json');
            $this->handle($f, $id);
            $n++;
        }
        return $n;
    }

    private function handle(string $file, string $id): void
    {
        $respFile = $this->d('queue') . "/responses/$id.json";
        $raw = (string)file_get_contents($file);
        $payloadHash = hash('sha256', $raw);
        $seen = $this->readJson($this->d('work') . "/state/$id.json");
        if (is_file($respFile)) {                       // idempotencia: ya respondida, no se ejecuta otra vez
            if ($seen && $seen['payload_sha256'] !== $payloadHash) {
                $this->audit($id, 'replay-distinto', ['sha256' => $payloadHash]);
            }
            @unlink($file);
            return;
        }
        if ($seen && $seen['payload_sha256'] !== $payloadHash) {
            $this->finish($file, $id, ['request_id' => $id, 'action' => null, 'tenant' => null], 'rejected', 'unknown', [], [], ['step' => 'validate', 'code' => 'REQUEST_ID_REUSED', 'message' => 'request_id ya usado con otro contenido']);
            return;
        }
        $req = json_decode($raw, true);
        if (!is_array($req) || strlen($raw) > 65536) {
            $this->finish($file, $id, ['request_id' => $id, 'action' => null, 'tenant' => null], 'rejected', 'unknown', [], [], ['step' => 'validate', 'code' => 'BAD_JSON', 'message' => 'JSON ilegible o demasiado grande']);
            return;
        }
        $keys = $this->loadKeys();
        if ($m = verify($req, $keys, 'req')) {
            $this->finish($file, $id, $this->shell($req, $id), 'rejected', 'unknown', [], [], ['step' => 'auth', 'code' => 'BAD_SIGNATURE', 'message' => $m]);
            return;
        }
        if ($errs = validate_request($req, $this->now(), $id)) {
            $this->finish($file, $id, $this->shell($req, $id), 'rejected', 'unknown', [], [], ['step' => 'validate', 'code' => 'INVALID', 'message' => implode('; ', $errs)]);
            return;
        }
        $this->saveState($id, ['payload_sha256' => $payloadHash, 'steps' => $seen['steps'] ?? [], 'started_at' => $seen['started_at'] ?? $this->now()]);
        $this->execute($file, $req);
    }

    private function shell(array $r, string $id): array
    {
        return ['request_id' => $id, 'action' => is_string($r['action'] ?? null) ? $r['action'] : null, 'tenant' => ['id' => is_string($r['tenant']['id'] ?? null) ? $r['tenant']['id'] : null]];
    }

    private function execute(string $file, array $req): void
    {
        $id = $req['request_id'];
        $slug = $req['tenant']['slug'];
        $action = $req['action'];
        $bind = $this->readJson($this->d('tenants') . "/$slug.master.json");
        $occupied = $this->h->exists($this->d('base') . "/$slug") || $this->h->exists($this->d('base') . "/$slug/current") || $this->h->exists("/etc/nginx/sites-available/fs-$slug.conf");
        if (in_array($slug, $this->c['protected'], true) && $action !== 'status') {
            $this->finish($file, $id, $req, 'rejected', 'unmanaged', [], [], ['step' => 'validate', 'code' => 'PROTECTED', 'message' => 'tenant protegido: fuera del control del agente']);
            return;
        }
        if ($bind && $bind['tenant_id'] !== $req['tenant']['id']) {
            $this->finish($file, $id, $req, 'rejected', 'unknown', [], [], ['step' => 'validate', 'code' => 'SLUG_BOUND', 'message' => 'el slug pertenece a otro tenant']);
            return;
        }
        if (!$bind && $occupied && $action !== 'status') {
            $this->finish($file, $id, $req, 'rejected', 'unmanaged', [], [], ['step' => 'validate', 'code' => 'SLUG_TAKEN', 'message' => 'slug ocupado por una instalacion no gestionada']);
            return;
        }
        $state = $bind['state'] ?? 'absent';
        $t = transition($state, $action);
        if (!$t['ok']) {
            $this->finish($file, $id, $req, 'rejected', $state, [], [], ['step' => 'state', 'code' => 'BAD_STATE', 'message' => $t['reason']]);
            return;
        }
        if ($t['noop'] && $action !== 'entitlements') {
            $this->finish($file, $id, $req, 'succeeded', $state, [['name' => $action, 'status' => 'skipped', 'ms' => 0, 'detail' => 'ya estaba asi']], ['noop' => true]);
            return;
        }

        $st = $this->readJson($this->d('work') . "/state/$id.json");
        $done = $st['steps'];
        $steps = [];
        $result = ['noop' => false];
        $ctx = ['req' => $req, 'slug' => $slug, 'host' => $req['tenant']['hostname'], 'db' => 'fs_' . str_replace('-', '_', $slug), 'id' => $id];
        try {
            foreach (plan($action) as $name) {
                $t0 = microtime(true);
                if (isset($done[$name])) {                  // reanudacion tras caida: el paso ya se hizo en este request
                    $res = $done[$name];
                    $steps[] = ['name' => $name, 'status' => 'skipped', 'ms' => 0, 'detail' => 'hecho antes (reanudacion)'];
                } else {
                    $res = $this->step($name, $ctx, $bind);
                    $done[$name] = $res;
                    $st['steps'] = $done;
                    $this->saveState($id, $st);             // admin_initial_password vive solo aqui hasta responder
                    $steps[] = ['name' => $name, 'status' => 'ok', 'ms' => (int)((microtime(true) - $t0) * 1000), 'detail' => $res['detail'] ?? ''];
                }
                $bind = $this->readJson($this->d('tenants') . "/$slug.master.json") ?: $bind;
                $result += $res['result'] ?? [];
            }
        } catch (StepFailed $e) {
            $steps[] = ['name' => $name, 'status' => 'failed', 'ms' => (int)((microtime(true) - $t0) * 1000), 'detail' => $e->getMessage()];
            $rb = $action === 'provision' ? $this->rollback($ctx, $done) : 'nada que deshacer';
            $this->finish($file, $id, $req, 'failed', $action === 'provision' ? 'error' : $state, $steps, ['rollback' => $rb],
                ['step' => $name, 'code' => $e->codigo, 'message' => $e->getMessage(), 'retryable' => $e->reintentable]);
            return;
        }
        $next = $t['next'];
        if (in_array($action, ['provision', 'suspend', 'reactivate'], true)) {
            $this->setBind($slug, ['state' => $next]);
        }
        $this->finish($file, $id, $req, 'succeeded', $next, $steps, $result);
    }

    // ---------- pasos (cada uno idempotente; devuelve ['detail'=>..., 'result'=>[...]]) ----------
    private function step(string $name, array $x, ?array $bind): array
    {
        $slug = $x['slug'];
        $sbin = $this->d('sbin');
        $h = $this->h;
        $tar = fn() => $this->artifact($x['req']);
        switch ($name) {
            case 'preflight':
                $ips = $h->dns($x['host']);
                if (!in_array($this->d('server_ip'), $ips, true)) {
                    throw new StepFailed('DNS_NOT_READY', "{$x['host']} no resuelve a {$this->d('server_ip')} (resuelve a: " . ($ips ? implode(',', $ips) : 'nada') . ')', true);
                }
                if ($x['req']['action'] === 'provision') {
                    $tar();
                    $this->emailTemplate();
                }
                return ['detail' => 'dns y artefacto ok'];
            case 'bind':
                if (!$bind) {
                    $this->setBind($slug, ['tenant_id' => $x['req']['tenant']['id'], 'slug' => $slug, 'hostname' => $x['host'], 'state' => 'provisioning',
                        'created_request' => $x['id'], 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()), 'entitlements_serial' => 0]);
                } else {
                    $this->setBind($slug, ['state' => 'provisioning']);
                }
                return ['detail' => 'slug ligado al tenant'];
            case 'provision':
                if ($h->exists($this->d('base') . "/$slug/current") && $h->exists("/etc/nginx/sites-available/fs-$slug.conf")) {
                    $h->exec(['ln', '-sfn', "/etc/nginx/sites-available/fs-$slug.conf", "/etc/nginx/sites-enabled/fs-$slug.conf"]);  // reanuda un rollback anterior
                    return ['detail' => 'ya aprovisionado'];
                }
                $this->must($h->exec(["$sbin/fs-provision-tenant", $slug, $x['host'], $tar()]), 'PROVISION_FAILED');
                return ['detail' => 'usuario, pool, BD y vhost creados'];
            case 'bootstrap':
                $pl = array_merge($this->c['plugins_base'], array_values(MODULE_PLUGINS));
                $this->must($h->exec(["$sbin/fs-bootstrap-tenant", $slug], ['FS_EXTRA_PLUGINS' => implode(' ', array_slice($pl, 2))]), 'BOOTSTRAP_FAILED');
                return ['detail' => 'FS instalado, TLS y plugins activados'];
            case 'deploy':
                $this->must($h->exec(["$sbin/fs-deploy-release", $slug, $tar()]), 'DEPLOY_FAILED');
                return ['detail' => 'release activa'];
            case 'company':
                $c = $x['req']['tenant']['company'];
                $h->sql($x['db'], 'UPDATE empresas SET nombre = ?, nombrecorto = ?, cifnif = ?, direccion = ?, ciudad = ?, provincia = ?, codpostal = ?, email = ? WHERE idempresa = 1',
                    [$x['req']['tenant']['legal_name'], preg_replace('/^(.{0,32}).*$/us', '$1', $x['req']['tenant']['trade_name']), $c['cifnif'], $c['direccion'], $c['ciudad'], $c['provincia'], $c['codpostal'], $c['email'] ?? '']);
                $this->clearCache($slug);
                return ['detail' => 'datos de la empresa cargados'];
            case 'email':
                return $this->applyEmail($x);
            case 'entitlements':
                return $this->applyEntitlements($x, $bind);
            case 'verify':
                [$code, $out] = $h->exec(['curl', '-sk', '-o', '/dev/null', '-m', '30', '-w', '%{http_code}', '--resolve', "{$x['host']}:443:127.0.0.1", "https://{$x['host']}/login"]);
                if (trim($out) !== '200') {
                    throw new StepFailed('HEALTH_FAILED', "GET /login devolvio HTTP " . trim($out), true);
                }
                return ['detail' => 'HTTPS /login 200'];
            case 'admin':
                $nick = owner_nick($x['req']['tenant']['admin_email']);
                if ($h->sql($x['db'], 'SELECT nick FROM users WHERE nick = ?', [$nick])) {
                    return ['detail' => 'usuario ya existia: no se toca', 'result' => ['admin' => ['nick' => $nick, 'initial_password' => null]]];
                }
                $pw = $this->randomPassword();
                $h->sql($x['db'], "INSERT INTO users (nick, password, email, admin, enabled, idempresa, codalmacen, langcode, homepage, level) VALUES (?, ?, ?, 1, 1, 1, '1', 'es_ES', 'Dashboard', 99)",
                    [$nick, password_hash($pw, PASSWORD_DEFAULT), $x['req']['tenant']['admin_email']], true);
                return ['detail' => 'usuario administrador creado', 'result' => ['admin' => ['nick' => $nick, 'initial_password' => $pw]]];
            case 'suspend':
            case 'reactivate':
                return $this->setSuspended($x, $name === 'suspend');
            case 'status':
                return ['detail' => 'estado leido', 'result' => $this->statusOf($x, $bind)];
            case 'deprovision-dry-run':
                return ['detail' => 'simulacion: nada se borra', 'result' => ['would_remove' => $this->removalList($slug, $x['db'])]];
        }
        throw new StepFailed('UNKNOWN_STEP', $name);
    }

    private function must(array $r, string $code): void
    {
        if ($r[0] !== 0) {
            throw new StepFailed($code, 'salida ' . $r[0] . ': ' . trim(substr($r[1], -600)));
        }
    }

    private function artifact(array $req): string
    {
        $tag = $req['release']['tag'] ?? trim((string)$this->h->read($this->d('default_release')));
        if ($tag === '' || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $tag)) {
            throw new StepFailed('RELEASE_MISSING', 'sin release: indique release.tag o fije ' . $this->d('default_release'));
        }
        $p = $this->d('releases') . "/$tag.tar.gz";
        if (!$this->h->exists($p) || !$this->h->exists("$p.sha256")) {
            throw new StepFailed('RELEASE_MISSING', "falta $p o su .sha256 (subirla con fs-deploy.yml)");
        }
        return $p;
    }

    private function applyEntitlements(array $x, ?array $bind): array
    {
        $en = $x['req']['entitlements'] ?? null;
        $serial = $en['serial'] ?? 0;
        if ($x['req']['action'] === 'entitlements' && $serial <= (int)($bind['entitlements_serial'] ?? 0)) {
            throw new StepFailed('SERIAL_NOT_NEWER', "serial $serial <= " . ($bind['entitlements_serial'] ?? 0));
        }
        foreach (entitlement_settings($en) as $k => $v) {
            // JSON_SET sobre la fila 'facturalo' de settings; si no existe se crea. Los plugins leen Tools::settings('facturalo','modulos.<clave>')
            $this->h->sql($x['db'], "INSERT INTO settings (name, properties) VALUES ('facturalo', JSON_OBJECT(?, ?)) ON DUPLICATE KEY UPDATE properties = JSON_SET(COALESCE(properties, '{}'), CONCAT('$.\"', ?, '\"'), ?)", [$k, $v, $k, $v]);
        }
        $this->clearCache($x['slug']);
        $this->setBind($x['slug'], ['entitlements_serial' => max($serial, (int)($bind['entitlements_serial'] ?? 0)), 'entitlements' => $en['modulos'] ?? array_fill_keys(MODULES, false)]);
        return ['detail' => "modulos aplicados (serial $serial)", 'result' => ['entitlements' => ['serial' => $serial, 'modulos' => $en['modulos'] ?? array_fill_keys(MODULES, false)]]];
    }

    /** Lee y valida la plantilla de correo (fichero de root). Los errores no incluyen valores. */
    private function emailTemplate(): array
    {
        $raw = $this->h->read($this->d('email_template'));
        if ($raw === null) {
            throw new StepFailed('EMAIL_TEMPLATE_MISSING', 'falta ' . $this->d('email_template') . ' (grupo de ajustes email de FS, root 600)');
        }
        if ($e = email_template_errors($raw)) {
            throw new StepFailed('EMAIL_TEMPLATE_INVALID', implode('; ', $e));
        }
        return json_decode($raw, true);
    }

    /** Misma condicion que NewMail::canSendMail() de FS: email, host y password no vacios en settings(email). */
    private function emailConfigured(string $db): bool
    {
        $r = $this->h->sql($db, "SELECT JSON_UNQUOTE(JSON_EXTRACT(properties, '$.email')) AS em, JSON_UNQUOTE(JSON_EXTRACT(properties, '$.host')) AS host, JSON_UNQUOTE(JSON_EXTRACT(properties, '$.password')) AS pw FROM settings WHERE name = 'email'");
        return isset($r[0]) && (string)$r[0]['em'] !== '' && (string)$r[0]['host'] !== '' && (string)$r[0]['pw'] !== '';
    }

    private function applyEmail(array $x): array
    {
        $t = $this->emailTemplate();
        $this->h->sql($x['db'], "INSERT INTO settings (name, properties) VALUES ('email', ?) ON DUPLICATE KEY UPDATE properties = JSON_MERGE_PATCH(COALESCE(properties, '{}'), ?)", [json_encode($t), json_encode($t)], true);
        $this->clearCache($x['slug']);                 // tools-settings.cache oculta el cambio
        if (!$this->emailConfigured($x['db'])) {
            throw new StepFailed('EMAIL_NOT_CONFIGURED', 'tras aplicar la plantilla settings(email) sigue sin email, host o password');
        }
        $host = (string)$t['host'];
        $port = (string)((int)($t['port'] ?? 587) ?: 587);
        [$c] = $this->h->exec(['timeout', '8', 'bash', '-c', 'exec 3<>/dev/tcp/$1/$2', '_', $host, $port]);
        if ($c !== 0) {
            throw new StepFailed('EMAIL_SMTP_UNREACHABLE', "no se conecta al servidor SMTP $host:$port", true);
        }
        return ['detail' => "correo configurado y SMTP $host:$port alcanzable", 'result' => ['email_configured' => true]];
    }

    private function setSuspended(array $x, bool $on): array
    {
        $slug = $x['slug'];
        $vhost = "/etc/nginx/sites-available/fs-$slug.conf";
        $snip = $this->d('suspend_dir') . "/$slug.conf";
        $conf = $this->h->read($vhost);
        if ($conf === null) {
            throw new StepFailed('NO_VHOST', "no existe $vhost");
        }
        if (!str_contains($conf, "include $snip;")) {      // se engancha una vez tras cada server_name (bloques 80 y 443)
            $this->h->write($snip, "# fs-master: activo\n", 0644);
            $this->h->write($vhost . '.bak-master', $conf, 0600);
            $this->h->write($vhost, preg_replace('/^(\s*server_name [^;]+;)$/m', "$1\n    include $snip;", $conf), 0644);
        }
        $prev = $this->h->read($snip);
        $this->h->write($snip, $on ? "return 503;\n" : "# fs-master: activo\n", 0644);
        [$tc, $to] = $this->h->exec(['nginx', '-t']);
        if ($tc !== 0) {                                   // no recargar con configuracion rota: se revierte el fragmento
            $this->h->write($snip, (string)$prev, 0644);
            throw new StepFailed('NGINX_TEST_FAILED', trim($to));
        }
        $this->must($this->h->exec(['systemctl', 'reload', 'nginx']), 'NGINX_RELOAD_FAILED');
        return ['detail' => $on ? 'servicio suspendido (503), datos intactos' : 'servicio reactivado'];
    }

    private function statusOf(array $x, ?array $bind): array
    {
        $slug = $x['slug'];
        $r = ['state' => $bind['state'] ?? 'absent', 'entitlements' => $bind['entitlements'] ?? null, 'entitlements_serial' => $bind['entitlements_serial'] ?? 0,
            'vhost' => $this->h->exists("/etc/nginx/sites-available/fs-$slug.conf"), 'installed' => $this->h->exists($this->d('base') . "/$slug/current"),
            'dns' => $this->h->dns($x['host']), 'email_configured' => null];
        if ($r['installed']) {
            $r['email_configured'] = $this->emailConfigured($x['db']);
            [, $out] = $this->h->exec(['curl', '-sk', '-o', '/dev/null', '-m', '15', '-w', '%{http_code}', '--resolve', "{$x['host']}:443:127.0.0.1", "https://{$x['host']}/login"]);
            $r['http_login'] = (int)trim($out);
            [, $rel] = $this->h->exec(['readlink', '-f', $this->d('base') . "/$slug/current"]);
            $r['release'] = basename(trim($rel));
        }
        return $r;
    }

    private function removalList(string $slug, string $db): array
    {
        return ["usuario de sistema fs-$slug", "pool PHP-FPM fs-$slug", "BD y usuario MySQL $db", "vhost fs-$slug.conf y certificado fs-$slug",
            $this->d('base') . "/$slug (releases, shared/MyFiles, backups)", $this->d('tenants') . "/$slug.{conf,secrets,master.json}",
            'NO IMPLEMENTADO: la baja real requiere decision del OWNER y copia previa'];
    }

    /** Deshacer SOLO lo reversible: el tenant a medias deja de servirse y queda en 'error'. Nunca se borran datos ni BD. */
    private function rollback(array $x, array $done): string
    {
        $slug = $x['slug'];
        $acts = [];
        if (isset($done['provision'])) {
            $this->h->remove("/etc/nginx/sites-enabled/fs-$slug.conf");
            $this->h->exec(['systemctl', 'reload', 'nginx']);
            $acts[] = 'vhost deshabilitado';
        }
        if (isset($done['bind'])) {
            $this->setBind($slug, ['state' => 'error']);
            $acts[] = 'estado=error (datos conservados; reintentar con un request_id nuevo reanuda)';
        }
        return implode('; ', $acts);
    }

    private function clearCache(string $slug): void
    {
        $this->h->exec(['find', $this->d('base') . "/$slug/shared/MyFiles/Tmp/FileCache", '-mindepth', '1', '-delete']);
    }

    private function randomPassword(): string
    {
        $a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 20; $i++) {
            $s .= $a[random_int(0, strlen($a) - 1)];
        }
        return $s;
    }

    // ---------- estado, firma, auditoria ----------
    private function setBind(string $slug, array $patch): void
    {
        $p = $this->d('tenants') . "/$slug.master.json";
        $cur = $this->readJson($p) ?: [];
        $this->h->write($p, json_encode(array_merge($cur, $patch), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600);
    }

    private function readJson(string $p): ?array
    {
        $d = $this->h->read($p) ?? (is_file($p) ? file_get_contents($p) : null);
        $j = $d === null ? null : json_decode($d, true);
        return is_array($j) ? $j : null;
    }

    private function saveState(string $id, array $st): void
    {
        $dir = $this->d('work') . '/state';
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/$id.json", json_encode($st), LOCK_EX);
        @chmod("$dir/$id.json", 0600);
    }

    private function loadKeys(): array
    {
        $k = [];
        foreach (glob($this->d('keys') . '/*.key') ?: [] as $f) {
            $k[basename($f, '.key')] = rtrim((string)file_get_contents($f), "\r\n");
        }
        return $k;
    }

    private function finish(string $file, string $id, array $req, string $status, string $tenantState, array $steps, array $result = [], ?array $error = null): void
    {
        $req += ['tenant' => null];
        $resp = response($req + ['request_id' => $id], $status, $tenantState, $steps, $result, $error, $this->now());
        $keys = $this->loadKeys();
        if ($keys) {                                       // se firma con la primera clave (kid mas bajo): rotacion = anadir kid nuevo y quitar el viejo
            ksort($keys);
            $kid = array_key_first($keys);
            $resp['sig'] = ['alg' => 'hmac-sha256', 'kid' => $kid, 'value' => sign($resp, $keys[$kid], 'resp')];
        }
        $dir = $this->d('queue') . '/responses';
        @mkdir($dir, 0750, true);
        $tmp = "$dir/.$id.json.tmp";
        file_put_contents($tmp, json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        chmod($tmp, 0640);
        rename($tmp, "$dir/$id.json");
        $this->audit($id, $status, ['action' => $req['action'] ?? null, 'tenant' => $req['tenant']['slug'] ?? ($req['tenant']['id'] ?? null),
            'by' => $req['requested_by'] ?? null, 'error' => $error['code'] ?? null, 'step' => $error['step'] ?? null]);
        if ($status === 'succeeded' && !empty($result['admin']['initial_password'])) {
            $this->wipeSecret($id);                        // la contrasena ya solo existe en la respuesta
        }
        @unlink($file);
    }

    private function wipeSecret(string $id): void
    {
        $st = $this->readJson($this->d('work') . "/state/$id.json");
        if ($st && isset($st['steps']['admin']['result']['admin']['initial_password'])) {
            $st['steps']['admin']['result']['admin']['initial_password'] = '[entregada]';
            $this->saveState($id, $st);
        }
    }

    /** Auditoria solo-insercion con cadena de hashes (cada linea incluye el hash de la anterior): una edicion rompe la cadena. */
    private function audit(string $id, string $event, array $data): void
    {
        $f = $this->d('work') . '/audit.log';
        @mkdir(dirname($f), 0700, true);
        $prev = '';
        if (is_file($f)) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $prev = $lines ? (json_decode(end($lines), true)['hash'] ?? '') : '';
        }
        $row = ['ts' => gmdate('Y-m-d\TH:i:s\Z', $this->now()), 'request_id' => $id, 'event' => $event] + $data + ['prev' => $prev];
        $row['hash'] = hash('sha256', canonical($row));
        file_put_contents($f, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ---------------- CLI ----------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $o = getopt('', ['conf::', 'dry-run', 'queue::', 'keys::']);
    $conf = Agent::CONF_DEFAULTS;
    if (isset($o['conf'])) {
        $conf = array_merge($conf, json_decode((string)file_get_contents($o['conf']), true) ?: []);
    }
    if (isset($o['dry-run'])) {
        if (!isset($o['queue'], $o['keys'])) {
            fwrite(STDERR, "--dry-run requiere --queue=<dir temporal> y --keys=<dir>\n");
            exit(2);
        }
        $host = new SimHost();
        $host->dns = [];
        $conf = array_merge($conf, ['queue' => $o['queue'], 'work' => $o['queue'] . '/.work', 'keys' => $o['keys']]);
        $agent = new Agent($conf, $host, fn() => time());
        $n = $agent->runOnce();
        echo "peticiones atendidas: $n\n" . implode("\n", $host->log) . "\n";
        exit(0);
    }
    $lock = fopen('/run/fs-master.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);                                           // otra instancia en curso
    }
    (new Agent($conf, new RealHost(), fn() => time()))->runOnce();
}
