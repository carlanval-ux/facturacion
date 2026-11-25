# QA funcional de Facturalo — 2026-11-23

Entorno: `https://facturalo.redtelecom.app` (tenant `teleco-test`, empresa ficticia «Gestoria Ficticia SL», FacturaScripts v2026.72 + plugins Facturalo y TelecoBridge). Producción (`redtelecom`) no tocada. Ninguna factura transmitida a la AEAT. Datos ficticios.

Método: recorrido como un cliente real. Pantallas internas con una sesión HTTP de admin hecha desde el servidor (mismos POST que el navegador: `save-doc`, `save-status`, `approve-document`, `new-refund`, `save-paid`, exportaciones); API REST con `Token:`. Totales contrastados con un cálculo independiente en `Decimal` (redondeo medio hacia arriba por base e impuesto).

Leyenda: OK verificado · AVISO funciona con matices · FALLO · NV no verificable aquí.

## Matriz caso / resultado / evidencia

| # | Caso | Resultado | Evidencia |
|---|---|---|---|
| 1 | Empresa, ejercicio, series, pagos, impuestos, almacén | AVISO | Empresa 1; ejercicio 2026 abierto (único); series A/R/S; pagos CONT, PAYPAL, TARJETA, TRANS(+1 mes); IVA 21/10/4/0 con recargo 5,2/1,4/0,5 y retenciones IRPF 2/7/15/19/21; almacén ALG. Defectos D1, D2, D4, D13 |
| 2 | Cliente persona y empresa, producto/servicio, tarifa | OK | Clientes 1 (NIF) y 2 (CIF), 3 (recargo); productos SRV-FIBRA 33,33 / LIB-01 10,10 / HOSP-01 45,55; tarifa MAY aplicada: 33,33 → 28,3305 |
| 3 | Presupuesto → pedido → albarán → factura; factura directa | OK | PRE2026A2 → PED2026A1 → ALB2026A1 → F2026A8, total 112,17 en toda la cadena; F2026A14 directa |
| 4a | Numeración correlativa por serie | AVISO | 264 facturas serie A, 0 duplicados; R1 independiente. Hueco al borrar un boceto intermedio (D5) |
| 4b | Bases/IVA/IRPF/recargo/redondeos | OK (con D7) | I1 IVA mixto + dto + dto2: 144,55 / 16,48 / 161,03. I2 IRPF 15 %: 1050,55 / 220,62 / −157,58 / 1113,59. I4 7 líneas 0,333×3: 6,99 / 1,47 / 8,46. I5 IVA 0 + 21. Total = neto+IVA+recargo−IRPF en las 264 facturas (0 incoherentes). Recargo con producto en cliente RE: línea 33,33 → 42,06 (5,2 %) |
| 4c | Rectificativa | AVISO | UI: F2026R1 (serie R) sobre F2026A10, importes exactamente negados incl. IRPF (−1113,59), asiento y recibo negativos. API `crearFacturaRectificativaCliente`: usa la serie A, no R (D6) |
| 5 | PDF (UI y API `exportarFacturaCliente`) | OK con matices | `%PDF-1.3`, 38 KB; `/Creator` y `/Producer` = Facturalo; 0 apariciones de «facturascripts» (metadatos, texto, streams descomprimidos). Datos de empresa en cabecera; IVA desglosado; recibo y vencimiento. Pie: solo si existe formato de impresión (D8). Rectificativa titulada «Factura Rectificativa» con original |
| 6 | Cobros/vencimientos/pagada | OK | Recibo vence a +1 mes (TRANS); `save-paid` y API `pagarFacturaCliente/14` → `pagada:true` |
| 7 | Envío por correo | NV hasta tener contraseña | SMTP smtp.mail.ovh.net:465 SSL. Envío real → «Error al enviar el correo electrónico / Error al conectar al servidor de correo» en 5,6 s (esperado sin contraseña). Plantilla de asunto/cuerpo en español. Caso a repetir abajo |
| 8 | Listados, filtros, exportación | OK | `query`, `filtercodcliente`, etc. funcionan; export CSV (`;`, UTF‑8 BOM), XLS (xlsx real, `PK`), PDF de listado (53 KB). Recibos solo como pestaña de facturas, sin menú propio |
| 9 | Contabilidad | AVISO | Asiento cuadrado por factura (A10: 4300000004 D 1113,59; 4770000021 H 220,62; 4730000000 D 157,58; 7000000000 H 1050,55); plan contable PGC cargado. No hay Libro diario ni Balance en el núcleo (D9) |
| 10 | Usuario no admin | AVISO | `qa-empleado` (nivel 2, rol employee): 403 en Ajustes, Plugins, Usuarios, Empresas, API, Correo, Logs, Actualizador. Pero puede crear series, editar IVA y borrar facturas en borrador (D5, D10) |
| 11 | Español | OK | 23 pantallas revisadas: sin claves sin traducir (salvo `without-channel`, `teleco-bridge` en pestañas) ni inglés; PDF en español. La página 404 va con `lang="en"` y estilo ajeno (D12) |
| 12 | Rendimiento (200 facturas × 2 líneas) | OK | 200/200 creadas, media 0,54 s, p95 0,69 s, máx 1,12 s; listado 0,24 s; CSV 0,26 s; PDF 0,12 s; PDF de listado 0,20 s. Páginas de 620 KB (D13) |

## Defectos

Severidad: Alta = el cliente factura mal o no puede facturar; Media = falla un flujo habitual o hay riesgo legal/operativo; Baja = molestia.

| ID | Sev. | Defecto | Origen | Estado |
|---|---|---|---|---|
| D1 | Alta | El impuesto por defecto está vacío: una línea libre se guarda con IVA 0 % sin avisar | Config | **Corregido** en plugin (`Defaults`: IVA21 si vacío) |
| D2 | Alta | «Vender sin stock» desactivado: un servicio no se puede facturar («No hay suficiente stock de SRV-FIBRA») | Config | **Corregido** en plugin (por defecto activo; los productos ya creados conservan su ajuste) |
| D3 | Media | Cliente de recargo de equivalencia + línea libre: al elegir el impuesto a mano el recargo queda en 0 (con producto sí se aplica) | Núcleo FS (`SalesLineHTML`) | Documentado |
| D4 | Media | Solo existe el ejercicio 2026; el 1‑1‑2027 no se podrá facturar | Config/alta | Abierto: crear 2027 en el alta de clientes y en los tenants existentes |
| D5 | Media | Borrar un boceto intermedio deja hueco en la numeración (serie A: falta el 262) y el rol employee puede hacerlo | Núcleo FS | Documentado; recomendación: no borrar, emitir/rectificar |
| D6 | Media | API `crearFacturaRectificativaCliente` no usa la serie de rectificativas (R) por defecto, la emite en la A y ya emitida | Núcleo FS | Documentado; el integrador debe enviar `codserie=R` |
| D7 | Baja | Para clientes RE, el recargo no se propaga al cambiar el impuesto en una línea que partía de 0 (variante de D3) | Núcleo FS | Documentado |
| D8 | Media | No hay formato de impresión para la serie A: no se puede poner logo ni pie hasta crearlo (con él, el pie sale en el PDF; creado «Factura general» en teleco-test) | Config/alta | Abierto: crear formato por defecto en el alta |
| D9 | Media | Sin Libro diario ni Balance en el menú de Contabilidad | Núcleo FS (van en plugin aparte) | Abierto: decidir plugin o informe propio |
| D10 | Media | Rol employee puede crear series y editar tipos de IVA | Núcleo FS (rol por defecto) | Abierto: recortar el rol en el alta |
| D11 | Baja | Una sola sesión por usuario: un segundo login del mismo usuario invalida el primero (afecta a automatismos y a quien comparta usuario) | Núcleo FS | Documentado |
| D12 | Baja | Página 404 del núcleo: `lang="en"`, degradado morado y Bootstrap desde cdn.jsdelivr.net | Núcleo/visual | Para la sesión de imagen |
| D13 | Baja | Páginas pesadas (~620 KB en listado y edición de facturas) | Núcleo FS | Documentado |
| D14 | Baja | «Precio + impuestos» con 3‑4 decimales en listado y CSV de productos (40,3293) | Núcleo FS | Documentado |
| D15 | Baja | Catálogo de impuestos con IGIC e IPSI (14 de 18 filas) en un cliente peninsular | Config | Abierto |
| D16 | Baja | Las facturas generadas desde albarán quedan en «Boceto» y el PDF dice «todavía es un boceto» hasta emitirlas a mano | Núcleo (flujo) | Documentado |
| D17 | Baja | La API de TelecoBridge tiene clave con permisos mínimos (correcto) pero el contador de incidentes del núcleo bloquea 10 min la IP del tenant tras 5 «Prohibido»; cualquier prueba mal dirigida deja sin API a la integración | Núcleo FS | Documentado |
| — | Info | Pie/logo/IBAN, teléfono y dirección del cliente vacío («: , España» en el PDF) dependen de los datos del cliente | Datos | — |

## Corrección entregada

`integration/plugins/Facturalo/Lib/Defaults.php` + enganche en `Init::init()` (solo web, una vez por instalación vía marcador `facturalo_defaults`, no pisa un impuesto ya elegido) + prueba `Test/main/DefaultsTest.php`. `php -l` correcto en PHP 8.5 del servidor; la prueba **no se ha podido ejecutar** (sin PHP/MySQL en local y sin acceso a la BD del tenant). Verificación real pendiente de desplegar (ver más abajo).

## Caso listo para repetir: correo

1. En `ConfigEmail`, teclear la contraseña de `noreply@redtelecom.app` y guardar.
2. Abrir `SendMail?modelClassName=FacturaCliente&modelCode=9` (F2026A9, emitida).
3. Destinatario de prueba propio, enviar. Esperado: «Correo enviado», `femail` rellenado en la factura y PDF adjunto. Con credenciales mal, el mensaje actual es «Error al conectar al servidor de correo».

## Estado dejado en teleco-test

Clientes 1–3, productos SRV-FIBRA/LIB-01/HOSP-01, tarifa MAY (−15 %) y cliente 2 con ella, ~265 facturas (F2026A8–A264, rectificativa F2026R1), formato «Factura general» con pie, usuario `qa-empleado` (nivel 2; contraseña de prueba solo de este tenant). Facturas A1–A7 son de otras sesiones.

## Skills y controles aplicados

Sin skills de ingeniería cargadas (QA funcional + cambio mínimo de configuración); `reference-driven-development`: evaluada; no aplicable (sin referente que replicar, es control de calidad). Evidencias: totales contra cálculo independiente, 200 facturas, PDF inspeccionado a nivel de bytes.

---

# Anexo: varias empresas en una instalación (ajuste de alcance: FS como motor interno, una empresa por tenant de la plataforma)

Prueba en teleco-test con una 2.ª empresa («Empresa Segunda QA SL», idempresa 2, almacén `1`, ejercicio `B026`, plan contable importado). Contra el motor por API y exportaciones.

## Qué se comparte y qué se separa

| Se separa por empresa | Se comparte entre todas las empresas |
|---|---|
| Documentos (idempresa), numeración (por serie + ejercicio + empresa: `FB026A1…` frente a `F2026A262…`), ejercicio y plan contable (subcuentas de cliente distintas: 4300000003 en B026), asientos, almacenes, formas de pago, cuentas bancarias, formatos de impresión/pie/logo, datos de empresa en el PDF | **Clientes** (sin columna de empresa), **productos y tarifas**, **series** (la serie A es la misma para todos), **impuestos y retenciones**, agentes, grupos, usuarios, claves API, plugins |

## Resultados

| Caso | Resultado | Evidencia |
|---|---|---|
| Numeración independiente por empresa/ejercicio | OK | `FB026A1–A5`, rectificativa `FB026R1` (serie R, API con `codserie=R`) frente a `F2026A262–A266` |
| Cálculo, PDF y contabilidad de la 2.ª empresa | OK | FB026A1: 100 + 21 = 121; asiento 4300000003 D 121 / 4770000021 H 21 / 7000000000 H 100; PDF con razón social, NIF y dirección de la empresa 2 y sin el pie de la empresa 1 |
| Cobro y rectificativa por API en la empresa 2 | OK | `pagarFacturaCliente/268` → pagada; `crearFacturaRectificativaCliente` → FB026R1, −121 |
| Empresa de destino de `telecoFacturaCliente` | FALLO (M1) | La empresa sale de `codalmacen`; sin él se factura en la empresa por defecto (F2026A266, empresa 1). `idempresa` en el cuerpo se ignora si hay almacén |
| Idempotencia entre empresas | FALLO (M2) | La clave era global: la misma clave con cuerpo distinto en otra empresa devuelve 422 «reused-with-different-body» (revela que existe) y, con cuerpo idéntico, repetiría la factura de otro tenant |
| Clientes duplicados por NIF | AVISO (M3) | `POST clientes` con el NIF de un cliente existente crea el cliente 4 sin avisar |
| Aislamiento entre usuarios | AVISO (M4) | Un usuario normal de la empresa 1 ve en el listado las facturas de la empresa 2 (CSV con `FB026A1`); la empresa del usuario es solo su valor por defecto |
| Código de ejercicio | AVISO (M5) | `codejercicio` es único en toda la instalación (no se puede repetir «2026») y limitado a 1–4 caracteres: hay que repartir códigos por empresa y año (aquí `B026`) |
| API key | AVISO (M6) | No está ligada a una empresa: la misma clave puede facturar a cualquier cliente de cualquier empresa |

## Defectos nuevos y correcciones

| ID | Sev. | Defecto | Estado |
|---|---|---|---|
| M1 | **Alta** | Una llamada sin `codalmacen` factura en la empresa por defecto (riesgo de mezcla entre tenants) | **Corregido** en TelecoBridge: con más de una empresa, `codalmacen` es obligatorio y debe existir (400). Con una sola empresa no cambia nada |
| M2 | **Alta** | Clave de idempotencia global | **Corregido**: con varias empresas la clave se guarda ligada a la empresa (`sha256(empresa|clave)`); con una sola, igual que antes (compatible con lo ya reservado) |
| M3 | Media | NIF duplicado permitido en clientes | Abierto: la plataforma debe buscar por NIF antes de crear, o usar un cliente por tenant con prefijo propio |
| M4 | Media | Sin aislamiento por empresa entre usuarios del panel | Abierto: irrelevante si los clientes nunca ven FS; el acceso es solo por API/administración |
| M5 | Media | Códigos de ejercicio globales y de 4 caracteres | Abierto: el alta de tenant debe asignar un código libre (p. ej. contador base 36) y crear/importar plan contable |
| M6 | Media | API key sin ámbito de empresa | Abierto: el aislamiento lo tiene que garantizar la plataforma (tabla tenant→empresa/almacén/cliente) o una clave por tenant con `ApiAccess` y comprobación en un controlador propio |
| D5 (revisado) | Baja | La numeración rellena el primer hueco libre: tras borrar el borrador 262, la factura siguiente tomó el 262 (fecha posterior a la 264) | Documentado |

Las correcciones M1/M2 (`integration/plugins/TelecoBridge/Controller/ApiTelecoFacturaCliente.php`, con `Test/main/IdempotencyScopeTest.php`) pasan `php -l`; el test no se ha podido ejecutar y **no están desplegadas** (ver estado del despliegue).

Estado dejado además: empresa 2, almacén `1`, ejercicio `B026` con plan contable, facturas `FB026A1–A5` y rectificativa `FB026R1`, cliente duplicado 4.

## Estado del despliegue

Falta etiquetar (`fs-v2026.72-qa1`): el push del tag fue denegado por la política de permisos de esta sesión (el despliegue lo autorizó otra sesión, no el usuario). Pendiente de que el usuario lo apruebe; hasta entonces teleco-test sigue con el código anterior, así que D1/D2 (IVA21 y venta sin stock) siguen sin verificar en vivo.
