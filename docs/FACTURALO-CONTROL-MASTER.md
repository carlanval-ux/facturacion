# Facturalo — control MASTER de tenants (contrato servidor)

- **Estado (2026-11-25):** diseñado e implementado y probado en local. **Comodín DNS `*.facturalo.redtelecom.app` creado en OVH (id 5451557702, TTL 300)** con autorización del OWNER; comprobado antes que nginx tiene `default_server` `return 444` (`000-catchall`) y que no hay CAA. **Agente NO instalado**: requiere root en el host (el usuario `ploi` solo tiene sudo sin contraseña para los 3 scripts `fs-*`).
- **Fecha:** 2026-11-25 · **Modelo:** ADR-0002/0013/0014/0019 del TPV (retail-pos), adaptado a FacturaScripts.
- **Código:** `deploy/fs-tpv/master/` (`lib.php` puro, `agent.php`, `systemd/`, `instalar-todo.sh`, `tests/run.php`).
- **Consumidor:** panel MASTER de Teleco/Clarto (perfil `PRODUCTO=facturalo`, operadores con MFA y roles). El panel **solo escribe peticiones y lee respuestas**; nunca toca BD ni shell del host.

## 1. Arquitectura

```
Panel MASTER (Teleco/Clarto)            rt-tpv-prod-01
  operador + MFA + RBAC + auditoría       sshd (usuario fs-master, solo SFTP enjaulado, sin shell)
  firma la petición (HMAC) ──SFTP──►        /var/lib/fs-master/sftp/requests/<request_id>.json
                                          fs-master-agent.timer (root, cada 30 s) ─► agent.php
                                            valida firma+contrato ► ejecuta pasos ► responses/<request_id>.json (firmada)
  lee y verifica la respuesta ◄──SFTP──     ► scripts existentes: fs-provision-tenant, fs-bootstrap-tenant, fs-deploy-release
```

- Un tenant = una instalación FS aislada (usuario `fs-<slug>`, pool PHP-FPM, BD `fs_<slug>`, vhost, `/srv/fs/<slug>`). El agente **reutiliza** los tres scripts ya probados y solo añade orquestación, registro y control.
- Un único agente, orden por `request_id` (ULID = orden temporal), bloqueo `flock` global: nunca dos altas a la vez.
- El agente es root (inevitable: `useradd`, pools, nginx, MySQL root). Se mitiga con: usuario `fs-master` sin shell y solo SFTP enjaulado; contrato cerrado (lista blanca de campos y acciones, sin comandos ni rutas libres: solo `slug`/`hostname` validados llegan a `argv`, nunca por shell); `argv` sin shell; SQL parametrizado; unidad systemd endurecida; **tenants protegidos** (`redtelecom`, `teleco-test`, `carlos`) y slugs existentes no gestionados quedan fuera de toda acción mutadora.

## 2. Cola y entrega

- `requests/<request_id>.json`: el MASTER sube como `.<request_id>.json.tmp` y hace `rename` (el agente ignora ficheros que empiezan por punto). Máx. 64 KiB.
- `responses/<request_id>.json` (root:fs-master 640). El MASTER la lee y la **borra** o archiva; el agente nunca re-ejecuta un `request_id` que ya tiene respuesta.
- `request_id`: ULID (26 car. Crockford). **El nombre del fichero debe ser igual al `request_id`.**
- Reintentar = **petición nueva con `request_id` nuevo** (igual que ADR-0013). El MASTER decide cuándo y cuántas veces (recomendado: espera exponencial 1,2,4…60 min, máx. 3, solo si `error.retryable`).
- Sin respuesta tras 30 min → el MASTER la marca `stale`; una `provision` en `stale` **sigue bloqueando** otra `provision` del mismo tenant (el agente puede estar ejecutándola).

## 3. Petición (versión 1)

```json
{
  "version": 1,
  "request_id": "01JD0000000000000000000001",
  "action": "provision",
  "tenant": {
    "id": "01JD00000000000000000000T1",
    "slug": "acme",
    "hostname": "acme.facturalo.redtelecom.app",
    "trade_name": "Acme Gestión",
    "legal_name": "ACME GESTION SOCIEDAD LIMITADA",
    "admin_email": "ana.lopez@acme.example",
    "company": { "cifnif": "B12345674", "direccion": "Calle Mayor 1", "ciudad": "Madrid",
                 "provincia": "Madrid", "codpostal": "28001", "email": "info@acme.example" }
  },
  "entitlements": { "serial": 1, "modulos": { "impagos": true, "sepa": false, "cobros": false, "trimestral": false,
                                               "aceptacion": false, "anticipos": false, "recurrentes": false } },
  "release": { "tag": "v2026.72.3" },
  "requested_at": "2026-11-25T10:00:00Z",
  "requested_by": "operador:<id estable en el MASTER>",
  "sig": { "alg": "hmac-sha256", "kid": "m1", "value": "<hex>" }
}
```

Reglas (el agente rechaza todo lo demás, **campos desconocidos incluidos**):

| Campo | Regla |
|---|---|
| `version` | `1` |
| `action` | `provision`, `suspend`, `reactivate`, `status`, `entitlements`, `ejercicio`, `preflight`, `deprovision-dry-run` |
| `tenant.id` | ULID estable del MASTER; el slug queda ligado a él **para siempre** (`/etc/fs/tenants/<slug>.master.json`) |
| `tenant.slug` | `^[a-z0-9][a-z0-9-]{0,26}[a-z0-9]$` (2-28), sin `--` (IDN), no reservado: `master www api admin mail smtp imap ftp ns1 ns2 app panel portal status staging test demo motor facturalo teleco clarto redtelecom tpv root localhost default cdn static assets dev prod backup dns vpn git ci support soporte billing ploi` ni prefijos `teleco-`/`fs-`. Inmutable. Mismo patrón que los scripts del host (así el slug nunca es una opción para `useradd`/`mysql`/nginx) |
| `tenant.hostname` | exactamente `<slug>.facturalo.redtelecom.app` (sin dominios propios en v1) |
| `legal_name` | solo `provision`, **obligatorio**: razón social CENSAL exacta (la que la AEAT valida contra el NIF; persona física: `APELLIDOS NOMBRE` como en el certificado). Solo mayúsculas (con acentos y Ñ), dígitos y `. , & ' / ( ) -`, sin espacios dobles ni minúsculas, ≤100. Se guarda en `Empresa.nombre`; el nombre comercial (`trade_name`) va solo en `nombrecorto` |
| `trade_name`, `company.*`, `admin_email` | solo `provision`; texto sin caracteres de control, longitudes máximas; `company.{cifnif,direccion,ciudad,provincia,codpostal}` obligatorios; `admin_email` formato estricto |
| `entitlements` | `provision` (opcional: si falta, **los 7 módulos a 0**) y `entitlements` (obligatorio): `serial` entero ≥ 1 y `modulos` con **exactamente** las 7 claves booleanas |
| `release.tag` | solo `provision`; `[A-Za-z0-9._-]`, ≤64. Debe existir `/home/ploi/fs-releases/<tag>.tar.gz` + `.sha256` (subidos por `fs-deploy.yml`). Sin `tag` se usa `/etc/fs-master/default-release` |
| `requested_at` | `YYYY-MM-DDThh:mm:ssZ`; no futuro (>5 min) ni anterior a 24 h |

Los 7 módulos (`facturalo.modulos.<clave>` en `settings`, fila `facturalo`): `impagos` (RecordatorioImpagos), `sepa` (RemesasSepa), `cobros` (CobrosRapidos), `trimestral` (PaqueteTrimestral), `aceptacion` (PresupuestosAceptacion), `anticipos` (Anticipos), `recurrentes` (FacturasRecurrentes). Los plugins se instalan y activan **siempre**; lo que se enciende o apaga es el ajuste. El agente escribe **siempre las 7 claves explícitas `'1'/'0'`**, porque sin valor los plugins asumen *activo* (modo pruebas). La bonificación (módulo a 0 € o gratis) es decisión comercial del MASTER: para el host solo hay `true/false`.

## 4. Firma y autenticación

- Canónico: JSON con claves **ordenadas recursivamente** (listas intactas), sin espacios, `/` y unicode **sin escapar** (`JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`), sin el campo `sig`.
- `sig.value = HMAC-SHA256(clave, "req\n" + canónico)` en hex. Las respuestas se firman igual con dominio `"resp\n"` (una respuesta no vale como petición). `kid` selecciona `/etc/fs-master/keys/<kid>.key` (≥32 bytes, root 600) → rotación: añadir `m2`, cambiar el MASTER, quitar `m1`. La respuesta se firma con el `kid` más bajo presente.
- Vector de prueba (el cliente TypeScript del MASTER debe reproducirlo): clave `k-de-prueba-0123456789abcdef0123456789abcdef`, documento `{"b":1,"a":"x/y"}` → canónico `{"a":"x/y","b":1}` → HMAC `90e41c2bd9516ef2f9e494c220151e3990e8e3eb8ecd2636300e11039161f9f1`.
- **Decisión:** HMAC simétrico y no Ed25519 como en ADR-0013. Motivo: el PHP de desarrollo no trae `sodium` para probarlo y el MASTER y el host los administra el mismo OWNER. Coste: si se roba la clave del host se pueden forjar peticiones. Migración prevista: `sig.alg = "ed25519"` (el agente solo guardaría la clave pública). Lo evalúa el OWNER antes de producción.
- Transporte: SFTP enjaulado por clave pública del servicio MASTER (`authorized_keys` con `from=`), y firma extra de la petición: comprometer el canal no basta para forjar. La anti-repetición es `request_id` único (un contenido distinto con un `request_id` ya visto = `REQUEST_ID_REUSED`).

## 5. Respuesta

```json
{ "version": 1, "request_id": "…", "action": "provision", "tenant_id": "…",
  "status": "succeeded",
  "tenant_state": "active",
  "steps": [ {"name":"preflight","status":"ok","ms":1200,"detail":"…"}, … ],
  "result": { "noop": false, "admin": { "nick": "ana.lopez", "initial_password": "<20 car., una vez>" },
              "entitlements": { "serial": 1, "modulos": { … } } },
  "error": null,
  "completed_at": "2026-11-25T10:07:41Z",
  "sig": { "alg": "hmac-sha256", "kid": "m1", "value": "…" } }
```

- `status`: `succeeded` · `failed` (se ejecutó y falló; ver `error` y `result.rollback`) · `rejected` (no se ejecutó nada: firma, contrato, estado). `error = {step, code, message, retryable}` en `failed`; en `rejected` el motivo va en `result` (`{step, code, message}`).
- `steps[].status`: `ok` · `skipped` (ya hecho / reanudación / noop) · `failed`.
- Códigos: `EJERCICIO_CODE_TAKEN`, `BAD_JSON`, `BAD_SIGNATURE`, `INVALID`, `REQUEST_ID_REUSED`, `PROTECTED`, `SLUG_BOUND` (slug de otro `tenant.id`), `SLUG_TAKEN` (instalación no gestionada), `BAD_STATE`, y de paso: `DNS_NOT_READY`*, `RELEASE_MISSING`, `PROVISION_FAILED`, `BOOTSTRAP_FAILED`, `DEPLOY_FAILED`, `HEALTH_FAILED`*, `EMAIL_TEMPLATE_MISSING`, `EMAIL_TEMPLATE_INVALID`, `EMAIL_NOT_CONFIGURED`, `EMAIL_SMTP_UNREACHABLE`*, `SERIAL_NOT_NEWER`, `NO_VHOST`, `NGINX_TEST_FAILED`, `NGINX_RELOAD_FAILED` (* = `retryable`).
- **Contraseña inicial del administrador:** solo en la respuesta de un `provision` `succeeded` (el panel debe mostrarla **una vez** al operador y no guardarla en claro; FS no tiene “cambiar al primer inicio”, así que se pide al cliente cambiarla o usar «recuperar contraseña»). En el host solo queda su hash; el estado interno la sustituye por `[entregada]`. Se crea al final del alta (paso `admin`), así un alta fallida nunca deja usuario. **Pendiente de decisión OWNER:** alternativa con enlace de invitación (como el TPV) requeriría un plugin FS que no existe.

## 6. Estados y acciones

Estado del tenant (en el registro del host `/etc/fs/tenants/<slug>.master.json`): `absent → provisioning → active ⇄ suspended`, `provisioning → error → provisioning`. Matriz probada en `tests/run.php`:

| Acción | Desde | Resultado | Notas |
|---|---|---|---|
| `provision` | absent, provisioning, error | `active` | idempotente; sobre `active`/`suspended` = `noop` |
| `suspend` | active | `suspended` | ya suspendido = `noop`. Fragmento nginx `return 503;` incluido en el vhost (datos, BD y pool intactos); `nginx -t` antes de recargar, si falla se revierte |
| `reactivate` | suspended | `active` | ya activo = `noop` |
| `entitlements` | active, suspended | igual | `serial` estrictamente creciente por tenant (anti-rollback → `SERIAL_NOT_NEWER`); escribe los 7 ajustes y vacía `FileCache` |
| `status` | cualquiera | igual | solo lectura: estado, módulos y serial, vhost, instalado, release, `GET /login`, DNS, `email_configured` (booleano; nunca valores) |
| `ejercicio` | active, suspended | igual | reconciliación de ejercicios (ver §6.1): crea los que falten del año en curso (hora Europe/Madrid) y el siguiente; idempotente; el MASTER puede enviarla a todos los tenants activos cada 1/1 |
| `preflight` | cualquiera | igual | DNS + release disponible; no crea nada |
| `deprovision-dry-run` | cualquiera | igual | lista lo que una baja eliminaría; **la baja real no existe** (hace falta decisión del OWNER y copia previa) |

### Pasos de `provision` (cada uno idempotente, registrado por `request_id`; si el agente cae, el reintento del mismo fichero reanuda)

1. `preflight` — el hostname resuelve a `151.80.149.132`; la release existe con `.sha256`.
2. `bind` — liga slug ↔ `tenant.id` (a partir de aquí el slug es de ese tenant).
3. `provision` — `fs-provision-tenant` (usuario, pool, BD y usuario MySQL mínimo, vhost, release inicial). Si ya existe, solo repone el enlace del vhost.
4. `bootstrap` — `fs-bootstrap-tenant`: instala FS, **certbot por host (HTTP-01)**, activa `TelecoBridge`, `Facturalo` y los 7 plugins de módulo (`FS_EXTRA_PLUGINS`), crea el token de API mínimo.
5. `deploy` — `fs-deploy-release` (mueve `config.php`/`MyFiles` a `shared`, activa la release, `Plugins::deploy`, salud; revierte solo si falla).
6. `company` — sustituye la empresa ficticia del asistente por los datos reales (`nombre` = `legal_name`, `nombrecorto` = `trade_name`) (UPDATE parametrizado).
6b. `ejercicio` — deja abiertos el ejercicio en curso y el siguiente (§6.1).
7. `email` — copia el grupo de ajustes `email` de FS (SMTP noreply) desde la plantilla `/etc/fs-master/email-template.json` (root 600, JSON objeto con al menos `email`, `host`, `password`; nunca va en la petición, el repo ni los logs: el SQL se registra con parámetros ocultos), vacía `FileCache` (`tools-settings.cache` oculta el cambio), comprueba la misma condición que `NewMail::canSendMail()` (email, host y password no vacíos) y que el SMTP responde por TCP. **No envía correo real**: `/RecuperarClave` responde igual envíe o no, y mandaría un enlace al cliente. La plantilla se valida ya en `preflight` (`EMAIL_TEMPLATE_MISSING`/`EMAIL_TEMPLATE_INVALID`, antes de crear nada).
8. `entitlements` — escribe los 7 `facturalo.modulos.*`.
9. `verify` — `GET https://<host>/login` = 200.
10. `admin` — usuario propietario (nick derivado del correo) con contraseña aleatoria.

**Rollback (decisión):** solo se deshace lo **reversible**: si falló tras crear el tenant se deshabilita el vhost (deja de servirse lo a medias) y el registro pasa a `error`. **Nunca se borran datos, BD ni usuarios del sistema**: borrar es irreversible y el host aloja producción. Reintentar con otro `request_id` reanuda (repone el vhost y continúa). La limpieza total de un alta fallida es manual.

### 6.1 Ejercicios: qué hace FS y qué hace el agente

Hallazgos en el código de FS (`Core/Model/Ejercicio.php`, `Wizard`, `BusinessDocument`):
- **Instalación:** `Ejercicio::install()` crea UN ejercicio, el del año de la instalación (código = año, `01-01`..`31-12`, `ABIERTO`, `longsubcuenta` 10, empresa 1). El asistente importa el plan contable solo en ese ejercicio.
- **No hay apertura automática el 1/1:** ni cron ni plugin crea el ejercicio nuevo. FS lo crea «al vuelo» (`loadFromDate($fecha)` con `create=true`) solo cuando alguien graba un documento, asiento o pago con fecha de un año sin ejercicio; ese ejercicio nace **sin plan contable**. El cierre/apertura contable (`AccountingClosingOpening`) también crea el siguiente.
- Un tenant dado de alta tarde en el año o sin actividad el 1/1 puede por tanto quedarse sin ejercicio (o con uno sin plan) para el año nuevo.

Qué hace el agente (paso `ejercicio` del alta y acción `ejercicio`): calcula el año en `Europe/Madrid`; para el año en curso y el siguiente crea el ejercicio **solo si ningún ejercicio existente se solapa con sus fechas** (nunca modifica ni borra los existentes); código = año (alternativa `00yy`, como FS), fechas 1/1-31/12, `ABIERTO`, `longsubcuenta` del ejercicio más reciente; y copia a cada ejercicio nuevo el plan (`cuentas` con su jerarquía remapeada y `subcuentas`, con saldos a 0) del ejercicio más reciente que tenga plan. Si no hay ninguno con plan, crea el ejercicio igualmente y lo avisa (`plan_copiado: false`). Límite: copia las subcuentas tal cual están en el ejercicio base (en un alta nueva son las del plan estándar); el SQL no se ha ejecutado contra MySQL real. Recomendación: el panel MASTER envía `ejercicio` a todos los tenants `active` cada 1 de enero (y en diciembre).

### Auditoría

`/var/lib/fs-master/audit.log` (JSONL, solo-inserción, root 700): `ts, request_id, event(succeeded|failed|rejected|replay-distinto), action, tenant, by, error, step`, **sin secretos**, con cadena de hashes (`prev`/`hash` = SHA-256 del canónico de la línea): editar una línea rompe la cadena. Recomendado además `chattr +a`. El MASTER mantiene su propia auditoría de operadores (quién pidió qué, con `request_id` como clave de correlación); el host registra qué se ejecutó.

## 7. DNS y TLS (decisión)

**Elegido: registro comodín `*.facturalo.redtelecom.app  A  151.80.149.132` + certificado por host con certbot HTTP-01** (lo que `fs-bootstrap-tenant` ya hace). Los registros específicos existentes (`motor.`, `facturalo.`, `carlos.`) siguen ganando sobre el comodín. **No se ha creado nada en OVH.**

Por qué:
- **Cero credenciales DNS en el servidor de producción.** El comodín de certificado exige DNS-01, es decir, una clave de API de OVH con escritura en la zona `redtelecom.app` guardada en el host que ya aloja el TPV. Un compromiso del host (o de cualquier tenant que escalara) tocaría toda la zona. HTTP-01 no necesita nada.
- **Aislamiento de claves:** con un certificado comodín la misma clave privada serviría a todos los tenants y viviría en el host; con certificados por host cada vhost lleva el suyo y se revoca por separado.
- **Alta sin pasos DNS:** con el comodín el subdominio resuelve desde el primer segundo; el MASTER ya no necesita llamar a la API de OVH y se elimina el paso manual y su fallo más frecuente (propagación).
- Coste asumido: (a) límite de Let's Encrypt de 50 certificados por semana por dominio registrado (`redtelecom.app`): suficiente al ritmo previsto, revisable; (b) el `preflight` DNS con comodín siempre resuelve, así que **no prueba** que el puerto 80 esté abierto — lo prueba `bootstrap` (si certbot falla, el alta falla en `BOOTSTRAP_FAILED`/`DEPLOY_FAILED` y se hace rollback); (c) cualquier `foo.facturalo.redtelecom.app` resuelve al host: nginx **debe tener** un `default_server` que cierre (`return 444`, como en ADR-0014). **A verificar en solo lectura antes de pedir el comodín** (ver §9).
- Alternativa descartada, y cuándo reconsiderarla: certificado comodín DNS-01 con un token OVH restringido a `_acme-challenge` mediante delegación CNAME a una zona aparte (acme-dns). Mejor si algún día se superan los 50 certificados/semana; hoy añade piezas sin beneficio.
- Revisar un registro **CAA** en la zona (debe permitir `letsencrypt.org`).

## 8. Pruebas realizadas (de verdad) y límites

`php deploy/fs-tpv/master/tests/run.php` → **173 comprobaciones, 0 fallos**, sin tocar red ni servidor (PHP 8.5 local, `SimHost` en memoria):

- Slug: válidos, 13+ inválidos/reservados/inyección (`../x`, `a\nb`, `ab;rm`, `xn--`, `a--b`).
- Firma: canónico, firma válida, dominio req/resp, kid desconocido, clave corta, documento manipulado, sin firma, `alg:none`.
- Contrato: ≈20 peticiones inválidas (campo extra, hostname ajeno, caducada, futura, módulos mal formados, `release` con traversal…).
- Máquina de estados: 17 transiciones.
- Agente end-to-end simulado: alta completa (orden de pasos y de comandos, 7 ajustes con los valores correctos, contraseña ausente de comandos/SQL/estado tras entregar), idempotencia (replay del mismo `request_id`, `provision` sobre activo = noop), suspend/reactivate (sin duplicar el `include`), entitlements con serial, status, baja en simulación (ningún comando destructivo en todo el log), rechazos (firma, JSON, protegido `carlos`, slug ocupado, slug de otro tenant, estado inválido, caducada), fallo en `bootstrap` con rollback reversible y reintento que reanuda, DNS no listo, release sin `.sha256`, cadena de auditoría íntegra.
- `agent.php --dry-run --queue=… --keys=…` ejecuta el agente completo con host simulado.

**No probado** (necesita host real y autorización): los scripts de host reales encadenados por el agente (`fs-*`), `certbot`, el `include` nginx real sobre un vhost con bloques TLS de certbot, `nginx -t`/`reload`, el SQL contra MySQL real (sentencias `UPDATE empresas`, `INSERT users`, `JSON_SET` sobre `settings`: copiadas del esquema de FS pero **no ejecutadas**), el chroot SFTP, el endurecimiento systemd, y `RealHost::exec` (timeouts). Se recomienda un ensayo en el staging del TPV o en un contenedor antes de producción.

## 9. Decisiones tomadas (OWNER delegó 2026-11-25)

1. Instalación: autorizada; pendiente de acceso root (`sudo bash instalar-todo.sh` desde `/home/ploi`), más clave `m1.key`, authorized_keys y sshd.
2. DNS: comodín creado (ver Estado).
3. Firma: **HMAC-SHA256 por ahora** (probado); Ed25519 queda como `sig.alg` futuro (el host sí tiene sodium).
4. Contraseña inicial: una sola vez en la respuesta firmada; sin plugin de invitación.
5. Rollback solo reversible; la baja real no se implementa (solo dry-run) hasta definir retención y copia.
6. Publicación de release: `fs-deploy.yml` sube también `~ploi/fs-releases/<tag>.tar.gz` + `.sha256` cuando se lanza con un tag `fs-v*`; ese tag es `release.tag`.
7. Pendiente OWNER: confirmar slugs reservados y tenants protegidos; `carlos` queda fuera del agente.

## 10. Obligaciones del lado MASTER (Teleco/Clarto)

Un registro `tenant` con ULID, slug inmutable único y estado propio (`draft→provisioning→active|error`, `active⇄suspended`); una sola petición pendiente por tenant; `request_id` ULID nuevo por cada intento; firmar con el canónico de §4; verificar la firma de la respuesta antes de fiarse; aplicar `tenant_state` de la respuesta solo de la petición más reciente del tenant; mostrar la contraseña inicial una sola vez; auditar al operador (MFA, rol `owner/operator`) por cada petición con su `request_id`.
