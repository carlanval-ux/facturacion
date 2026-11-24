# RecordatorioImpagosFacturalo

Módulo comercial **«impagos»** (5 €/mes). Reclamación automática por email de recibos de cliente pendientes, en hasta 5 pasos de tono creciente. Desarrollo propio (clean-room).

## Qué hace
- Pasos: **previo** (N días antes del vencimiento), **día** del vencimiento, **post1/post2/post3** (D días después; post2 y post3 opcionales y desactivados por defecto). Cada paso: activo, días, asunto y cuerpo editables con `{cliente} {numero} {importe} {vencimiento} {enlace} {empresa}`.
- Ajustes por empresa/tenant (Ventas → Recordatorio de impagos): activar, no reclamar vencimientos anteriores a una fecha (por defecto, la de activación), franja horaria y solo laborables, máx. correos por pasada, PDF de factura adjunto, nombre del remitente y email de respuesta, clientes y facturas excluidos (códigos).
- Cron de FS cada 10 min. **Idempotente**: la clave única `idrecibo-paso` impide enviar dos veces un paso al mismo recibo (se reserva la fila antes de enviar). Errores: hasta 3 intentos. Historial con fecha, paso, destinatario y resultado.
- Se detiene si el recibo se cobra, si la factura es rectificativa o tiene abonos/rectificativas, si el ejercicio no existe, si el cliente/factura está excluido o dado de baja, y no envía a clientes sin email válido (queda anotado `sin-email`).
- Si el módulo se activa tarde y varios pasos ya habían vencido, **solo se envía el último**; los anteriores se anotan como `omitido`.
- **Enviar recordatorio ahora**: botón sobre los recibos marcados en la pestaña «Recibos pendientes» (usa el paso que toca hoy; ignora horario y fecha mínima, respeta cobro, abonos, exclusiones y baja).
- Cumplimiento: tono respetuoso, cada correo añade el emisor (nombre y NIF) y un enlace de baja firmado (HMAC); la baja se confirma con un botón (POST) para que los antivirus de correo no la disparen. Sin datos a terceros. Sin la palabra «FacturaScripts» en textos de usuario ni en la página de baja.
- Control de módulos: `Lib/ModuloActivo::estaActivo('impagos')` lee `facturalo.modulos.impagos` (activo si no existe). Único punto a enganchar a la plataforma.

## Instalación
Copiar a `Plugins/` y activar. Tablas `impagos_recordatorios` e `impagos_bajas` y ajustes por defecto se crean solos (grupo de ajustes `recordatorioimpagos`). Requiere cron del sistema de FS y email configurado (Administrador → Emails) y `site_url` definido (si es localhost no se envía: el enlace de baja sería inservible).

## Pruebas
- PHPUnit: `Test/main/PasosTest.php` (lógica de pasos, horario, variables, token).
- En vivo (teleco-test): `impagos-live.sh` en la carpeta de notas (solo lectura; envío real opcional).

## No probado / límites
- Sin PHP local: no se ha ejecutado nada (ni `php -l`, ni PHPUnit, ni la UI).
- Un solo grupo de ajustes por tenant (no por empresa dentro de un mismo tenant multiempresa).
- «Ejercicio anulado» se interpreta como ejercicio inexistente; los ejercicios cerrados sí se reclaman.
- El pie del correo del núcleo (NewMail) puede incluir su propia marca; no se ha revisado la plantilla de email.
- La baja es por cliente (no por factura) y se revierte borrando la fila en la pestaña «Clientes dados de baja».
