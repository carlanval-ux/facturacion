# VerifactuFacturalo

VERI*FACTU propio de Facturalo, **clean-room**: escrito solo desde la documentación pública de la AEAT (especificación de huella/hash, XSD `SuministroInformacion`/`SuministroLR`/`RespuestaSuministro`, endpoints). No usa ni se inspira en el plugin comercial «Verifactu». Si ese plugin está activo, este se desactiva solo (`Servicio::conflicto()`).

## Qué hace
- Al emitir una factura de cliente (no editable) genera el **registro de alta** (F1, F2 sin NIF de cliente, R1 «por diferencias» si es rectificativa) con **huella SHA-256 encadenada** (cadena única por empresa y entorno, protegida por un `UNIQUE`). La **anulación** se lanza desde la pestaña *Requerimientos* (botón «Anular registro»).
- Envía por SOAP con **certificado de cliente (mTLS)** (streams de PHP, sin extensión `soap` ni `curl`), guarda CSV y estado (`pendiente`, `aceptado`, `aceptado_errores`, `rechazado`, `error`) en `vfp_registros`, reintenta con backoff (1 min → 6 h) desde el Cron cada 5 min y respeta el `TiempoEsperaEnvio` de la AEAT.
- **QR y leyenda** «VERI*FACTU» en la cabecera del PDF (hook `qrImageHeader` del core, ECC nivel M); en pruebas rotula «(PRUEBAS)».
- **Bloqueo**: una factura con registro no admite cambios en fecha, cliente, importes, serie/número ni borrado.
- **Eventos** en `vfp_eventos` (certificado, cambio de modo, envíos, errores). Nunca contraseñas ni contenido del certificado.

## UI (EditEmpresa → pestaña «Verifactu»)
Certificado (nombre), Vencimiento, check **Modo pruebas**, check «Certificado de sello», selector **Modo Verifactu** (VERI*FACTU / Desactivado), botones **Cambiar certificado Verifactu** (.p12/.pfx + contraseña: se valida con openssl y se guarda **cifrado** con AES-256-GCM / `FS_APP_KEY`; sin `FS_APP_KEY` no se guarda) y **Comprobar Verifactu** (extensiones, certificado, destino y handshake TLS sin enviar nada). Pestañas **Requerimientos** (registros y estado, reintentar, anular) y **Eventos Verifactu**.

## Pasar de pruebas a real = desmarcar «Modo pruebas»
`Endpoint::soap($modo_pruebas, $sello)` es el **único** sitio que decide el destino, y solo recibe ese check:

| Check | Host |
|---|---|
| marcado (defecto) | `prewww1.aeat.es` / `prewww10.aeat.es` (sello) |
| desmarcado | `www1.agenciatributaria.gob.es` / `www10.agenciatributaria.gob.es` (sello) |

Sin cambios de código ni de esquema. Salvaguardas: `Transporte` revalida el destino contra el check antes de abrir el socket; cada registro lleva su `entorno` y **solo se envía al entorno en el que se creó** (un pendiente de pruebas no sale a real); al cambiar el check la cadena empieza de nuevo (`PrimerRegistro`) y `desde` se reinicia a hoy, de modo que no se registran en real las facturas anteriores al cambio. Las facturas ya registradas en pruebas quedan fuera de la AEAT real: es el comportamiento esperado al pasar a real.

## Ajustes opcionales (`Tools::settings('verifactufacturalo', ...)`)
`sif_nombre` / `sif_nif`: identidad del productor del software (bloque `SistemaInformatico`). Por defecto: RED TELECOM CONECTA, S.L. (B19918630); la AEAT valida NIF+nombre contra el censo, así que no pongas el NIF de otro con ese nombre.

## Tests (`Test/main`)
- `HuellaTest`: los 3 vectores del documento de la AEAT (primer registro, encadenado, anulación).
- `XmlTest`: altas, encadenado, rectificativa, anulación y recargo validan contra los XSD oficiales (`Test/xsd`, solo se cambió la ruta del import de xmldsig a local); lectura de respuesta/Fault; rechazo de DOCTYPE.
- `EndpointTest`: el check decide el host; `validar()` rechaza cualquier destino que no le corresponda.

## Límites conocidos (no implementado / no probado)
- **No probado contra la AEAT real** (ni preproducción): falta certificado cualificado. mTLS, handshake y mapeo de respuestas reales no están verificados end-to-end.
- Modo «No VERI*FACTU» (registros firmados XAdES, sin envío) **no** implementado: el selector solo ofrece VERI*FACTU o Desactivado.
- IVA 0 % se declara siempre exento E1; no distingue no sujeto / otras exenciones. No hay IGIC/IPSI ni operaciones intracomunitarias, ni rectificativas por sustitución ni subsanación automática de rechazados.
- La pestaña *Requerimientos* lista registros; no genera todavía el XML de respuesta a un requerimiento de la AEAT.
- `openssl_pkcs12_read` puede no abrir .p12 antiguos (RC2/3DES) con OpenSSL 3: convertirlos primero.
