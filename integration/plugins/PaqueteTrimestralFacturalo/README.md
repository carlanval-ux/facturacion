# PaqueteTrimestralFacturalo

Módulo comercial de Facturalo (clave `trimestral`, 5 €/mes): da al cliente y a su gestoría las **cifras del periodo**. **No presenta nada ante la Agencia Tributaria.** En pantalla y en cada documento figura: *«Cifras orientativas calculadas con los datos de Facturalo; no constituyen una presentación ante la Agencia Tributaria.»*

Código propio (clean-room): solo normativa pública y cálculos sobre los modelos de facturas. No usa ni lee los plugins Modelo303/111/115/130/347/349 ni FSReports/ExtendedReport.

## Activación
`ModuloActivo::estaActivo('trimestral')` lee el ajuste `facturalo.modulos.trimestral`. **Activo por defecto** (sin ajuste); solo un valor `0/false/off/no/inactivo` lo apaga. Sin el módulo la pantalla avisa y no calcula.

## Pantalla `Paquete trimestral` (menú Informes)
Selector de ejercicio y periodo (1T–4T / anual) y «pagos fraccionados previos» (dato del usuario para el 130). Tablas 303, 130, 111, 115, 347, 349 + avisos de datos. **Cada cifra es un enlace** al listado de facturas que la compone (cada una enlaza a su factura).

| Bloque | Qué calcula |
|---|---|
| 303 | Base y cuota repercutida por tipo (21/10/4/0, y otros si existen), recargo de equivalencia por tipo, exentas, no sujetas, inversión del sujeto pasivo; IVA soportado por tipo, recargo soportado (no deducible); devengado − deducible |
| 130 | Acumulado enero→fin del periodo: ingresos − gastos (bases), 20 %, retenciones soportadas, pagos previos → resultado. Sin provisión de difícil justificación ni reducciones |
| 111 / 115 | Retenciones practicadas en compras del periodo por perceptor. Alquiler (115) si la descripción de la retención contiene alquiler/arrend/inmueble o, sin descripción, el tipo es 19 %; el resto es 111 |
| 347 | Año completo: terceros con operaciones (IVA incluido, rectificativas restan) **> 3.005,06 €**, desglose por trimestres. Excluye intracomunitarias/exportación/importación |
| 349 | Operaciones intracomunitarias por tercero y clave E/S (ventas) y A/I (compras) |

Criterio: facturas por **fecha de la factura** dentro del año del ejercicio y la empresa activa; las rectificativas ya llevan importe negativo y restan. Las bases se escalan al neto de cabecera (descuentos globales) y se redondean a 2 decimales por grupo de tipo. Las compras se consideran con IVA íntegramente deducible (orientativo).

### Avisos de datos
`nif_vacio`, `nif_vacio_347`, `sin_ejercicio`, `otro_ejercicio` (fecha de este año con otro ejercicio), `retencion_no_informada` (proveedor con retención en otras facturas del año y sin ella en una del periodo), `rect_positiva`.

## Exportaciones
- Libros de **emitidas** y **recibidas** (formato libro IVA: una fila por factura y tipo): **CSV UTF-8 con BOM y separador `;`**, decimales con coma (abre bien en Excel), y **PDF** con el nombre y NIF del emisor. No se genera `.xlsx`.
- Resumen PDF/CSV y **JSON** de datos.
- **Paquete ZIP**: `LEEME.txt`, `paquete.json`, `resumen.csv/pdf`, libros CSV/PDF y, si el plugin FacturaZip está activo (sin depender de él), `facturas-emitidas/*.pdf`.
- CSV: las celdas que empiezan por `= + @` se neutralizan.

## Esquema JSON estable (`facturalo.paquete-trimestral`, versión 1)
Pensado para Clarto. Los campos existentes no cambian de significado dentro de la misma `version`; se pueden añadir campos. Importes en euros, números con 2 decimales, negativos para rectificativas.

```jsonc
{
  "schema": "facturalo.paquete-trimestral", "version": 1,
  "aviso": "Cifras orientativas …",
  "emisor": { "nombre": "", "cifnif": "" },
  "periodo": { "ejercicio": "2026", "anyo": 2026, "periodo": "T1|T2|T3|T4|anual", "desde": "2026-01-01", "hasta": "2026-03-31" },
  "generado": "ISO-8601",
  "modelos": {
    "303": {
      "repercutido": { "tipos": { "21": {"base":0,"cuota":0,"refs":["cli:12"]}, "10":{}, "4":{}, "0":{} },
                       "recargo_equivalencia": { "5.2": {"base":0,"cuota":0,"refs":[]} },
                       "exentas": {"base":0,"refs":[]}, "no_sujetas": {…}, "isp": {…} },
      "soportado":   { "tipos": { "21": {…} }, "recargo_soportado": {…}, "exentas": {…}, "no_sujetas": {…}, "isp": {…} },
      "total_base_repercutida":0, "total_cuota_repercutida":0, "total_recargo_equivalencia":0, "total_devengado":0,
      "total_base_soportada":0, "total_cuota_deducible":0, "resultado":0
    },
    "130": { "acumulado_desde":"", "acumulado_hasta":"", "ingresos":{"importe":0,"refs":[]}, "gastos_deducibles":{…},
             "rendimiento_neto":0, "veinte_por_ciento":0, "retenciones_soportadas":{…}, "pagos_previos":0, "resultado":0, "nota":"" },
    "111": { "perceptores":[{"nombre":"","cifnif":"","base":0,"retencion":0,"refs":[]}], "num_perceptores":0, "base":0, "retenciones":0, "refs":[] },
    "115": { "…": "igual que 111" },
    "347": { "anyo":2026, "umbral":3005.06,
             "clientes":[{"nombre":"","cifnif":"","total":0,"trimestres":[0,0,0,0],"refs":[]}], "proveedores":[…] },
    "349": { "operaciones":[{"clave":"E|S|A|I","nombre":"","cifnif":"","base":0,"refs":[]}], "total":0 }
  },
  "libros": { "emitidas":[{"fecha":"","factura":"","numero_externo":"","tercero":"","cifnif":"","rectificativa":false,
                           "base":0,"tipo_iva":21,"cuota_iva":0,"tipo_recargo":0,"cuota_recargo":0,"retencion":0,"ref":"cli:12"}],
              "recibidas":[…] },
  "avisos": [ { "codigo":"nif_vacio", "mensaje":"<código de factura o tercero>", "ref":"cli:12|null" } ]
}
```
`ref` = `cli:<idfactura>` (venta) o `prov:<idfactura>` (compra). Descarga: `PaqueteTrimestral?codejercicio=…&periodo=T1&action=json` (sesión de usuario con permiso de exportar).

## Pruebas
- `Test/main/CalculoTest.php` (PHPUnit): redondeos, descuento global, rectificativas con signo negativo, facturas de otro ejercicio, recargo de equivalencia, 111/115, 130 acumulado, umbral 347, 349, avisos, CSV/JSON.
- En vivo: `trimestral-live.sh` (teleco-test; crea y borra datos de prueba).

## Límites conocidos
Orientativo: sin criterio de caja, prorrata, regímenes especiales (agrario, REBU…), compensación de periodos anteriores, ni validación de NIF; el 130 no calcula pagos previos automáticamente.
