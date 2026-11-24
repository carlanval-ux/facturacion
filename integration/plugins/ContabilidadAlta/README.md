# ContabilidadAlta

Lado Facturalo del mapa Facturalo↔Clarto (tickets MAPA-18 a 23). **Funciones de plataforma: siempre activas, no son un módulo de pago.** Clarto/gestoría es el maestro de los parámetros contables; este plugin los aplica y los verifica. No toca el Core.

| Ticket | Qué hace |
|---|---|
| MAPA-19 | Aprovisionamiento contable + informe de diferencias (criterio A.7) |
| MAPA-21 | Secuencias de factura de cliente: `usarhuecos=false`, `mantenerfecha=false` siempre; las nuevas con patrón `{SERIE}{EJE}-{0NUM}` (`A2026-000001`) |
| MAPA-22 | Códigos **nuevos** de cliente/proveedor: alfanumérico, 1-10 (los existentes no se tocan) |
| MAPA-20 | Tabla `facturalo_cambios` (outbox) + `GET /api/3/cambios` |
| MAPA-18 | Lista de periodos cerrados y bloqueo de fecha en facturas de cliente y proveedor |
| MAPA-23 | (en `TelecoBridge`) `telecoRectificativaCliente` y `telecoPagarFacturaCliente/{id}` con `Idempotency-Key` |
| MAPA-24 | **No hecho**: ver «Pendiente» |

## MAPA-19 · Aprovisionamiento (`/api/3/contabilidadAlta`, clave API de **acceso completo**)
- `GET ?idempresa=&longsubcuenta=10&anyo=2026` → solo informe: `{conforme, diferencias:[{clave, esperado, actual}]}`. No escribe.
- `POST` (mismos campos + `plan_csv` opcional con el texto del CSV `cuenta;descripcion;cuentaesp` de Clarto; sin él, el ESP de FS) → aprovisiona y devuelve `pasos` + el informe.
- Por defecto: empresa por defecto, `longsubcuenta` 10 (9–11), año actual.
- Pasos (idempotentes; nunca borran ni renombran): ejercicio año natural abierto con `longsubcuenta` · importación del plan **solo si el ejercicio no tiene subcuentas** (cuentas especiales vienen del CSV) · impuestos IVA 0/4/10/21 con subcuentas de grupo `4770000000`/`4720000000` (A.4; IGIC/IPSI existentes → `activo=false`) · retenciones IRPF 2/7/15/19/21 (`473…`/`4751…`) · series A, R(tipo R), S(tipo S) y sus secuencias · ajustes `default.decimals=2, coddivisa=EUR, tipoidfiscal=NIF, codpago=CONT, codserie=A, codserierec=R, codimpuesto=IVA21` (los que dependen de un registro, solo si existe).
- Con facturas ya emitidas en una serie/ejercicio el patrón **no** se cambia (se informa) y sale como diferencia; `usarhuecos=false` sí.
- Si el ejercicio ya tiene subcuentas con otra longitud no se cambia (FS no lo permite): sale como diferencia.
- Verificación: ejercicio (longitud, fechas, estado) · cuentas/subcuentas del CSV presentes y `cuentaesp` de cada cuenta · impuestos y retenciones · series y secuencias · ajustes. Las subcuentas se comparan por número, no una a una.
- Sin CLI propio: el endpoint cubre el alta (la clave por tenant ya existe). Script en vivo: `contabilidad-alta-live.sh` (carpeta de notas).

## MAPA-20 · Cambios (`GET /api/3/cambios?desde=&limit=`)
Solo lectura. `desde` = id del último cambio visto (cursor) o fecha `Y-m-d[ H:i:s]`. Respuesta `{cambios:[{id,tipo,idref,idfactura,idfacturarect,evento,creado}], siguiente, hay_mas}` (máx. 500). `tipo` = `factura`|`recibo`; `evento` = `guardado`|`eliminado`. Cada alta, pago, vencimiento, rectificativa (`idfacturarect` informado) o cambio de estado genera una fila; Clarto relee ese `idfactura`. Clave sin acceso completo: `allowget` sobre el recurso `cambios`.

## MAPA-18 · Periodos cerrados (`/api/3/periodosCerrados`)
`GET` lista; `POST periodos=2026-T1,2026-04` sustituye la lista (acceso completo; vacío = ninguno). Formatos: `2026-T1`, `2026`, `2026-03`, `2026-01-01..2026-03-31`. Ajuste `facturalo.periodos_cerrados`. Sin lista no se bloquea nada (no depende de Clarto). Se rechaza **crear** una factura (cliente o proveedor) o **cambiar su fecha** a un día cerrado; guardar una existente sin tocar la fecha (cobros, etc.) sigue permitido. Convive con la protección de fecha futura de `Facturalo`.

## MAPA-23 · Idempotencia (`TelecoBridge`)
`POST /api/3/telecoRectificativaCliente` (cuerpo de `crearFacturaRectificativaCliente`) y `POST|PUT /api/3/telecoPagarFacturaCliente/{id}` (cuerpo de `pagarFacturaCliente`), cabecera `Idempotency-Key` obligatoria (8-80 `[A-Za-z0-9_.:-]`). Misma tabla `teleco_idempotencia`, mismas reglas que `telecoFacturaCliente`: repetición = misma respuesta (`Idempotent-Replay: true`); misma clave con otro cuerpo/otra factura = 422; en curso = 409; un fallo libera la clave. La clave se liga al endpoint. Los endpoints del núcleo siguen existiendo sin idempotencia.

## Pendiente / límites
- **MAPA-24 no se ha hecho**: `PaqueteTrimestralFacturalo` ya da IVA trimestral (303) y los libros de emitidas/recibidas (listado). Falta «ventas por cliente» (el 347 solo lista los de > 3.005,06 €) y que el menú Informes enlace a esas pantallas; es una pantalla pequeña pero queda fuera.
- FS avisa con un error de registro («use-holes-invoices-esp») al guardar secuencias de factura con `usarhuecos=false` en España: es solo un mensaje del Core; la decisión D-3 del mapa es la contraria. Con `usarhuecos=false`, **borrar** una factura deja hueco: no se bloquea el borrado aquí.
- `facturalo_cambios` crece sin purga. El registro de cambios no rompe nunca una factura (si falla, se descarta).
- El bloqueo de periodos devuelve el error genérico del Core en la API (422) y el motivo en el registro.
- No se activa `default.enable_api` ni se crea la clave API: es del alta de plataforma.
- Pruebas: `Test/main` (PHPUnit de FS) cubre las reglas puras; el aprovisionamiento y los hooks se prueban en vivo con `contabilidad-alta-live.sh` (aún **no ejecutado**).
