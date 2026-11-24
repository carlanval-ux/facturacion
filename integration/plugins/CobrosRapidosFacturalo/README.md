# CobrosRapidosFacturalo — Cobro rápido y pagos múltiples

Módulo comercial `cobros` (5 €/mes). Plugin propio, clean-room. Se activa con `ModuloActivo::estaActivo('cobros')`; mientras esa clase no exista lee el ajuste `facturalo` → `modulos.cobros` (activo por defecto si no está definido). Inactivo: no aparecen botones y los controladores se niegan.

## Qué hace
- **Marcar como cobrada**: botón en el listado de facturas, en su pestaña de recibos y en la ficha de la factura. Abre la ventana *Cobrar* (`CobrarFacturas`): fecha (hoy, no futura), forma de pago, cuenta bancaria opcional, observación.
- **Lote**: facturas/recibos de uno o varios clientes en una sola operación (mismo método y fecha). Por fila se edita el importe a cobrar, o se indica un **total** que se reparte por vencimiento. Todo o nada (transacción).
- **Parcial**: si se cobra menos que el recibo, este queda por lo cobrado y se crea otro recibo pendiente por el resto (mismo vencimiento). No se puede cobrar más de lo pendiente.
- **Anular cobro**: *Ventas → Registro de cobros*, seleccionar, motivo obligatorio. El recibo vuelve a pendiente; FS genera su pago de reversión y la factura queda impagada. No se anula si el recibo cambió después.
- **Auditoría**: tabla `cobros_registro` (quién, cuándo, recibo, importe, método, cuenta, lote, motivo) visible en el mismo listado.

## Integración
Solo usa `ReciboCliente`/`FacturaCliente::getNewReceipt`: pone `pagado`, `fechapago`, `codpago` y FS crea el pago, el asiento y el estado de la factura. Sin SQL directo. No toca facturas ni registros VERI*FACTU: solo el estado de cobro que ya gestiona FS.

## Límites conocidos
- La cuenta bancaria queda en el registro y en las observaciones del recibo; FS no la usa en el asiento (usa la de la forma de pago).
- La ventana de cobro es una página compacta propia, no un modal sobre el listado (los modales de FS solo se declaran en el XML del propio controlador).
- Tras anular un cobro parcial quedan dos recibos pendientes (no se fusionan).
- No probado en vivo todavía (sin PHP local): ejecutar `cobros-live.sh` y `php -l`.

## Pruebas
`Test/main/RepartoTest.php` (unitaria). Script en vivo para `teleco-test`: cobro único, parcial, lote, anulación, fecha futura y exceso.
