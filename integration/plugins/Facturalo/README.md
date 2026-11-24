# Facturalo (plugin de marca blanca)

## Protección de fecha de facturas de cliente
`Extension/Model/FacturaCliente.php` (hook `saveBefore`) rechaza guardar una factura de cliente con `fecha` posterior a hoy.
- Solo en alta o cuando **cambia** la fecha; una factura existente con fecha futura que no se toca sigue editable.
- Compara solo el día, en la zona horaria configurada en FS (Europe/Madrid por defecto), no UTC; hoy se permite a cualquier hora.
- Afecta a todo lo que pasa por `save()`: UI, API `/api/3` (devuelve error 4xx con el mensaje), agrupar albaranes, rectificativas, TelecoBridge e importaciones. No afecta a facturas de proveedor ni a otros documentos.
- Mensaje (clave `fecha-factura-futura`, es/en): «No se puede emitir una factura con fecha posterior a hoy (%fecha%). Corrige la fecha para no alterar la numeración y la secuencia de las facturas.»
- Sin configuración. Lógica en `Lib/FechaFutura.php`; tests: `Test/main/FechaFuturaTest.php` (unitarios) y `plugins-live`/`fecha-futura-live.sh` en las notas (en vivo).
