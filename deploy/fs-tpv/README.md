# FacturaScripts en el servidor PHP del TPV

Decisión del OWNER: el único servidor PHP es el del TPV, así que FS (un FS por tenant, ADR de FS-002) va ahí.

**Host (verificado con Ploi, solo lectura):** `rt-tpv-prod-01` (id 123550), 151.80.149.132, Ploi, nginx + PHP-FPM **8.5.12**, MySQL 8.4, 2 sitios. El TPV (NexoPOS, Laravel 12) se aprovisiona con `retail-pos/docs/tpv/staging/tenant-provision.sh`: usuario de sistema, pool FPM, BD y vhost por tenant, `/etc/tpv/host.conf`.

`provision-fs-tenant.sh` replica ese modelo para FS (`/srv/fs/<slug>`, BD `fs_<slug>`, pool `fs-<slug>`). **No se ha ejecutado en ningún servidor.**

## Antes de ejecutarlo (bloqueos)
1. **PHP 8.5**: FS declara `>=8.1` y mi PoC solo corrió en 8.3. Hay que probar FS v2026.72 + TelecoBridge en PHP 8.5 (en local, no en el host) y, si falla, instalar 8.3 en paralelo (`FS_PHP_VERSION=8.3`), que es un cambio en producción del TPV.
2. **Autorización del OWNER** para tocar `rt-tpv-prod-01` (ya aloja el TPV en producción). Recomendado probar antes en el staging del TPV.
3. TLS (certbot), DNS del subdominio y copias fuera del servidor: igual que el TPV (RUNBOOK del TPV).
4. Sustituir el aprovisionamiento Docker de `deploy/fs-saas` por este (el Docker sirvió solo para test).
5. Certificado real de AEAT: solo en este entorno real, tras autorización expresa; sigue pendiente.
