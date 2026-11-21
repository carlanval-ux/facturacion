# FS-002: aprovisionamiento SaaS de FacturaScripts (solo pruebas locales)

Un servicio FS independiente por cliente. Datos ficticios; nada de producción, TLS ni servicios de pago.
Reutiliza `deploy/fs-poc/` (Dockerfile, `bootstrap.sh`, `provision.sql`) sin modificarlo. No toca el PoC FS-001.

## Arquitectura

Por tenant `<slug>` (`[a-z0-9-]{3,30}`):

- proyecto compose `fs-<slug>` (plantilla única `docker-compose.tenant.yml`): red propia, contenedores `db` (MariaDB 11.4 propia) y `fs`;
- volúmenes con nombre propio `fs-<slug>-dbdata` y `fs-<slug>-myfiles`;
- puerto `127.0.0.1:<8100-8199>` determinista por hash del slug (si está ocupado, el siguiente libre); solo loopback;
- imagen `fs-saas:<IMAGE_TAG>` (por defecto `local`), construida desde el Dockerfile del PoC;
- secretos y API key fuera del repo, en `$SECRETS_DIR/<slug>/` (`compose.env`, `apikey`), generados aleatoriamente y nunca impresos.
  Por defecto `D:/temp/fs-saas-secrets` (en WSL `/mnt/d/temp/fs-saas-secrets`). En unidades montadas de Windows (drvfs) `chmod 600/700` no se aplica: usar un `SECRETS_DIR` en ext4 o un gestor de secretos fuera de pruebas;
- API key mínima propia (sin `fullaccess`), plugin TelecoBridge activado.

Todos los scripts son bash y se ejecutan dentro de WSL (donde está Docker), parametrizados por `TENANT`.

## Uso

```bash
cd /mnt/c/Users/Propietario/facturacion
TENANT=acme COMPANY="Acme Ficticia SL" NIF=B12345678 CODPAGO=CONT SERIE=A bash deploy/fs-saas/provision-tenant.sh
TENANT=acme KEEP=7 BACKUP_DIR=/mnt/d/temp/fs-saas-backups bash deploy/fs-saas/backup-tenant.sh   # imprime la ruta de la copia
TENANT=acme2 bash deploy/fs-saas/restore-tenant.sh <ruta_copia>        # tenant nuevo/vacío; FORCE_OVERWRITE=1 si tiene datos
TENANT=acme bash deploy/fs-saas/upgrade-tenant.sh [nuevo_tag]          # copia -> imagen -> rebuild -> healthcheck -> rollback
TENANT=acme bash deploy/fs-saas/monitor-tenant.sh                      # JSON; exit != 0 si algo falla
TENANT=test-x bash deploy/fs-saas/destroy-tenant.sh                    # solo slugs test-*
bash deploy/fs-saas/test-saas.sh                                       # prueba E2E completa (~10 min)
```

Notas de diseño:

- Re-ejecutar `provision-tenant.sh` sobre un tenant ya aprovisionado no cambia nada (secretos y BD intactos).
- `backup-tenant.sh`: `mariadb-dump --single-transaction` + tar de MyFiles + `SHA256SUMS` + `manifest.json` (tenant, fecha, versión FS, tablas, nº de facturas). `restore-tenant.sh` verifica el sha256 antes de tocar nada.
- `restore-tenant.sh` rota la API key y la contraseña admin del tenant restaurado (quedan en su `SECRETS_DIR`); con `KEEP_CREDENTIALS=1` las conserva (lo usa el rollback del upgrade).
- `upgrade-tenant.sh`: ante fallo restaura imagen y datos desde la copia previa y sale con código 3. `FORCE_HEALTH_FAIL=1` fuerza el fallo para probar el rollback.
- `monitor-tenant.sh`: contenedores, HTTP de `/api/3` con token, reservas `pending` de más de 10 min en `teleco_idempotencia`, tamaño de volúmenes (`MAX_VOL_MB`) y edad de la última copia (`MAX_BACKUP_AGE_H`).
  Atajo: el corte de 10 min se calcula en la zona `Europe/Madrid`, la que usa el plugin al escribir `creado`.

## NO está hecho

- Reverse proxy con TLS (Caddy/Traefik), DNS y subdominio por cliente.
- Panel/alta automática desde Teleco (el alta es por script).
- Cron real de monitor y backups, y alertas.
- Backup offsite/cifrado (las copias van a un directorio local).
- Límites reales de CPU/RAM/disco por tenant (el monitor solo avisa por umbral de tamaño).
- Gestor de secretos y rotación periódica; BD con usuario propio no-root (hoy FS usa root de su MariaDB aislada).
- Migración real entre versiones de FS (el upgrade se probó con el mismo tag).

## Resultados reales de `test-saas.sh`

Ejecución real (WSL, 2026-11-19), `bash deploy/fs-saas/test-saas.sh`, ~10 min:

```
== 1. provisión de dos tenants
PASS  puertos distintos en 8100-8199
PASS  solo loopback
PASS  provision no imprime secretos
PASS  re-provision idempotente (secretos intactos, no reinstala)
PASS  empresa/serie/codpago propios (B)
== 2. aislamiento
PASS  A con su key = 200, B con su key = 200
PASS  key de A en B = 401/403
PASS  key de B en A = 401/403
PASS  volúmenes y redes propios
== 3. factura idempotente en A
PASS  cliente creado en A
PASS  factura creada con codigo (F2026A1)
PASS  cliente de A no existe en B
== 4. backup de A + monitor
PASS  backup con manifest (1 factura, sha256 ok)
PASS  monitor A: exit 0 y ok=true
{"tenant":"test-a","containers":{"db":"running/healthy","fs":"running"},"api":{"http":200},"idempotencia_pending_antiguas":0,"volumes_mb":{"db":191,"myfiles":1},"last_backup_age_h":0,"ok":true}
PASS  monitor B (sin copia): exit != 0, backup=false
== 5. guardas
PASS  restore sobre tenant con datos rechazado sin flag
PASS  destroy rechaza slug no test- (exit 2)
== 6. destruir A y restaurar en test-a2
PASS  A destruido (sin contenedores ni volúmenes)
PASS  key de a2 rotada (distinta de A) y válida
PASS  factura F2026A1 existe en a2
PASS  misma Idempotency-Key: replay, mismo codigo
PASS  sigue habiendo 1 factura
PASS  misma clave con cuerpo distinto: 422
== 7. upgrade (mismo tag) y rollback
PASS  upgrade simulado OK + healthcheck 200
PASS  fallo forzado -> rollback automático (exit 3), API 200 y 1 factura
PASS  monitor a2 final ok
== 8. limpieza
PASS  sin restos test-*
PASS  PoC FS-001 intacto
RESULTADO: 28 PASS, 0 FALLO
```

Hallazgo de la prueba: al recrear el contenedor `fs` (restore/upgrade) se pierden `config.php`, `.htaccess` y `Dinamic` (no están en volumen) y FS vuelve al instalador. `lib.sh` guarda una copia de `config.php` en `SECRETS_DIR` y la repone, y además retira `deploy/` e `integration/` del contenedor (`/deploy` de FS colisiona con ese directorio).
