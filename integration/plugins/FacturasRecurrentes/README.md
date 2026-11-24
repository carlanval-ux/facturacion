# FacturasRecurrentes

Plugin propio (desarrollo desde cero, sin código ni textos de terceros) que automatiza la creación de documentos repetidos. Menú **Ventas → Documentos recurrentes**.

## Qué hace
- **Plantilla**: cliente (o proveedor, tipo *compra*), líneas (producto por referencia o concepto libre; cantidad, precio, impuesto, descuento), documento a generar (factura, albarán, pedido, presupuesto), serie, forma de pago, observaciones.
- **Periodicidad**: cada N días / semanas / meses, o solo manual (botón *Generar ahora*); inicio, fin opcional, próxima fecha, % del primer documento (prorrateo), activa/pausada. En meses se conserva el día (31 ene → 28 feb → 31 mar).
- **Automático**: `Cron.php` (job de 5 min en el cron de cada tenant) genera lo vencido. Idempotente: cada (plantilla, fecha prevista) tiene una fila única en el historial (`clave` UNIQUE) reservada antes de crear nada; segunda pasada = no duplica. Si falla se guarda el error y se reintenta en la siguiente pasada; tras 5 fallos la plantilla se pausa. Recupera pasadas perdidas (máx. 12 por plantilla y pasada).
- **Fecha**: el documento nunca lleva fecha futura. Opción *Si la fecha ya pasó*: fecha prevista (por defecto) o la de hoy. Las facturas siguen el flujo normal del modo pruebas VERI*FACTU; el plugin no fuerza envíos.
- **Email** (opcional, solo ventas): check *Enviar por email al cliente*. Si el plugin `AutoEnvioFacturas` está activo no envía (delega, queda anotado); si no, envía el PDF con el sistema de email estándar (si hay email configurado).
- **Grupos**: un grupo guarda datos y líneas; cada plantilla con *Grupo* usa las líneas/serie/pago/documento del grupo y mantiene su cliente y planificación. Cambiar una línea del grupo aplica a todas. *Generar ahora* en un grupo genera para todos sus clientes activos.
- **Historial** por plantilla: fecha prevista, fecha del documento, código, resultado, intentos, detalle, envío.

## Instalación
Se copia con el resto de `integration/plugins/` (workflow `fs-deploy.yml`). Activar desde Administración → Plugins (o `Plugins::enable('FacturasRecurrentes')`). Las tablas se crean solas.

## Tests
- `Test/main/RecurrenciaTest.php` (fechas, sin BD) y `Test/main/GeneradorTest.php` (idempotencia, con BD). No se han podido ejecutar en local (sin PHP en esta máquina).
- En vivo (teleco-test): script `recurrentes-live.sh` en la carpeta de notas de sesión.

## Notas
- Traducciones en `Translation/` (carpeta estándar del núcleo, no `Translations/`).
- Permisos: los de cualquier página de Ventas; el administrador de la empresa los concede a sus usuarios. Nada técnico.
- Límite conocido: el reintento de una fila en error no usa bloqueo atómico (el job de cron ya impide pasadas simultáneas).
