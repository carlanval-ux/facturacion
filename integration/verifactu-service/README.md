# Servicio central VERI*FACTU de Facturalo

Diseño y contratos: [docs/verifactu-propio.md](../../docs/verifactu-propio.md). Node ≥ 22.13 + `openssl` en PATH, sin dependencias npm. `npm test` (16 pruebas, sin red).

## Modos: pruebas por defecto, real por tenant

- Cada tenant tiene `modo_real` (defecto **0** = entorno de pruebas de la AEAT). Es el **único** selector del endpoint.
- La puerta global es el ajuste de despliegue `VERIFACTU_PROD_HABILITADO`. Solo el texto exacto `true` la abre; ausente, `false`, `1`, `TRUE`… = cerrada. Cerrada: subir un certificado real y activar el modo real devuelven 403.
- Se abre solo con autorización expresa del OWNER. No hace falta tocar código ni esquema.

### Activar el modo real de un tenant (ejemplo; **no ejecutar en pruebas**)

1. Abrir la puerta: `VERIFACTU_PROD_HABILITADO=true` en el entorno del servicio y reiniciar.
2. El tenant sube su certificado real preparado: `POST /v1/certificado` con `entorno: "prod"` (no de pruebas, cualificado, vigente, titular = NIF del tenant; con apoderamiento si es representante).
3. Activar (backend de la plataforma, `ADMIN_TOKEN`):
   `POST /admin/instalaciones/modo-real {"id":"<id>","activar":true,"confirmacion":"ACTIVAR VERIFACTU REAL <NIF>"}`
4. Volver a pruebas: mismo endpoint con `"activar":false`. Todo queda en la tabla `auditoria`.

## Alojarlo en teleco-test (guía; no se despliega desde aquí)

```bash
cd integration/verifactu-service
# 1) secretos FUERA del repo (p. ej. /opt/verifactu/verifactu.env, chmod 600)
CERT_MASTER_KEY=$(openssl rand -base64 32)   # guárdala en el gestor de secretos: si se pierde, hay que resubir los certificados
ADMIN_TOKEN=$(openssl rand -base64 32)
# 2) arrancar (solo loopback 127.0.0.1:8787; VERIFACTU_PROD_HABILITADO queda en false)
docker compose --env-file /opt/verifactu/verifactu.env up -d --build
# 3) TLS: publicar con el proxy de teleco-test (p. ej. https://verifactu.<dominio-test>) hacia 127.0.0.1:8787
# 4) dar de alta un tenant de pruebas y guardar su token en el backend de Teleco (se muestra una sola vez)
curl -s -X POST http://127.0.0.1:8787/admin/instalaciones -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"nombre":"teleco-test","nif":"B12345674","nombre_empresa":"Empresa de prueba SL"}'
curl -s http://127.0.0.1:8787/v1/estado -H "Authorization: Bearer <token>"   # entorno test, modo_real false
```

Sin Docker: `CERT_MASTER_KEY=… ADMIN_TOKEN=… DB_PATH=/var/lib/verifactu/verifactu.sqlite node src/server.js` bajo systemd, con copia de seguridad del fichero SQLite.
La imagen y el compose no se han construido ni ejecutado todavía.
