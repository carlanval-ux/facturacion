# FacturaScripts en el servidor PHP del TPV

Decisión del OWNER: el único servidor PHP es el del TPV, así que FS (un FS por tenant, ADR de FS-002) va ahí.

**Host (verificado con Ploi, solo lectura):** `rt-tpv-prod-01` (id 123550), 151.80.149.132, Ploi, nginx + PHP-FPM **8.5.12**, MySQL 8.4, 2 sitios. El TPV (NexoPOS, Laravel 12) se aprovisiona con `retail-pos/docs/tpv/staging/tenant-provision.sh`: usuario de sistema, pool FPM, BD y vhost por tenant, `/etc/tpv/host.conf`.

`provision-fs-tenant.sh` replica ese modelo para FS (`/srv/fs/<slug>`, BD `fs_<slug>`, pool `fs-<slug>`). **No se ha ejecutado en ningún servidor.**

## Antes de ejecutarlo (bloqueos)
1. **PHP 8.5 (VERIFICADO 21-11-2026 en local, php:8.5-apache + MariaDB):** instalación, plugin, API y el extremo a extremo real de Teleco (12/12) funcionan. Dos requisitos, ya en el script: el pool debe llevar `display_errors=off` y `error_reporting=E_ALL & ~E_DEPRECATED` (si no, los avisos `imagedestroy()` de PHP 8.5 corrompen los PDF) y hay que vaciar `MyFiles/Tmp/FileCache` tras tocar la BD por SQL.
2. **Autorización del OWNER** para tocar `rt-tpv-prod-01` (ya aloja el TPV en producción). Recomendado probar antes en el staging del TPV.
3. TLS (certbot), DNS del subdominio y copias fuera del servidor: igual que el TPV (RUNBOOK del TPV).
4. Sustituir el aprovisionamiento Docker de `deploy/fs-saas` por este (el Docker sirvió solo para test).
5. Certificado real de AEAT: solo en este entorno real, tras autorización expresa; sigue pendiente.
