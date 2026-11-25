# VERI*FACTU propio de Facturalo — diseño (solo PRUEBAS)

Estado: v0.1, 2026-11-23. Código: `integration/verifactu-service/` (servicio Node) e `integration/plugins/FacturaloVerifactu/` (plugin PHP).
**Clean-room**: no se ha consultado el plugin oficial `Verifactu` de facturascripts.com (EULA). Fuentes: especificaciones de la AEAT (XSD/WSDL, huella, QR) y el módulo propio de Teleco
(`_wt-fs-cliente/src/verifactu/`: cifrado, huella/XML, transporte, entorno), del que se reutiliza la lógica ya probada.

> **Ajuste de alcance (2026-11-23, OWNER):** plataforma única multitenant sobre Teleco-viabilidad; los clientes no ven el panel de FS. El servicio y su API por tenant
> (token por tenant, custodia de certificados) quedan como pieza central; la subida del certificado y el estado vivirán en el front de Teleco, que llama a esta API **desde su backend**
> (el token del tenant no debe llegar al navegador). El plugin `FacturaloVerifactu` pasa a pieza mínima opcional para sellar/enviar desde el motor de FS. Rotación de token: `POST /admin/instalaciones/rotar-token`.

## 1. Arquitectura

```
FS (instalación del cliente)                      Servicio central (Node)                         AEAT
 plugin FacturaloVerifactu  --HTTPS + token-->    custodia certificados cifrados                  PRE (prewww1/10.aeat.es)
 pestaña Empresa / Factura                        construye registro + huella, mTLS, estado  ---> solo pruebas; PROD bloqueado
```

Una instalación = una empresa = un certificado (decisión del OWNER: una instalación por cliente). El plugin **no guarda** certificado ni contraseña: los envía una vez al servicio y
solo lee metadatos. El servicio deduce el tenant del token; el cuerpo nunca elige empresa.

## 2. Contrato de la API (JSON, `Authorization: Bearer <token>`)

| Método y ruta | Auth | Descripción |
|---|---|---|
| `POST /admin/instalaciones` `{nombre, nif, nombre_empresa}` | `ADMIN_TOKEN` | Crea instalación; devuelve `{id, nif, token}` (el token se ve **una vez**; en BD solo su SHA-256) |
| `POST /admin/instalaciones/desactivar` `{id}` | `ADMIN_TOKEN` | El token deja de valer |
| `POST /admin/instalaciones/modo-real` `{id, activar, confirmacion}` | `ADMIN_TOKEN` | **Interruptor de modo real por tenant** (`modo_real`, por defecto 0). Activar exige: puerta global abierta, certificado real (no de pruebas, vigente, cualificado, no autofirmado) guardado en entorno `prod`, y `confirmacion` = `ACTIVAR VERIFACTU REAL <NIF>`. Desactivar siempre posible. Ambos quedan en la tabla `auditoria` |
| `GET /v1/estado` | instalación | `{entorno, modo_real, prod_habilitado, empresa, certificado\|null}` (el certificado es el del entorno vigente) |
| `POST /v1/certificado` (alias `PUT`) `{tipo, alias, p12_base64, passphrase, apoderamiento?, nif_empresa?, entorno?}` (`entorno:'prod'` deja preparado un certificado real; solo con la puerta global abierta) | instalación | Inspecciona, **verifica antes de guardar**, cifra y sustituye al activo. 201 con metadatos / 422 `{problemas, avisos}` / 409 NIF distinto / 403 prod |
| `POST /v1/certificado/revocar` (alias `DELETE /v1/certificado`) | instalación | Revoca el activo |
| `POST /v1/registros/alta` `{factura:{numserie, fecha:AAAA-MM-DD, tipo:F1\|F2, descripcion?, destinatario?, lineas:[{base_cent, iva_bp}]}, subsanacion?:bool}` | instalación | Genera registro encadenado y lo remite. Idempotente por (numserie, fecha): reenvía el mismo XML salvo que ya esté aceptado |
| `POST /v1/registros/anulacion` `{factura:{numserie, fecha}}` | instalación | Registro de anulación; exige alta previa |
| `GET /v1/registros[?numserie=]` | instalación | Registros con `estado` (`pendiente`, `aceptado`, `aceptado_con_errores`, `rechazado`, `error`), `csv`, `huella`, `qr_url` |

Respuestas **nunca** contienen PKCS#12, contraseña ni DEK; los errores 500 no devuelven el mensaje original. Los alias POST existen porque `Core\Http` de FS solo ofrece GET y POST JSON.

## 3. Modelo de datos (SQLite, `src/store.js`)

- `instalaciones(id, nombre, nif, nombre_empresa, token_hash UNIQUE, activa)`
- `certificados(id, instalacion_id, entorno, tipo, titular_nif/nombre, emisor, serie, huella_sha256, valido_desde/hasta, cualificado, autofirmado, apoderamiento_*, estado, p12_cifrado, pass_cifrada, dek_cifrada)`;
  índice único parcial → un solo certificado `activo` por (instalación, entorno).
- `registros(id, instalacion_id, entorno, tipo alta|anulacion, subsanacion, numserie, fecha, importe, huella, huella_anterior, xml, estado, csv, resp_*)`;
  único parcial (instalación, entorno, tipo, numserie, fecha) para no-subsanaciones. La cadena de huellas es por (instalación, entorno): el «anterior» es el último registro generado.

## 4. Flujos

1. **Alta de cliente** (OWNER): `POST /admin/instalaciones` → token → `FS_VERIFACTU_URL` y `FS_VERIFACTU_TOKEN` en el `config.php` de esa instalación.
2. **Certificado**: Empresa → pestaña Verifactu → fichero + contraseña + tipo (sello / representante / colaborador social; este con referencia de apoderamiento). El servicio comprueba vigencia, usos
   (firma digital, autenticación TLS cliente), NIF del titular = NIF de la instalación (o apoderamiento al NIF de la empresa), y en TEST **solo certificados de prueba**. Si algo falla no se guarda nada.
3. **Factura**: pestaña Verifactu de la factura de cliente → alta / subsanar / anular. El plugin convierte líneas a `{base_cent, iva_bp}`; F1 si hay NIF de cliente, F2 si no. El servicio muestra estado,
   CSV, huella y QR (generado en PHP con `QrImage`; la URL de cotejo es la de pruebas `prewww2.aeat.es/.../ValidarQR`).
4. **Remisión**: se descifra el certificado un instante en memoria, mTLS contra `prewww10` (sello) / `prewww1` (representante, colaborador), sin seguir redirecciones, y se guarda la respuesta.

## 5. Amenazas y controles

| Amenaza | Control |
|---|---|
| Certificado en reposo | AES-256-GCM con sobre: DEK aleatoria por certificado cifrada con `CERT_MASTER_KEY` (32 B, fuera de la BD); AAD = `id_certificado:id_instalación` → un sobre copiado a otra fila/empresa no descifra (test). Contraseña cifrada con la misma DEK. |
| Fuga por API/log | Solo metadatos; no se registran cuerpos; openssl recibe la contraseña por variable de entorno (no argv) y el PKCS#12 en directorio temporal 0700 borrado siempre. |
| Cruce de tenants | Tenant solo del token; todas las consultas filtran `instalacion_id`; tests: B no ve ni usa el certificado de A. Instalación desactivable. |
| Certificado ajeno/caducado/de otra empresa/sin usos | Verificación previa al guardado y re-comprobación de vigencia al remitir; tests negativos. |
| Remisión real accidental | Dos llaves: (1) puerta global, ajuste de despliegue `VERIFACTU_PROD_HABILITADO` (solo `true` la abre; defecto cerrada; sin ella no se puede ni guardar un certificado real ni activar el modo real, y `validarDestino('prod')` rechaza); (2) check `modo_real` **por tenant** (defecto 0), que es el **único** selector del endpoint (`entornoDe`): ninguna variable de entorno ni la mera presencia de un certificado real lo cambia (test). En TEST, lista blanca (`prewww*.aeat.es`, loopback/`.test`) + lista negra de `agenciatributaria.gob.es` y subdominios; sin redirecciones; validación antes de abrir socket. Activarlo exige cambio de código revisado y autorización expresa del OWNER. |
| Token de instalación robado | Token de 256 bits, solo su hash en BD, desactivable; el servicio escucha en 127.0.0.1 por defecto (poner detrás de TLS). |
| Apoderamiento falso | Es una declaración: el servicio avisa «NO verificable sin conexión»; solo la AEAT lo confirma. |

### Pasar a real

Por tenant es **un ajuste** (`modo-real`), sin cambios de código ni de esquema. La puerta global (llave 1) es una variable de entorno, cerrada por defecto; abrirla requiere autorización expresa del OWNER y validación normativa, no código. Cerrada, `modo-real` responde 403. Ver el README del servicio.

## 6. Límites conocidos (no ocultos)

- La AEAT **no documenta** certificados de prueba oficiales y el sandbox exige un certificado cualificado real (ver `ENDPOINTS-Y-REQUISITOS.md` del repo de Teleco): por eso las pruebas usan un **simulado local**
  y certificados generados con openssl. No se ha hablado nunca con `prewww*.aeat.es`. Las respuestas reales pueden diferir del simulado.
- Soporta F1/F2 con IVA por líneas; **no** rectificativas (R1–R5), recargo de equivalencia, IRPF, exentas distintas de E1, ni lotes.
- Sin rate-limit propio, sin rotación de token ni de `CERT_MASTER_KEY`, sin firma XAdES del registro (opcional en Verifactu), sin cola de reintentos/espera de `TiempoEsperaEnvio`.
- El plugin PHP no se ha podido ejecutar ni lintar en esta máquina (sin PHP); ver pendientes en el informe.
- Una instalación FS multiempresa mostraría la pestaña en todas las empresas; el servicio rechaza (409) un `nif_empresa` distinto del de la instalación.

## 7. Operación

```
CERT_MASTER_KEY=$(openssl rand -base64 32) ADMIN_TOKEN=<≥20 car.> DB_PATH=./verifactu.sqlite node src/server.js
cd integration/verifactu-service && npm test      # Node ≥ 22.13, openssl en PATH
```

Variables: `PORT`, `HOST`, `VERIFACTU_URL_TEST` (simulado; solo se usa en modo pruebas), `VERIFACTU_CA_TEST`, `SISTEMA_NIF`. Perder `CERT_MASTER_KEY` inutiliza todos los certificados guardados (hay que volver a subirlos).
