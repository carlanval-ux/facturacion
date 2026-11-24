# RemesasSepaFacturalo — Cobro domiciliado (módulo `sepa`, 5 €/mes)

Plugin propio, desarrollado desde cero a partir del estándar público ISO 20022 / SEPA (pain.008.001.02). No usa ni copia plugins de terceros.

## Qué hace
1. **Mandatos SEPA** (Ventas → Cobro domiciliado → *Mandatos SEPA*, y pestaña en la ficha del cliente): IBAN validado con dígito de control (módulo 97), BIC opcional, referencia única (se genera sola), fecha de firma, esquema CORE/B2B, secuencia FRST/RCUR/FNAL/OOFF, estado activo/revocado. Botón **Documento del mandato (PDF)** con nombre/logo de la empresa, texto legal del esquema y huecos de firma.
2. **Acreedor por empresa** (pestaña *Acreedor SEPA*): identificador ACI, nombre, IBAN de cargo, BIC y plazos mínimos de presentación. Si faltan datos, la pestaña de recibos avisa.
3. **Remesas**: pestaña *Recibos a remesar* (recibos pendientes, en EUR, de clientes con mandato activo y aún no remesados). Se seleccionan y **Crear remesa**: cada recibo se cobra en su vencimiento o, si este ya es anterior al plazo, en la primera fecha posible (siempre día hábil TARGET); se agrupa por fecha de cobro + esquema + secuencia (un bloque `PmtInf` por grupo) y se guarda el XML `pain.008.001.02`, descargable en *Descargar XML SEPA*.
   - Validaciones: caracteres SEPA (transliteración de acentos/ñ), longitudes (nombres 70, concepto 140, ids 35), importes con 2 decimales entre 0,01 y 999.999.999,99, EUR, referencias únicas, IBAN/BIC, totales y orden de elementos.
   - **Plazos** (configurables por acreedor, días hábiles entre presentación y cobro): primer adeudo CORE (FRST/OOFF) **5**, recurrente CORE **1**, B2B **1**. Se cambian en *Acreedor SEPA → Plazos mínimos de presentación*. Nota: el reglamento EPC vigente permite 1 día en todos los casos; los 5 días del primer adeudo son el valor prudente pedido (algunos bancos lo exigen). Pónganse a 1 si el banco lo admite.
4. **Resolución**: en la remesa, cada línea pasa de *Remesado* a *Cobrado* (marca el recibo como pagado y genera el pago) o *Devuelto* (motivo obligatorio AC01, AM04…; el recibo sigue pendiente con nota en observaciones). Al cobrar un FRST el mandato pasa a RCUR; OOFF/FNAL cobrados revocan el mandato. Cuando no quedan líneas por resolver la remesa se cierra. Una remesa sin resultados puede **anularse** (libera los recibos).
5. **Historial y exportación**: listado de remesas con estado, XML y CSV descargables.
6. Cualquier recibo (también de facturas recurrentes) con mandato activo aparece como remesable; no hay dependencia de otros módulos.

## Módulo comercial
`ModuloActivo::estaActivo('sepa')` lee el ajuste `facturalo` → `modulos.sepa`. Sin valor, está **activo** (pruebas). Si está inactivo: la página se oculta del menú, las pantallas redirigen al inicio y no se añade la pestaña al cliente.

## RGPD
El IBAN se muestra enmascarado (`ES91 **** **** **** **** 1332`); el IBAN completo solo lo ve el administrador (columna con nivel 99). Para fijar/cambiar un IBAN se escribe en *Nuevo IBAN* (solo escritura). El IBAN no se escribe en logs ni mensajes de error. El XML guardado en la base de datos contiene los IBAN (es el fichero que se entrega al banco): proteger los accesos y copias.

## Validación contra el esquema oficial
`SepaXml::validarXml()` comprueba buena formación, totales, formato de importes y orden de elementos. Si se coloca el XSD oficial ISO 20022 en `Data/pain.008.001.02.xsd` también valida contra él (no incluido: no se descargó ningún fichero externo).

## Tests
- `Test/main/RemesasSepaTest.php` (PHPUnit): IBAN válidos/inválidos, máscara, días hábiles (Semana Santa), texto SEPA, ACI, generación y agrupación del XML con ejemplos, plazos, rechazos.
- `Test/sepa-live.sh`: prueba en vivo en teleco-test (modelos, PDF, remesa→cobro→reversión con `IDRECIBO=`).

## Límites conocidos
- Solo EUR y un acreedor por empresa; remesas de una sola empresa.
- Dígito de control del ACI: solo aviso (no bloquea).
- No hay envío al banco ni lectura de ficheros de devoluciones (pain.002/camt): la resolución es manual.
- Sin PHP local al desarrollarlo: no ejecutado aún (`php -l`, PHPUnit y script en vivo pendientes del coordinador).
