# Facturalo ↔ Clarto: mapa de correspondencia (entidad a entidad y campo a campo)

Fecha: 24/11/2026 · Estado: **análisis y diseño. No implementa ni despliega nada; no toca producción, servidores ni Clarto.**
Documento técnico interno (aquí sí se nombra FacturaScripts = «FS»; en textos de producto al cliente no).
Etiquetas: **PROBADO** (leído en código, con ruta), **NO VERIFICADO** (falta comprobar en datos reales), **DECISIÓN** (propuesta para el OWNER).
Fuentes: repo `carlanval-ux/facturacion` (FS 2026.72, `Core/Table/*.xml`, `Core/Model`, `Core/Controller/Api*.php`, `Core/Data/Codpais/ESP/*`, plugins propios `integration/plugins/Facturalo` y `TelecoBridge`); repo Teleco-viabilidad (= Clarto) en lectura (`migrations/001..137`, `src/contabilidad.js`, `diario.js`, `facturacion.js`, `facturalo/*`, `docs/FACTURALO-PLATAFORMA.md`, `FACTURALO-F0-INFORME.md`). **No se ha leído el plugin oficial Verifactu** (clean-room): su integración solo se mapea por interfaz/API y queda pendiente del script de solo lectura (§9).
Complementos: `docs/FACTURALO-DEUDA-PLUGINS.md` (funciones propias a construir), cruzado en §8.

---

## 0. Resumen ejecutivo

1. **Hay encaje estructural bueno**: ambos son PGC español, ambos trabajan con subcuenta de 10 dígitos por defecto (FS: `ejercicios.longsubcuenta`=10, rango 4–15; Clarto: `contabilidad_empresa.longitud_subcuenta` 9–11, default 10), ambos usan 430/400/410 para terceros y 472/477/473/4751 para IVA y retenciones, y ambos admiten rectificativas con importes negativos.
2. **Clarto NO tiene tabla de plan de cuentas**: el plan vive en código (`contabilidad.js:11`, `diario.js:17`, `clasificador.js:28`) y las cuentas de asiento solo se validan por regex. FS tiene el plan completo (1 660 filas ESP: 9 grupos, 78 de 2 díg., 421 de 3, 397 de 4, 10 de 5 y 745 subcuentas de 10). **Hueco crítico G1**: sin catálogo de cuentas en Clarto no se puede garantizar «mismo plan, mismos nombres».
3. **Fuente de verdad (DECISIÓN D-A)**: Clarto/gestoría es **maestro de parámetros contables** (longitud de subcuenta, variante de PGC, cuentas especiales, series, retenciones, cierres trimestrales). Facturalo se configura **al alta de la empresa** con un CSV de plan generado por Clarto (formato nativo de FS `cuenta;descripcion;cuentaesp`). Mientras Clarto no tenga catálogo (G1), se **siembra desde el CSV de FS** (el único plan completo hoy) y desde ese día es Clarto quien lo gobierna.
4. **Diferencias que rompen la igualdad si no se corrigen** (detalle §4): (a) FS crea una subcuenta de IVA **por tipo** (`4770000021`) y Clarto usa la de grupo expandida (`4770000000`); (b) FS numera `F2026A1`, Clarto `A2026-000001`; (c) FS no tiene cierre trimestral, Clarto sí; (d) FS reutiliza huecos de numeración en facturas de cliente (`usarhuecos=true`); (e) la subcuenta de cliente la elige FS (`430`+código de cliente) y Clarto asigna «primer número libre»; (f) Clarto no modela IGIC/IPSI, exenciones ni operaciones intracomunitarias que FS sí; (g) FS guarda importes en `double` de euros, Clarto en céntimos.
5. **Sentido de la sincronización**: Facturalo → Clarto para **facturas emitidas y recibidas, clientes/proveedores, cobros y estado VERI\*FACTU** (Facturalo es donde nace el dato); Clarto → Facturalo para **plan contable, ejercicios, series, retenciones, cuentas especiales y bloqueos de periodo**; empresa/NIF y régimen de IVA: alta desde Clarto, edición con alerta bidireccional.
6. **Idempotencia**: `fs:<instancia>:<idfactura>` (documentos) y `fs:<instancia>:cli:<codcliente>` (terceros), `UNIQUE (client_id, origen, id_externo)`; segunda red: `clave_contable` de Clarto (`NIF|nº normalizado|fecha|total_cent`). Pull desde la API de FS con `FS_API_KEY` por tenant (el cuerpo de la API solo se lee como form-urlencoded; para leer da igual, GET).
7. **Core de FS no guarda nada de VERI\*FACTU** (sin huella, QR persistido ni estado AEAT): todo eso lo aporta el plugin oficial; Clarto solo podrá **mostrar el estado** que ese plugin exponga (§9: script de lectura pendiente).
8. **Huecos críticos de Clarto** (mayor a menor): G1 catálogo de plan contable · G2 ingesta máquina-a-máquina (`extracciones.documento_id` es NOT NULL y exige revisión humana) · G3 vínculo de identidad empresa↔instancia (`sso_externo_id` no existe) · G4 emitidas con rectificativa/país/vencimiento/cobros (`fact_facturas` no los tiene) · G5 IGIC/IPSI/exenciones/operación · G6 pestañas de ficha (no hay pestaña de facturación).
9. **Huecos críticos de Facturalo**: F1 `facturascli` sin marca de última modificación (no hay forma barata de detectar cambios de cobro/estado: hace falta `last_update` o outbox en el plugin propio) · F2 secuencias con `usarhuecos=true` · F3 sin bloqueo por trimestre · F4 sin idempotencia nativa (la tiene `TelecoBridge` solo para crear) · F5 sin pestañas/informes visibles en menú `Informes`.
10. **Plan de trabajo propuesto**: 28 tickets (§7), ≈ 62 días-persona (±40 %), repartidos Clarto 36 d / Facturalo 17 d / ambos 9 d. Ruta mínima para «ver las facturas de Facturalo en la ficha de Clarto» (T-01..T-08, T-14) ≈ 20 días.

---

## 1. Inventario de Facturalo (resumen por módulo)

Tablas: `Core/Table/*.xml` (83). Campos completos en el inventario de trabajo (volcado del XML); aquí se citan los que intervienen en el mapa.

### 1.1 Menú (PROBADO, `getPageData`)
Visible en Core solo `List*` más `About`, `AdminPlugins`, `Updater`, `ConfigEmail`, `EditSettings`. Las `Edit*` están ocultas (`EditController.php:50`). `Dashboard`/`MegaSearch` tienen `showonmenu=false`: **el menú Informes no muestra ninguna página** (informes = plugins).

| Menú | Páginas visibles |
|---|---|
| Administrador | Acerca de, Plugins, Email, Panel de control (EditSettings), Agentes, Biblioteca (adjuntos), Empresas, Registros (log), Países, Usuarios, Actualizador |
| Contabilidad | Asientos, Cuentas, Ejercicios, Formas de pago, Impuestos, Series |
| Compras | Proveedores, Presupuestos, Pedidos, Albaranes, Facturas |
| Ventas | Clientes, Presupuestos, Pedidos, Albaranes, Facturas, Tarifas |
| Almacén | Productos, Almacenes, Familias, Fabricantes, Atributos, Agencias de transporte |
| Informes | (ninguna) |

Páginas ocultas útiles: EditCuentaEspecial, EditSubcuenta, EditDiario, EditConceptoPartida, EditRetencion, EditEjercicio (importa plan, regulariza, cierra), EditSecuenciaDocumento, EditEstadoDocumento, EditDivisa, EditApiKey, EditRole, EditReciboCliente/Proveedor, EditCuentaBanco.

### 1.2 API REST `/api/3` (PROBADO, `ApiController.php`, `Kernel.php:512-556`)
Auth: cabecera `Token` (o `X-Auth-Token`), permisos por recurso `api_access(allowget/post/put/delete)`; 5 incidentes/600 s bloquean IP. Lista: `limit` (50), `offset`, `filter[campo]=` con sufijos `_gt/_gte/_lt/_lte/_neq/_like/_null/_notnull`, `sort[campo]=`, cabecera `X-Total-Count`. Recursos relevantes: `ejercicios, cuentas, subcuentas, cuentaespeciales, impuestos, impuestozonas, retenciones, series, secuenciadocumentos, formapagos, divisas, empresas, clientes, proveedores, contactos, facturaclientes, lineafacturaclientes, facturaproveedores, lineafacturaproveedores, reciboclientes, recibosproveedores, pagoclientes, asientos, partidas, productos, variantes, settings, estadodocumentos, cuentabancos…`. Rutas propias: `crearFacturaCliente`, `crearFacturaRectificativaCliente` (`idfactura`, `fecha`, `motivorect`, `refund_<idlinea>`), `editarFacturaCliente/<id>`, `exportarFacturaCliente/<id>` (PDF), `pagarFacturaCliente/<id>`, `attachedfiles`, `telecoFacturaCliente` (plugin, con `Idempotency-Key`).
**Limitaciones (PROBADO):** el cuerpo de POST/PUT solo se lee como form-urlencoded (los arrays, como `lineas`, van como string JSON); sin idempotencia nativa (409 si la PK existe); sin `last_update` en facturas.

### 1.3 Plugins propios
- `Facturalo` v0.1: marca (CSS, PDF, vistas), panel cerrado opcional (`FACTURALO_PANEL_CERRADO=1`). **No** añade tablas, series, impuestos, plan ni ajustes.
- `TelecoBridge` v0.1: tabla `teleco_idempotencia(clave varchar(80) PK, hash varchar(64), estado 'pending'|'done', http, respuesta, creado)` y `POST /api/3/telecoFacturaCliente` (`Idempotency-Key` 8–80 `[A-Za-z0-9_.:-]`).

---

## 2. Inventario de Clarto (resumen)

Tenant = `clients.id` (uuid) = `client_id` en toda tabla, RLS forzada `aislamiento_portal` + aislamiento por despacho. Identificadores humanos: `clients.nif` (único, «CIF» en UI), `clients.codigo_contable` (≤60; para A3 1..99999). **No existen** `sso_externo_id`, país ni catálogo de plan contable.
Contabilidad: `contabilidad_empresa` (longitud subcuenta 9–11, inmutable tras primera operación), `parametros_contables` (general `client_id NULL`=10 + overrides por ejercicio), `perfil_contable` (plan_contable `pgc_general|pgc_pymes|pgc_pymes_micro`), `terceros` (cliente/proveedor/acreedor, subcuenta `^[0-9]{9,11}$`, CHECK 430/400/410, verificación), `subcuentas_usadas`, `extracciones` (registro contable de cada factura recibida/emitida; importes en **euros** jsonb), `clasificaciones_contables`, `asientos`/`asiento_lineas` (céntimos bigint, inmutables, cuadre diferido), `trimestres_contables`, `numeracion_asientos`, `exportaciones*` (CSV, A3 SUENLACE, SIGA98), `impuestos_cliente`/`impuestos_periodo` (modelos: el resultado lo introduce el profesional, no se calcula).
Facturación emitida: `fact_clientes`, `fact_catalogo`, `fact_facturas`, `fact_contadores`, `fact_certificados`, `fact_registros_verifactu` (migraciones 135–137; céntimos, `iva_bp` 0/400/1000/2100, `irpf_bp`).
Ficha del cliente hoy (`admin/admin.js:315`): pestañas `cuenta, mercantil, actividad, impuestos, notas, contable, equipo, planes`. **No hay pestaña de facturación**; vive en `/entradas/facturacion`.
Permisos: `facturacion.ver|gestionar|certificados`, `contabilidad.preparar|reabrir|configurar`, `clientes.*`, `impuestos.*`; el PROFESIONAL no tiene `facturacion.*` y solo ve empresas asignadas.

---

## 3. Matriz por módulo (Facturalo ↔ Clarto)

Leyenda dirección: **F→C** Facturalo a Clarto · **C→F** Clarto a Facturalo · **↔** bidireccional con maestro indicado · **L** solo lectura en Clarto (consulta a la API de FS) · **—** sin correspondencia.
Conversión importes: FS `double` euros (2 decimales, `Tools::round` = `round(x, decimals)`), Clarto céntimos `bigint`: `cent = round_half_away(x·100)`; el decimal de FS debe fijarse a **2** (`settings default.decimals`).

### 3.1 Empresa / cliente de la gestoría

| Facturalo (tabla.campo, tipo) | Clarto (tabla.campo, tipo) | Regla | Dir. | Clave |
|---|---|---|---|---|
| `empresas.idempresa` serial | `clients.id` uuid | Una empresa FS por cliente; vínculo en nueva `integracion_empresas(client_id, instancia, idempresa, fs_url, api_key_ref)` (G3) | C→F al alta | `instancia+idempresa` |
| `empresas.cifnif` varchar(30) | `clients.nif` text, UNIQUE `upper(nif)` | Normalizar `upper`, sin espacios/puntos/guiones; validar con `nifValido()` | C→F al alta; cambio ↔ con alerta | nif |
| `empresas.nombre` varchar(100) / `nombrecorto` varchar(32) | `clients.nombre` text | `nombre` = razón social; `nombrecorto` = truncar a 32 | C→F | |
| `empresas.tipoidfiscal` varchar(25) (CIF/NIF/DNI/NIE/VAT/Pasaporte) | derivado de `clients.forma` y del NIF | sociedad/entidad → CIF; autónomo/particular → NIF/NIE | C→F | |
| `empresas.personafisica` bool | `clients.forma` (sociedad/autonomo/particular/entidad) | `personafisica = forma ∈ {autonomo, particular}` | C→F | |
| `empresas.regimeniva` varchar(50) (General, Simplificado, Agrario, Recargo, Exento, Caja, Oro, …) | `situacion_fiscal` (régimen IVA) + `contabilidad_empresa.recargo_equivalencia` | Mapa 1:1 con catálogo de Clarto (**G5**: Clarto no distingue Caja/Agrario/OSS) | C→F | |
| `empresas.direccion/ciudad/provincia/codpostal` | `clients.direccion/municipio/provincia/cp` (`cp ^[0-9]{5}$`) | Copia; FS no valida CP | C→F | |
| `empresas.codpais` varchar(20) | **no existe** (país en prefijo del NIF) | **G5**: columna `pais` ISO-2 en `clients` | C→F | |
| `empresas.email/telefono1/web` | `clients.email/telefono/web` | Copia | C→F | |
| `empresas.fechaalta`, `idlogo` | `clients.created_at` / — | Sin correspondencia; logo solo FS | — | |
| `empresas.administrador` | `relaciones_persona` | Informativo | C→F | |

### 3.2 Contabilidad: ejercicio, plan, subcuentas, cuentas especiales

| Facturalo | Clarto | Regla / diferencia | Dir. | Clave |
|---|---|---|---|---|
| `ejercicios.codejercicio` varchar(4) `^[A-Z0-9_+.-]{1,4}$` | `asientos.ejercicio` smallint 2000–2100 (año natural) | Usar `codejercicio` = año (`2026`), que es lo que crea FS por fecha (`Ejercicio::createNew`) | C→F | año |
| `ejercicios.fechainicio/fechafin` date | derivado (01-01/31-12) | Año natural; FS admite ejercicios no naturales: **prohibirlos** en Facturalo | C→F | |
| `ejercicios.estado` ABIERTO/CERRADO | `trimestres_contables.estado` abierto/cerrado por (ejercicio, trimestre) | Granularidad distinta: FS solo anual. **Gap F3**: bloqueo trimestral en FS por plugin; cierre anual FS = 4 trimestres cerrados en Clarto | C→F | |
| `ejercicios.longsubcuenta` int (4–15, def. 10) | `contabilidad_empresa.longitud_subcuenta` smallint 9–11 (def. 10) + `parametros_contables` | **Igualar**: valor de Clarto → FS antes de importar el plan (FS rechaza cambiarlo con subcuentas; Clarto lo fija inmutable en la 1.ª operación). Rango efectivo permitido: 9–11 | C→F | |
| `cuentas.codcuenta` varchar(10) UK(codcuenta,codejercicio); `descripcion` varchar(255); `codcuentaesp` varchar(6); `parent_codcuenta` | **no existe tabla** (regex 3–11 en `asiento_lineas.cuenta`) | **G1**: crear `plan_cuentas(variante, codigo, descripcion, cuenta_especial, padre)` | C→F (tras sembrar desde FS) | `(variante,codigo)` |
| `subcuentas.codsubcuenta` varchar(15) UK(codsubcuenta,codejercicio); `descripcion`; `idcuenta` | `terceros.subcuenta` (solo terceros) y `asiento_lineas.subcuenta` `^[0-9]{9,11}$` empieza por `cuenta` | FS tiene subcuentas del plan (745) + de terceros; Clarto solo terceros + expansión `cuenta.padEnd(l,'0')`. **G1** también para subcuentas fijas del plan | C→F plan; F→C terceros | `codsubcuenta` |
| `cuentasesp.codcuentaesp` varchar(6) PK (38) | constantes `GRUPOS`/`CUENTAS` en código | Tabla §A.3; Clarto debe declararlas como datos (G1) | C→F | |
| `diarios` (1 Principal, 2 Facturas, 3 Cartera pagos, 4 Cartera cobros) | — | Clarto no usa diarios (un solo libro); informativo | — | |
| `conceptos_partidas` | — | Sin correspondencia | — | |
| `regularizacionimpuestos`, apertura/cierre/regularización (`asientos.operacion` A/C/R) | — | Clarto no genera cierres; **no sincronizar** | — | |

### 3.3 Impuestos, retenciones, formas de pago, divisas

| Facturalo | Clarto | Regla / diferencia | Dir. |
|---|---|---|---|
| `impuestos.codimpuesto` varchar(10) PK; `iva` double; `recargo` double; `operacion` varchar(20) ES_01/02/03/04/99; `tipo` 1=%, 2=fijo; `activo` | contabilidad `tipo_iva` % ∈ {0,2,4,5,7.5,10,21}; `fact_catalogo.iva_bp`/`fact_facturas.lineas[].iva_bp` ∈ {0,400,1000,2100} | `IVA0/4/10/21` ↔ 0/400/1000/2100 (`bp = iva·100`). **G5**: IGIC/IPSI y tipos 2/5/7.5 solo en un lado → rechazar/avisar en ingesta, no inventar | C→F catálogo (activar solo los 4 IVA) |
| `impuestos.recargo` 0/0.5/1.4/5.2 | recargo por tipo (21→5,2/1,75; 10→1,4; 4→0,5; 0→0) `contabilidad.js:41` | Igual salvo el 1,75 % (Clarto lo admite para 21 % antiguo) | — |
| `impuestos.codsubcuentarep/repintra/repre/sop/sopintra/sopre` varchar(15) | `CUENTAS` 472/477 expandidas (`4720000000`, `4770000000`) | **Diferencia D-1**: FS por tipo (`4770000021`); Clarto una sola de grupo. §A.4 | C→F |
| `impuestoszonas` (excepciones por país/`excepcioniva`) | — | **G5** | — |
| `retenciones.codretencion` varchar(10); `porcentaje` double; `codsubcuentaret`/`codsubcuentaacr` | `fact_facturas.irpf_bp` int 0–2100; `extracciones.campos.retencion_tipo` % | `IRPF2/7/15/19/21` ↔ 200/700/1500/1900/2100; Clarto admite 0–21 libre; FS solo las dadas de alta | C→F catálogo |
| `formaspago.codpago` varchar(10) (CONT, TRANS, TARJETA, PAYPAL), `plazovencimiento`, `tipovencimiento`, `domiciliado`, `codcuentabanco` | `fact_facturas`: sin forma de pago (G4) | Solo lectura (ficha) | F→C |
| `divisas.coddivisa` varchar(3), `tasaconv` | — (solo EUR) | Facturalo solo EUR (otra divisa → rechazar en ingesta) | — |
| `paises.codpais` varchar(20) PK, `codiso` varchar(2) | — | G5 | — |
| `idsfiscales` (CIF, DNI, NIE, NIF, Pasaporte, VAT) | NIF con prefijo ISO-2 | `tipoidfiscal` + prefijo VAT → NIF canónico de Clarto | F→C |

### 3.4 Terceros: clientes y proveedores

| Facturalo | Clarto | Regla | Dir. | Clave |
|---|---|---|---|---|
| `clientes.codcliente` varchar(10) PK `^[A-Z0-9_+.-]{1,10}$` | `fact_clientes.fs_codcliente` text UK `^[A-Za-z0-9]{1,10}$` (def. `TVC`+6 díg.) | Regex de Clarto más estricta (sin `_+.-`): en Facturalo restringir a alfanumérico | F→C | `fs:<inst>:cli:<cod>` |
| `clientes.cifnif` varchar(30) NN; `tipoidfiscal` | `fact_clientes.nif` text (5–20) y `terceros.nif` text (rol cliente) | Normalizar y validar; duplicado de NIF por empresa → conflicto visible | F→C | `(client_id, upper(nif))` |
| `clientes.nombre` varchar(100) / `razonsocial` varchar(100) | `fact_clientes.nombre` ≤200 / `terceros.nombre` | `razonsocial` si existe, si no `nombre` | F→C | |
| `clientes.email/telefono1` | `fact_clientes.email/telefono` | Copia | F→C | |
| `clientes.codsubcuenta` varchar(15) | `terceros.subcuenta` `^[0-9]{9,11}$` CHECK `left(…,3)=430` | **Regla**: la subcuenta nace en FS; Clarto la **adopta** (`terceros.verificacion='pendiente'` hasta revisión del gestor). Colisión con `subcuentas_usadas` → conflicto a resolver, nunca sobrescribir | F→C | `(client_id, subcuenta)` |
| `clientes.regimeniva`, `operacion`, `excepcioniva` | — | G5 (informativo en ficha) | F→C | |
| `clientes.debaja`/`fechabaja` | `terceros.activo` | `activo = !debaja` | F→C | |
| `clientes.codretencion`, `codserie`, `codpago`, `riesgomax…` | — | Solo FS | — | |
| `contactos.*` (direcciones, `cifnif`, `codpais`) | — (sin dirección en `fact_clientes`) | G4: dirección y país del destinatario son datos obligatorios de factura | F→C | |
| `cuentasbcocli.iban/mandato/fmandato` (SEPA) | `banco_*`/`cuentas_bancarias` (mov. del cliente de la gestoría, no de sus clientes finales) | Sin correspondencia; dato personal: **no sincronizar** | — | |
| `proveedores.codproveedor`, `cifnif`, `nombre`, `acreedor`, `codsubcuenta` | `terceros` rol `proveedor`/`acreedor` | `acreedor=true` → rol acreedor (410), si no 400. Mismo criterio de adopción de subcuenta | F→C | |

### 3.5 Facturas emitidas (ventas)

| Facturalo (`facturascli`) | Clarto | Regla de transformación | Dir. |
|---|---|---|---|
| `idfactura` serial PK | `extracciones`/ingesta: id externo | clave `fs:<inst>:<idfactura>` | F→C |
| `codigo` varchar(20) NN (`F2026A1` por defecto) | `fact_facturas.numero_fiscal` text ≤60; `extracciones.campos.numero` ≤60 | Copiar tal cual; §A.5 fija patrón `A2026-000001` | F→C |
| `codserie` varchar(4); `codejercicio`; `numero` varchar(12) | `serie` `^[A-Z0-9]{1,5}$`; `ejercicio` int; `numero` int ≥1 | `numero` texto→int; serie ≤4 cabe en 5 | F→C |
| `fecha` date NN; `fechadevengo` date; `hora` | `extracciones.campos.fecha`; periodo `AAAA-Tn` | `periodo` = trimestre de `fechadevengo ?? fecha` (**NO VERIFICADO**: criterio fiscal del gestor) | F→C |
| `cifnif` varchar(30) NN, `nombrecliente` varchar(100) NN | `tercero_nif`, `tercero_nombre` ≤200 | Normalizar NIF | F→C |
| `neto`, `totaliva`, `totalrecargo`, `totalirpf`, `totalsuplidos`, `total` (double) | `base_cent`, `iva_cent`, `irpf_cent`, `total_cent` (bigint); `campos.lineas[].base/cuota/recargo`, `retencion`, `total` (euros) | `cent()`; **comprobar** total FS = neto + suplidos + IVA + recargo − IRPF (`Calculator.php:272`); descuadre > 0,01 → marcar `discrepancia`, no aprobar | F→C |
| `lineasfacturascli`: `pvpunitario`, `cantidad` (double), `dtopor`, `dtopor2`, `iva`, `recargo`, `irpf`, `suplido`, `codimpuesto`, `excepcioniva` | `fact_facturas.lineas[]` {referencia, descripcion, tipo, cantidad **entera**, precio_cent, iva_bp}; `extracciones.campos.lineas[]` {base, tipo_iva, cuota, recargo_*} | Para contabilidad solo hacen falta **agrupaciones por tipo** (base, tipo, cuota, recargo): se agregan por `iva` sin copiar descuentos. Cantidad decimal/descuentos no caben en `fact_facturas.lineas` → ingerir solo como registro contable (no re-emitir) | F→C |
| `irpf` / `retenciones` | `retencion_tipo` %, `retencion` € | `retencion_tipo = totalirpf/neto` redondeado a 2 decimales y debe ∈ retenciones dadas de alta | F→C |
| `operacion` varchar(30) (exportacion, intracomunitaria, inv-sujeto-pasivo, …) y `excepcioniva` | — | **G5**: Clarto no clasifica; la ingesta marca «requiere revisión» si ≠ vacío | F→C |
| `idfacturarect`, `codigorect`, `motivorect` varchar(20) (ES_80_1_2_6, ES_80_3, ES_80_4, ES_OTHER) | `campos.rectificativa` bool, `rectificacion_tipo` R1–R5, `rectificacion_modalidad` S/I, `rectificadas[{numero,fecha}]` | FS rectifica con líneas negativas = modalidad **I** (diferencias, negativo) : `ES_80_1_2_6→R1`, `ES_80_3→R2`, `ES_80_4→R3`, `ES_OTHER→R4` (serie `S`/simplificada→R5). Signo: se guarda negativo en ambos; A3 lo invierte al exportar. FS no tiene modalidad **S** (Clarto la bloquea: coherente) | F→C |
| `idestado` → `estados_documentos` (10 Boceto, 11 Emitida) | `estado` borrador/enviando/emitida/error | **Solo se ingiere `idestado=11`**; Boceto se ignora. FS no tiene «anulada» en factura (se anula con rectificativa) | F→C |
| `pagada`, `vencida` bool | `fs_pagada` bool (`fact_facturas`) | F→C; cambios requieren detección (**F1**) | F→C |
| `idasiento` int | `asientos.id` uuid | **Sin vínculo**: Clarto forma su asiento; FS conserva el suyo (§5, D-B) | — |
| `codpago`, `idcontactofact`, `direccion/ciudad/codpostal/codpais`, `numero2`, `observaciones` | no existen en `fact_facturas` | **G4**: añadir (forma de pago, vencimiento, dirección, país, referencia) | F→C |

### 3.6 Facturas recibidas (compras)

| Facturalo (`facturasprov`) | Clarto | Regla | Dir. |
|---|---|---|---|
| `codigo`, `numproveedor` varchar(50) | `campos.numero` ≤60 | **Usar `numproveedor`** (número del proveedor) y no `codigo` (interno de FS) | F→C |
| `cifnif`, `nombre`, `codproveedor` | `tercero_nif`, `tercero_nombre`, `terceros` | Idem emitidas | F→C |
| `fecha`, `fechadevengo`, totales, `irpf`, `operacion`, `idfacturarect`, `motivorect` | `campos.*` | Idem emitidas (`tipo='recibida'`; retención → Haber 4751) | F→C |
| `idestado` 21 Boceto / 22 Recibida | — | Solo 22 | F→C |
| adjunto (PDF original) en `attached_files`/`attached_files_relations` | `documents` (`documento_id` NOT NULL en `extracciones`) | Resuelve G2 parcialmente: la factura de compra puede traer su PDF; la emitida se genera con `exportarFacturaCliente` | F→C |

### 3.7 Cobros y pagos

| Facturalo | Clarto | Regla | Dir. |
|---|---|---|---|
| `recibospagoscli`: `idrecibo`, `idfactura`, `importe`, `vencimiento`, `fechapago`, `pagado`, `vencido`, `codpago`, `gastos`, `liquidado` | — (solo `fs_pagada`) | **G4**: tabla `fs_cobros` (espejo de solo lectura): importe en céntimos, vencimiento, fecha pago, estado | F→C (L) |
| `pagoscli`: `idpago`, `idrecibo`, `importe`, `fecha`, `codpago`, `idasiento` | — | Idem | F→C (L) |
| `recibospagosprov`, `pagosprov` | — | Idem para gastos | F→C (L) |
| `cuentasbanco` (iban, sufijosepa) | `cuentas_bancarias` (cliente) | Informativo; **no** copiar IBAN | — |

### 3.8 Series y numeración

| Facturalo | Clarto | Regla | Dir. |
|---|---|---|---|
| `series.codserie` varchar(4) PK (A, R tipo 'R', S tipo 'S'); `siniva`; `iddiario` | `fact_facturas.serie` `^[A-Z0-9]{1,5}$`; `fact_contadores` | Series estándar `A`,`R`,`S` en toda empresa | C→F |
| `secuencias_documentos`: `patron` (def. `{EJE}{SERIE}{0NUM}`), `longnumero` (def. 6), `inicio`, `numero` (siguiente), `usarhuecos` (true en FacturaCliente al autocrearse), `mantenerfecha` | `fact_contadores(client_id, serie, ejercicio, ultimo)` correlativo sin huecos | Configurar `patron={SERIE}{EJE}-{0NUM}`, `longnumero=6`, `usarhuecos=false` → `A2026-000001` (igual que `emision.js`) | C→F |
| UK `(codejercicio,codserie,idempresa,numero)` (sin bloqueo de secuencia) | `SELECT … FOR UPDATE` + trigger solo +1 | Garantía más débil en FS; vigilar duplicados con la UK y reintento | — |

### 3.9 Asientos y partidas

| Facturalo (`asientos`, `partidas`) | Clarto (`asientos`, `asiento_lineas`) | Regla | Dir. |
|---|---|---|---|
| `asientos.numero` int NN, `fecha`, `concepto` varchar(255), `documento` varchar(50), `iddiario`, `operacion` A/C/R | `numero` int 1..99 999 999 por (cliente, ejercicio), `fecha_asiento`, `concepto` ≤200, `origen` factura/anulacion, `estado` | **No importar** asientos de FS como asientos de Clarto (D-B). Se comparan por totales de cuenta | L |
| `partidas.codsubcuenta` varchar(15), `debe`, `haber` (double), `idasiento`, `baseimponible`, `iva`, `recargo`, `cifnif`, `factura` | `asiento_lineas.cuenta`/`subcuenta`, `debe_cent`/`haber_cent` bigint, `orden` 1–50 | Informe de conciliación: sumas Debe/Haber por cuenta y trimestre FS vs Clarto | L |
| Cuadre `asientos.importe`; FS permite descuadre (aviso) | `asientos_cuadra` diferido, exacto al céntimo | Clarto es más estricto | — |
| Inmutabilidad: FS permite editar asiento si `editable` | Clarto inmutable, anulación por asiento inverso | — | — |

### 3.10 Productos y catálogo

| Facturalo | Clarto (`fact_catalogo`) | Regla | Dir. |
|---|---|---|---|
| `productos.referencia` varchar(30) UK | `referencia` `^[A-Za-z0-9._-]{1,30}$` | Misma longitud; FS admite más caracteres | ↔ (maestro: quien lo crea; hoy Clarto) |
| `descripcion` text | `descripcion` 1–200 | Truncar con aviso | |
| `precio` (variante `precio`), `codimpuesto` | `precio_cent`, `iva_bp` | `IVA21↔2100` | |
| `tipo`, familias, fabricantes, atributos, tarifas, stock, almacenes | `tipo` servicio/producto | Sin correspondencia (Clarto no tiene almacén); **no sincronizar** | — |

### 3.11 VERI\*FACTU
Core de FS no guarda huella/QR/estado AEAT (PROBADO). Ganchos: `facturascli.motivorect`, `operacion`, `excepcioniva`, `fechadevengo`, `Lib/QrImage.php`, hook PDF `qrImageHeader`.
| Dato | Facturalo | Clarto | Estado |
|---|---|---|---|
| Huella encadenada, XML, CSV, estado (pendiente/aceptado/aceptado_con_errores/rechazado/error), tipo alta/anulación | Plugin oficial (tablas propias; **no se lee su código**) | `fact_registros_verifactu(huella, huella_anterior, xml, estado, csv, respuesta_*, seq)` solo para facturas del **motor propio** | **NO VERIFICADO**: nombres de tablas/columnas y API del plugin → script §9 |
| Certificado | Plugin / panel de FS | `fact_certificados` cifrado por empresa | El certificado **no** viaja nunca; Clarto solo muestra «cargado/vigente/caduca» si el plugin lo expone |
| Modo prueba/real | Ajuste del plugin | `fact_facturas.modo` (CHECK admite `real` aunque la política es solo prueba) | PROD bloqueado (`PROD_HABILITADO=false`) |

### 3.12 Usuarios, roles, API, documentos
| Facturalo | Clarto | Regla |
|---|---|---|
| `users.nick` PK, `admin`, `enabled`, `codrole`, `idempresa` | `client_users` (admin/empleado), `staff_*` | Sin sincronización; acceso SSO por `sso_externo_id` (G3). FS no limita a qué empresa accede un usuario → un usuario por empresa/instancia |
| `roles`, `roles_access`, `pages` | `permisos` en `roles.js` | Sin correspondencia |
| `api_keys.apikey` varchar(99) + `api_access` | secreto cifrado en `integracion_empresas` | Una clave por tenant, solo `allowget` para el pull; `fullaccess=false` |
| `attached_files`, `attached_files_relations` | `documents` | PDF/adjuntos: F→C bajo demanda |
| `logs`, `emails_sent` | `audit`, `salidas_correo` | Sin sincronización |

---

## 4. Diferencias a corregir o vigilar (con decisión propuesta)

| # | Diferencia | Efecto | Propuesta |
|---|---|---|---|
| D-1 | IVA: FS subcuenta por tipo (`4770000021`, `4720000004`…); Clarto de grupo (`4770000000`) | Conciliación por subcuenta no cuadra | **Opción A (recomendada)**: configurar FS con una subcuenta de grupo (§A.4) → igual a Clarto. Opción B: Clarto adopta subcuentas por tipo (cambia `formarAsiento` y exportaciones). **NO VERIFICADO** si FS fusiona partidas con la misma subcuenta; probar en `teleco-test` |
| D-2 | Numeración `F2026A1` (FS) vs `A2026-000001` (Clarto/F0) | Dos números distintos para la misma factura | Patrón FS `{SERIE}{EJE}-{0NUM}`, `longnumero=6` (§A.5) |
| D-3 | `usarhuecos=true` por defecto en facturas de cliente: reutiliza huecos y copia la fecha del documento anterior | Incumple correlatividad y VERI\*FACTU | Secuencia creada con `usarhuecos=false`, `mantenerfecha=false` (FS lo permite; en ESP loguea error `use-holes-invoices-esp` pero **no** bloquea: ticket F-03 para blindarlo) |
| D-4 | Subcuenta de cliente: FS `430`+ceros+`codcliente` (evita las usadas); Clarto «primer número libre» | Colisión de numeración | Gana FS (nace allí); Clarto adopta (T-09) |
| D-5 | Cierre: FS anual; Clarto trimestral | Alta en trimestre cerrado en Clarto | Ingesta rechaza (`asientos_numerar` exige trimestre abierto) → cola «en espera»; plugin FS bloquea fecha en trimestre cerrado (T-18) |
| D-6 | Importes: `double` vs `bigint`; redondeo | Descuadres ±0,01 | `decimals=2`; `cent()` half away from zero; **NO VERIFICADO** `taxcalculationmethod` `classic` frente a `mulBp` de Clarto (el F0 dejó el comparador `facturalo-comparar-fs.mjs` sin ejecutar contra FS real) |
| D-7 | IGIC/IPSI/exenciones/intracomunitarias/ISP solo en FS | Facturas que Clarto no clasifica | G5: catálogo `operacion_iva` en Clarto; hasta entonces «requiere revisión» |
| D-8 | `fact_facturas.lineas` cantidad entera sin descuento; FS cantidad decimal y dos descuentos | No re-emitir desde Clarto | Ingesta como registro contable agregado; las líneas completas se consultan por API/PDF |
| D-9 | Estados | Solo `Emitida` es contable | Filtro `idestado=11` / `22` |
| D-10 | Regex de códigos: FS `[A-Z0-9_+.-]{1,10}`; Clarto alfanumérico | Rechazo de `codcliente` con `_` `.` | Restringir en Facturalo (§A.6) |
| D-11 | NIF: FS `varchar(30)` + `tipoidfiscal`; Clarto `nifValido()` + prefijo ISO-2 | País implícito | Normalizar al ingerir; columna `pais` (G5) |
| D-12 | `clients.codigo_contable` (A3, 1..99 999) sin equivalente en FS | — | Solo Clarto; mostrar en ficha |
| D-13 | Decisión D-2 de Clarto («cómo entran las emitidas externas a la contabilidad») sigue abierta; `extracciones.documento_id` NOT NULL + `revisada_por` obligatorio | Sin ruta automática | Propuesta §5 (D-C) |

---

## 5. Decisiones propuestas para el OWNER

- **D-A Plan contable**: maestro = Clarto/gestoría. Mientras no exista catálogo (G1), se siembra desde `Core/Data/Codpais/ESP/defaultPlan.csv` y se versiona en Clarto (variante `pgc_general`; `pymes` y `pymes_micro` se derivan y solo difieren en cuentas que el perfil permite). Facturalo recibe el plan **solo** al alta del ejercicio (ejercicio vacío) y por **añadidos** después (cuenta/subcuenta nueva), nunca borrados ni renombrados sin aviso.
- **D-B Asientos**: dos libros con roles distintos: el **asiento de Clarto** es el de la gestoría (A3/SIGA98 salen de él); el de FS es operativo del cliente. Se concilian por cuenta y trimestre; no se importan los de FS.
- **D-C Ingesta de emitidas**: registro estructurado `origen='integracion'` creado por la ingesta con PDF adjunto (`exportarFacturaCliente`) como `documents` sintético; entra como `revisada` **solo** si pasa validaciones automáticas (NIF, cuadre al céntimo, periodo abierto, tercero verificado, sin duplicado, sin operación especial). Si falla alguna → bandeja de revisión humana existente (no se elimina la revisión humana: se reduce a excepciones). Requiere cambio de CHECK `revisada_por` (ticket T-05) y aprobación del OWNER.
- **D-D Dirección**: lo transaccional nace en Facturalo y no se edita desde Clarto; lo estructural lo gobierna Clarto.
- **D-E Cuenta de gestoría**: acceso del gestor a datos de Facturalo siempre por la API de FS con clave `allowget` por tenant (nunca SQL directo, nunca el certificado).

---

## 6. «Accesible desde la ficha del gestor/cliente» — pestañas propuestas

Hoy la ficha tiene `cuenta, mercantil, actividad, impuestos, notas, contable, equipo, planes`. Añadir (permiso `facturacion.ver` + nuevo `facturalo.ver`; PROFESIONAL solo empresas asignadas, 404 fuera):

| Pestaña (nueva) | Contenido | Fuente hoy en Facturalo | ¿Ya disponible? |
|---|---|---|---|
| **Facturalo ▸ Resumen** | Estado de la instancia/empresa, última sincronización, errores, cifras del trimestre (ventas, IVA repercutido, gastos, IVA soportado), semáforo de conciliación | `facturaclientes`, `facturaproveedores` agregados | Sí (cálculo en Clarto con T-06) |
| **Facturas emitidas** | Lista (nº, fecha, cliente, base, IVA, IRPF, total, estado, cobrada, VERI\*FACTU) + PDF | `facturaclientes`, `lineafacturaclientes`, `exportarFacturaCliente` | Sí |
| **Facturas recibidas (gastos)** | Lista y adjunto; botón «enviar a revisión contable» | `facturaproveedores`, `attachedfiles` | Sí |
| **Clientes y proveedores** | Terceros con NIF, subcuenta, verificación, país | `clientes`, `proveedores`, `contactos` | Sí |
| **Plan contable** | Estado de sincronización (longitud de subcuenta, nº de cuentas, diferencias con el maestro de Clarto), cuentas especiales | `ejercicios`, `cuentas`, `subcuentas`, `cuentaespeciales` | Sí (solo lectura + «resincronizar») |
| **Impuestos y series** | Catálogo IVA/IRPF, series y patrones, diferencias con el estándar | `impuestos`, `retenciones`, `series`, `secuenciadocumentos` | Sí |
| **Cobros** | Recibos pendientes/vencidos/cobrados, pagos | `reciboclientes`, `pagoclientes`, `recibosproveedores` | Sí (T-12) |
| **VERI\*FACTU** | Estado por factura, entorno, certificado (solo «vigente/caduca»), cola | Plugin oficial (interfaz/API) | **NO VERIFICADO** (§9) |
| **Conciliación y modelos** | Descuadres FS↔Clarto por cuenta/trimestre; base para 303/130/111/115/347 como **ayuda** | `asientos`+`partidas`, facturas | Sí (cálculo en Clarto) |
| **Informes** | IVA trimestral, listado de facturas, exportación CSV/XLSX/A3 | agregados | Sí |

---

## 7. Huecos priorizados y tickets propuestos

Tamaño: S ≤ 1 d · M 2–3 d · L 4–6 d (persona-días, ±40 %). Lado: **C** Clarto · **F** Facturalo · **CF** ambos.

### 7.1 Huecos
| ID | Hueco | Lado | Prioridad |
|---|---|---|---|
| G1 | Clarto sin catálogo de plan contable/cuentas especiales (código fijo) | C | Crítica |
| G2 | Ingesta máquina-a-máquina inexistente (`extracciones.documento_id` NOT NULL, revisión humana obligatoria, `verificarPeticion` HMAC sin ruta) | C | Crítica |
| G3 | Vínculo empresa↔instancia (`sso_externo_id`/`integracion_empresas`) | C | Crítica |
| G4 | `fact_*` sin rectificativas, país/dirección, vencimiento, forma de pago, cobros, vínculo a asiento | C | Alta |
| G5 | Sin IGIC/IPSI, exenciones, operación (intracomunitaria/ISP/exportación), país, régimen IVA fino | C | Alta |
| G6 | Ficha sin pestañas de Facturalo | C | Alta |
| F1 | `facturascli` sin `last_update`: no se detectan cambios de cobro/estado sin releer | F | Alta |
| F2 | `usarhuecos=true`; secuencia sin bloqueo | F | Alta |
| F3 | Sin bloqueo por trimestre/cierre de Clarto | F | Media |
| F4 | Sin ajuste automático al alta (plan, series, impuestos, decimales) | F | Crítica |
| F5 | Sin informes visibles (menú Informes vacío) | F | Media |
| F6 | VERI\*FACTU: datos solo por interfaz/API del plugin; mapeo pendiente | CF | Alta (NO VERIFICADO) |

### 7.2 Tickets (código `MAPA-nn`)
| ID | Lado | Ticket | Criterio de cierre | Tam. |
|---|---|---|---|---|
| 01 | C | Tabla `integracion_empresas` (client_id, instancia, idempresa, URL, clave cifrada por tenant, estado) + `sso_externo_id` en `client_users` | RLS forzada; prueba A/B | M |
| 02 | C | Registro de aplicaciones y secretos HMAC; `verificarPeticion` cableada; anti-replay | Ventana 300 s; replay → 409; rotación | M |
| 03 | C | Endpoint `POST /api/integraciones/facturalo/eventos` con clave externa UNIQUE `(client_id, origen, id_externo)` | Idempotente; segunda entrega = misma respuesta | M |
| 04 | CF | Pull desde FS con `FS_API_KEY` por tenant: facturas 11/22, clientes, proveedores, recibos; paginación `offset/limit`; cursor `idfactura_gt` + relectura de `pagada` | Importa 1 000 facturas sin duplicar | L |
| 05 | C | Registro contable `origen='integracion'` (D-C): documento sintético con PDF, reglas de aprobación automática, CHECK `revisada_por` ajustado | Auditoría; casos excepcionales a bandeja | L |
| 06 | C | Agregados y conciliación FS↔Clarto por cuenta y trimestre (solo lectura) | Informe con diferencias por cuenta | M |
| 07 | C | Pestañas Facturalo en la ficha: Resumen, Emitidas, Recibidas, Terceros (solo lectura) | Revisión en navegador; 404 fuera de asignación | L |
| 08 | C | Permiso `facturalo.ver` y matriz en `roles.js` | Pruebas negativas | S |
| 09 | C | Adopción de subcuenta de FS en `terceros` con conflicto explícito | Colisión → incidencia, no sobrescribe | M |
| 10 | C | **G1** Tabla `plan_cuentas` (variante, código, descripción, cuenta especial, padre) sembrada con el CSV ESP; `GRUPOS`/`CUENTAS` leídos de datos | `cuenta` de asientos validada contra catálogo | L |
| 11 | C | Generador del CSV de plan para FS (`cuenta;descripcion;cuentaesp`, longitud de subcuenta de la empresa) | Importa en FS vacío sin ajustes | M |
| 12 | C | Espejo de cobros `fs_cobros` y pestaña Cobros | Cuadra con recibos de FS | M |
| 13 | C | **G4** Ampliar `fact_facturas`/registro: rectificativa (`rectifica_a`, tipo R1–R5), país, dirección, vencimiento, forma de pago | Migración con down; RLS | M |
| 14 | C | **G5** Catálogo `operacion_iva` y `pais` en `clients`/terceros; IGIC/IPSI como rechazo explícito | Ingesta clasifica o deriva a revisión | M |
| 15 | C | Pestaña Plan contable/Impuestos y series (diferencias con el maestro) | Muestra desviaciones | M |
| 16 | C | Pestaña VERI\*FACTU (estado leído del plugin) | Tras §9 | M |
| 17 | C | Pestaña Conciliación/Modelos (base 303/130/111/115/347 como ayuda; no presenta) | Cifras = facturas ingeridas | L |
| 18 | CF | Cierre trimestral: Clarto publica lista de periodos cerrados; plugin FS bloquea fechas en esos periodos | Alta en periodo cerrado → rechazo en FS | M |
| 19 | F | **F4** «Aprovisionamiento contable» al alta: ejercicio año natural, `longsubcuenta` de Clarto, importación del CSV, cuentas especiales, impuestos con subcuentas §A.4, retenciones, series A/R/S, secuencias §A.5, `decimals=2` | Script idempotente; informe de diferencias = 0 | L |
| 20 | F | **F1** `last_update` en `facturascli`/`recibospagoscli` (o outbox de cambios) en plugin propio + endpoint de cambios `GET /api/3/cambios?desde=` | Detecta pago y rectificativa sin releer | M |
| 21 | F | **F2** Blindar `usarhuecos=false` y patrón de serie (hook al crear secuencia) | Imposible crear secuencia de factura con huecos | S |
| 22 | F | Restricción de `codcliente`/`codproveedor` a alfanumérico ≤10 | Alta con `_` rechazada | S |
| 23 | F | Idempotencia también en `crearFacturaRectificativaCliente` y `pagarFacturaCliente` (extender `teleco_idempotencia`) | Reintento no duplica | M |
| 24 | F | Informes mínimos visibles (menú Informes): IVA trimestral, listado, ventas por cliente (con plugin propio, ver deuda) | Cuadra con facturas | M |
| 25 | CF | Reconciliación de redondeo: ejecutar `facturalo-comparar-fs.mjs` contra FS real y registrar 0 diferencias | Informe | S |
| 26 | CF | Mapeo de VERI\*FACTU por interfaz/API (resultado del script §9) | Documento de campos observados | M |
| 27 | C | Modelo de «cuenta gestoría» gratuita ya definida (D5): enlazar con alta de Facturalo (FAC-PL-36) | Código de invitación → alta con aprovisionamiento | S |
| 28 | C | Pruebas de contrato Facturalo↔Clarto (fixtures de facturas de FS: normal, IRPF, rectificativa, 0 %, recargo) | Verde en CI | M |
Total ≈ Clarto 52 d, Facturalo 15 d, ambos 11 d (≈ 78 d).
Ruta mínima (ver facturas en la ficha): 01, 02, 03, 04, 07, 08, 10, 19 ≈ 30 d.

---

## 8. Cruce con la deuda de funciones propias (`FACTURALO-DEUDA-PLUGINS.md`)

| Función de la deuda | ¿Depende de Clarto? | Dónde se cruza con este mapa |
|---|---|---|
| Cobro rápido y pagos múltiples (P1) | No (FS: `pagarFacturaCliente`, recibos). Clarto solo **lee** el estado | T-12 espejo de cobros; F1 `last_update`; no tocar facturas registradas en VERI\*FACTU |
| Recordatorio de impagos (P1) | No; usa el estado de recibos de FS. Clarto muestra «recordatorios enviados» | T-12 |
| Remesas SEPA (P1) | **Parcial**: mandatos/IBAN de clientes finales son dato personal de la empresa; Clarto **no** debe copiarlos. Dependencia con conciliación bancaria de Clarto (`banco_*`) para casar el cargo | §3.4 (no sincronizar IBAN); T-12 |
| Paquete trimestral para la gestoría (303, 111, 115, 130, 347, 349) (P1) | **Sí, depende de Clarto**: Clarto ya tiene `impuestos_periodo` (resultado lo introduce el profesional), exportación A3/SIGA98 y calendario. El paquete de Facturalo debe producir **las mismas cifras** que la conciliación de Clarto | T-06, T-17; criterio de periodo (§3.5) |
| Exportación masiva ZIP (P1) | Parcial: es la vía de entrega a la gestoría si Clarto no tira por API | T-04 (pull) la sustituye en el flujo normal |
| Presupuesto a factura y aceptación online (P1) | No (presupuestos fuera de Clarto) | — |
| Anticipos y cobros a cuenta (P1) | **Sí**: Clarto no modela anticipos (438/407) ni factura de anticipo; falta en G4/G5 | T-13, T-14 |
| Enlace de pago Redsys/Stripe (P1) | Parcial: la conciliación del cobro debe llegar a Clarto; gasto y contrato de pasarela por cliente | T-12 |
| Panel de cobros y previsión (P2) | No | pestaña Cobros (§6) |
| Importar factura de proveedor con IA/OCR (P2) | **Sí**: la extracción IA de Clarto está desactivada (`clasificador.js:21`) y exige revisión humana; el OCR de Facturalo debe alimentar `extracciones` | T-04/T-05; DPA y gasto por llamada |
| Criterio de caja (RECC) y exenciones (P2) | **Sí**: Clarto no distingue régimen de caja ni exenciones | G5, T-14 |
| Rectificativas guiadas / duplicar (P2) | Parcial: Clarto debe aceptar rectificativas emitidas | T-13 |
| Conciliación bancaria Norma 43 (P2) | **Sí**: Clarto ya tiene `banco_*` y conciliación; evitar duplicar | no desarrollar en Facturalo hasta cerrar la decisión de Clarto |
| Multidivisa (P2) | **Sí**: Clarto solo EUR | G5 (fuera de alcance) |
| Facturae/FACe (P2) | No | — |
| Portal de cliente final (P2) | No | — |
| Amortizaciones y asientos, CRM, intercambios (P3) | **Sí / terreno Clarto** | no duplicar |
| Informes y panel simple (P2) | Parcial: cifras deben coincidir con Clarto | T-24, T-06 |

**Marcadas «depende de Clarto»**: paquete trimestral, anticipos, OCR de proveedor, criterio de caja y exenciones, conciliación bancaria, multidivisa, amortizaciones/CRM, y la parte de conciliación de remesas SEPA y del enlace de pago.

---

## 9. Lo que no se ha podido ver (script de solo lectura)

Sin credenciales de `151.80.149.132`. Se deja `fs-mapa-lectura.sh` en la carpeta de notas compartida (solo `SELECT`; no imprime contraseñas, claves API ni datos personales de clientes; recorre cada instalación FS encontrada). Debe ejecutarlo el coordinador:

```
ssh ploi@151.80.149.132 'bash -s' < fs-mapa-lectura.sh > fs-mapa-salida.txt
```
Sacará de producción (`redtelecom`) y de `teleco-test`: versión y plugins activos; lista de tablas con filas (incluidas las tablas del plugin Verifactu, **solo nombres y columnas**, sin contenido); empresas; ejercicios y `longsubcuenta`; nº de cuentas/subcuentas por longitud; cuentas especiales asignadas; impuestos, retenciones, series, formas de pago, estados, secuencias; distribución de clientes/proveedores por longitud y régimen; facturas por serie/ejercicio/operación; rectificativas por signo; asientos y cuadre global; recibos; usuarios y claves API (sin secretos); y `settings` (claves, sin valores sensibles). Con ello se cierran: D-1 (cómo agrupa FS las partidas de IVA), D-3 (si producción ya tiene huecos), D-6 y los campos de VERI\*FACTU del plugin oficial (T-26).

---

## Anexo A. Configuración recomendada del plan contable en Facturalo (para igualar a Clarto)

### A.1 Ejercicio
- `codejercicio` = año natural (`2026`), `fechainicio` 01-01, `fechafin` 31-12, `estado` ABIERTO. Un ejercicio por año; no crear ejercicios no naturales.
- `longsubcuenta` = **longitud de Clarto** de la empresa (por defecto **10**; permitido 9–11). Se establece **antes** de importar el plan, con el ejercicio vacío (si ya hay subcuentas, FS rechaza el cambio; Clarto también lo fija de forma inmutable tras la primera operación). El Asistente (`Wizard.php:156`) importa el plan solo si no hay cuentas; un ejercicio creado por fecha (`Ejercicio::createNew`) hereda `longsubcuenta` pero **no** el plan: hay que importarlo desde `EditEjercicio`.

### A.2 Estructura del plan (ESP, `defaultPlan.csv`, `;`, cabecera `cuenta;descripcion;cuentaesp`)
| Nivel | Dígitos | Filas | Ejemplo |
|---|---|---|---|
| Grupo | 1 | 9 | `1` Financiación básica |
| Subgrupo | 2 | 78 | `10` Capital |
| Cuenta | 3 | 421 | `100` Capital social |
| Subcuenta de cuenta | 4 | 397 | `1030` Socios por desembolsos no exigidos… |
| Auxiliar | 5 | 10 | `76200`–`76202` |
| **Subcuenta** (longitud del ejercicio) | **10** | **745** | `4720000021` |
Total 1 660 (+ cabecera). El importador trata la longitud máxima del fichero como subcuenta y rellena con ceros a la longitud del ejercicio (`convertSubaccountCode`); si el CSV de Clarto usa otra longitud (9 u 11), el generador (T-11) escribe las subcuentas ya a esa longitud y FS no tiene que convertir.
Nombres: PGC oficial en español tal cual el CSV (ej. `129` «Resultado del ejercicio», `430` «Clientes»). Clarto hereda esos nombres (G1) y no los modifica sin versionar.

### A.3 Cuentas especiales (38; asignación del plan ESP)
`PREVIO=120 · PRENEG=121 · PYG=129 · DIVPOS=135 · PROVEE=400 · ANTPRO=407 · ACREED=410 · CLIENT=430 · ANTCLI=438 · IVADEU=4700 · IVASOP=472 · IRPF=473 · IVAACR=4750 · IRPFPR=4751 · IVAREP=477 · SUPLI=555 · CAJA=570 · BANCO=572 · COMPRA=600 · DEVCOM=6080 · EURNEG=668 · VENTAS=700..705 · DEVVEN=7080 · EURPOS=768`; sin cuenta asignada en el CSV: `CAMNEG, CAMPOS, GTOBAN, GTORF, INGRF, IRPFA, IVAEUE, IVAREX, IVARRE, IVARUE, IVARXP, IVASEX, IVASIM, IVASUE`.
Correspondencia con Clarto (`contabilidad.js:11`, `diario.js:17`, `clasificador.js:28`): CLIENT↔430, PROVEE↔400, ACREED↔410, IVASOP↔472, IVAREP↔477, IRPF↔473 (retención en venta emitida), IRPFPR↔4751 (retención practicada en compra), COMPRA↔600, VENTAS↔700. Regla del clasificador (`629`, `7xx`) no tiene cuenta especial en FS: se resuelve por subcuenta del producto/servicio.

### A.4 Impuestos y subcuentas de IVA (Opción A: igual a Clarto)
`impuestos` (activos solo IVA0/4/10/21):
| codimpuesto | iva | recargo | codsubcuentarep | codsubcuentasop | codsubcuentarepre |
|---|---|---|---|---|---|
| IVA0 | 0 | 0 | — | — | — |
| IVA4 | 4 | 0,5 | 4770000000 | 4720000000 | 4770000000 |
| IVA10 | 10 | 1,4 | 4770000000 | 4720000000 | 4770000000 |
| IVA21 | 21 | 5,2 | 4770000000 | 4720000000 | 4770000000 |
(En longitud 10; para 9/11 dígitos, ajustar ceros: `cuenta.padEnd(L,'0')`.) Intracomunitarios `codsubcuentarepintra/sopintra`: **vacíos** hasta que Clarto modele G5. Desactivar IGIC/IPSI (`activo=false`). `default.codimpuesto=IVA21`.
`retenciones`: IRPF2/7/15/19/21 con `codsubcuentaret=4730000000` y `codsubcuentaacr=4751000000`. Si Clarto necesita 1 % o 24 %, se dan de alta desde Clarto.
Opción B (FS estándar, por tipo `4770000021`…): solo si Clarto adopta subcuentas por tipo (D-1).

### A.5 Series y numeración
| Serie | tipo | siniva | Patrón | longnumero | usarhuecos | mantenerfecha |
|---|---|---|---|---|---|---|
| `A` General | — | no | `{SERIE}{EJE}-{0NUM}` → `A2026-000001` | 6 | **false** | false |
| `R` Rectificativas | `R` | no | idem → `R2026-000001` | 6 | **false** | false |
| `S` Simplificadas | `S` | no | idem → `S2026-000001` | 6 | **false** | false |
Alinea el motor propio de Clarto (`A2026-000001`). `codigo` ≤ 20 cabe (`A2026-000001` = 12). `inicio`=1; para el tenant 0 se fija el siguiente número al último existente (FAC-PL-20). Facturas de proveedor: patrón de FS por defecto (no contable de emisión).
`default.codserie=A`, `default.codserierec=R`.

### A.6 Resto de ajustes (`settings`) y catálogos
`default.decimals=2` · `default.coddivisa=EUR` (única divisa) · `default.tipoidfiscal=NIF` · `default.codpago=CONT` · `default.enable_api=true` + clave API de lectura por tenant (`allowget` en `facturaclientes, facturaproveedores, clientes, proveedores, reciboclientes, recibosproveedores, pagoclientes, ejercicios, cuentas, subcuentas, impuestos, retenciones, series, secuenciadocumentos, empresas, asientos, partidas`) · `validate_iban=true` · `codcliente`/`codproveedor` alfanuméricos ≤10 · Facturas de cliente: estado inicial Boceto→Emitida (estado 11, no editable). Diarios por defecto (1–4) se dejan.

### A.7 Verificación al aprovisionar (criterio de cierre del T-19)
1. `ejercicios.longsubcuenta` = `contabilidad_empresa.longitud_subcuenta`.
2. Nº de cuentas y subcuentas = filas del CSV generado por Clarto (1 660 con el plan por defecto).
3. Las 24 cuentas especiales con cuenta asignada coinciden con A.3.
4. `impuestos` y `retenciones` = A.4.
5. `series` y `secuencias_documentos` = A.5 (`usarhuecos=false`).
6. Factura de prueba (IVA 21 %, IRPF 15 %, y una rectificativa) en `teleco-test`: asiento FS vs asiento formado por Clarto coinciden en cuentas de 3–4 dígitos y en importes al céntimo.
