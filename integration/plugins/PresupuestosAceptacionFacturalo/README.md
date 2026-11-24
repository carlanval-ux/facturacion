# PresupuestosAceptacionFacturalo — Presupuesto a factura con aceptación online

Módulo comercial de Facturalo (clave `aceptacion`, 5 €/mes). Código propio, clean-room; sin telemetría ni servicios de terceros.

## Qué hace
1. **Enviar para aceptación**: botón en el pie del presupuesto (abierto/editable). Genera un token aleatorio de 256 bits (solo se guarda su SHA-256), válido para ese único documento, y envía por correo el enlace + el PDF. Caducidad: ajuste `presupuestosaceptacion.dias` (30 por defecto, 1–365). Un reenvío anula los enlaces anteriores sin resolver.
2. **Página pública** `/AceptarPresupuesto?t=TOKEN` (sin login, mobile-first, estética Facturalo): líneas, importes y condiciones; **Aceptar** (nombre + casilla de conformidad) o **Rechazar** (motivo opcional). Límite de 30 peticiones/10 min por IP, CSRF en los POST, `noindex`, `no-store`, `Referrer-Policy: no-referrer`, formulario con doble clic bloqueado y cierre transaccional (`SELECT … FOR UPDATE`): la acción final se ejecuta **una sola vez**.
3. **Evidencia** (no es firma electrónica cualificada, y la página lo explica): fecha-hora, IP, user-agent, nombre y SHA-256 del contenido del presupuesto en ese momento. Se guarda en `presupuestos_aceptacion`.
4. **Al aceptar**: el presupuesto pasa al estado «Aceptado» (se crea al activar el plugin), aviso por email al emisor y aparecen en el presupuesto los botones **Convertir en factura / pedido / albarán** (solo los destinos con estado activo que generen ese documento). La conversión usa el flujo estándar (cambio de `idestado` con `generadoc`), así que respeta la protección de fecha de Facturalo y VERI*FACTU del modo en que esté la instalación; no hay SQL a mano. **Conversión automática**: ajuste `presupuestosaceptacion.autoconvertir` = `factura|pedido|albaran`; vacío (desactivado) por defecto.
5. **Edición posterior**: si el presupuesto cambia tras el envío, su huella ya no coincide y el enlace queda anulado (la página lo indica y se persiste al primer intento).
6. **Embudo** en el listado de presupuestos: filtro «Aceptación online» con enviado / visto / aceptado / rechazado / caducado y su recuento.

## Módulo comercial
`ModuloActivo::estaActivo('aceptacion')` lee el ajuste `facturalo.modulos.aceptacion` (`1`/`0`). Sin ajuste: activo en pruebas; con `FACTURALO_MODULOS_ESTRICTO=1` (producción) inactivo hasta activarlo. Nota para el coordinador: la clase vive aquí (`Lib/ModuloActivo.php`); si otro módulo ya trae una compartida, unificar.

## Textos y dependencias
Traducciones `es_ES` y `en_EN`. Requiere el plugin `Facturalo` (reutiliza su URL base y su plantilla pública). Acciones del emisor: controlador `AceptacionAccion` (POST + token del formulario, permisos de modificación y «solo mis datos»).

## Pruebas
- `Test/main/AceptacionLogicaTest.php` (sin BD): token, caducidad, huella, anulación por edición, un solo uso, límite por IP, módulo.
- `Test/main/AceptacionFlujoTest.php` (BD): evidencia, doble clic, validaciones, caducado, edición.
- Script en vivo: `aceptacion-live.sh` (carpeta de notas): `ssh ploi@host 'bash -s' < aceptacion-live.sh` (opcional `URL=…` para la página pública).

## No probado (sin PHP local)
Nada se ha ejecutado: ni `php -l`, ni tests, ni el correo real, ni el render del botón en el pie del presupuesto, ni la conversión a factura con VERI*FACTU. Pendiente en el servidor de pruebas.
