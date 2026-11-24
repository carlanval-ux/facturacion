# AnticiposFacturalo — Anticipos y cobros a cuenta

Módulo comercial de Facturalo (clave `anticipos`, 5 €/mes). Desarrollo propio (clean-room): no usa código de otros plugins de anticipos.
Activo si `facturalo.modulos.anticipos` no es `0/false/no/off` (sin valor = activo, para pruebas). Con el módulo apagado no se cargan pestañas ni descuentos y el menú se oculta (`Lib/ModuloActivo.php`; si Facturalo aporta un `ModuloActivo` común, basta cambiar el `use`).

## Qué hace
1. **Registrar anticipo** (presupuesto, pedido o albarán de cliente → pestaña *Anticipos* → botón): importe (IVA incluido) **o** %, fecha, forma de pago y observaciones.
2. Crea una **factura de anticipo** normal de FS (serie propia `ANT`, configurable en *Anticipos → Ajustes*), con líneas «Anticipo de pedido X» **repartidas por tipo de IVA del documento** en proporción a su peso, el recibo **cobrado** y la emisión (primer estado no editable y activo, igual que la rectificativa del núcleo).
3. Al generar la **factura final** (desde presupuesto, pedido o albarán, también en cadena presupuesto→pedido→albarán→factura: el anticipo pendiente viaja al documento nuevo) se añaden líneas negativas por cada anticipo y tipo de IVA («Anticipo ya facturado en ANT2026-1»), antes de calcular y de emitir. El total a pagar es la diferencia.
4. Si el anticipo **supera lo que queda por facturar** (p. ej. el documento bajó de importe), no se descuenta, queda *pendiente* y se avisa: se devuelve rectificando la factura de anticipo.
5. **Rectificar la factura de anticipo** con una rectificativa que la anula entera la marca *devuelto* y la final deja de descontarla. Rectificación parcial: aviso, sigue pendiente. Si ya estaba aplicada: aviso de rectificar también la final.
6. Vistas: **Ventas → Anticipos** (todos, filtros por cliente/estado/fecha), pestaña en cada documento y en la ficha del cliente; estados *Pendiente de aplicar / Aplicado / Devuelto* y aviso de anticipos sin aplicar.
- Borrar la final devuelve sus anticipos a pendientes; no se puede borrar una factura de anticipo ya aplicada.

## IVA (sin duplicados)
El anticipo devenga IVA al cobrarse: su factura lo declara. La final descuenta **base por tipo** (no solo el total), así el IVA de final + anticipos = IVA del documento, con diferencias de céntimos como mucho (el IVA se redondea por factura y tipo). Casos en `Test/main/RepartoTest.php`, p. ej. pedido 600 al 21 % + 400 al 10 % (total 1.166, IVA 166): anticipos 500 y 300 → final 313,89 + 52,11 IVA = 366,00; IVA 54,03+17,15+32,42+10,29+52,11 = 166,00.
Si el importe pedido no se puede alcanzar al céntimo con IVA por tramo, se factura el más cercano y se avisa (p. ej. 20,00 → 20,01).

## VERI*FACTU
- La factura de anticipo se crea y se **emite por el flujo normal de FS** (cambio a estado no editable): ahí el plugin Verifactu registra el alta (en modo pruebas, el del ejercicio). Se respeta la protección de fecha de Facturalo (no fecha futura): si falla, no se crea nada.
- La factura final se genera **editable**: las líneas de descuento se añaden antes de emitirla, así su alta VERI*FACTU ya incluye los descuentos. Nunca se modifica una factura ya emitida/registrada.
- Rectificar un anticipo es el flujo normal de rectificativas (con su propio registro). El módulo solo reacciona a posteriori (estado *devuelto*); no crea ni envía registros.
- No se fuerza ningún envío a la AEAT.

## Qué NO cubre
- IRPF/retenciones, recargo de equivalencia y suplidos en el reparto del anticipo (el recargo lo calcula FS en la factura; el importe puede variar unos céntimos).
- Descuento parcial de un anticipo (se aplica entero o nada); facturación parcial de un documento: el anticipo se descuenta en la primera final.
- Devolución del dinero: la rectificativa genera su recibo negativo; el pago real lo gestiona el usuario. Sin pasarelas.
- Anticipos de proveedor, otras monedas, y corregir automáticamente una final ya emitida cuando se rectifica un anticipo aplicado (solo aviso).
- Un anticipo registrado en un presupuesto no aparece en su pestaña una vez trasladado al pedido (viaja con el documento vigente).

## Pruebas
- `Test/main/RepartoTest.php` (PHPUnit, sin base de datos). Las cifras se contrastaron con un cálculo independiente; **no se ha podido ejecutar PHP** en el entorno de desarrollo.
- `Test/anticipos-live.sh`: prueba en vivo en teleco-test (dos IVA, dos anticipos, rechazo por exceso, cadena pedido→albarán→factura, rectificativa, anticipo > total, borrado). `ssh ploi@host 'bash -s' < Test/anticipos-live.sh`.
- Sin verificar en vivo: pestañas y modales (Edit*Cliente), emisión/alta VERI*FACTU, el estado «emitida» elegido y las traducciones de widgets.
