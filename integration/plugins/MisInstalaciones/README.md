# MisInstalaciones

Panel para controlar de forma remota otras instalaciones de FacturaScripts de tu propiedad, usando solo su API REST del core. No hace falta instalar ningún plugin en las instalaciones remotas.

## Requisitos de la instalación remota

- FacturaScripts 2026.4 o superior para mostrar la versión del core. Si no está disponible, el panel muestra `---`.
- API REST activada.
- Una API key dedicada con acceso a `users`, `roles`, `roleusers` y `plugins`, o con acceso completo.
- Opcional: plugin **VisorErrores 1.6 o superior** activado, con acceso de la API key a `visorerrores` (GET y, para borrar errores, DELETE). Solo con esa versión aparecen las pestañas **Visor de Errores** y **Logs de base de datos**; con versiones anteriores o sin el plugin, no se muestran.

## Uso

- Alta de instalaciones en el listado: nombre, URL, API key y observaciones.
- La URL debe empezar por `https://`.
- El botón **Test de conexión** aparece cuando la URL y la API key son válidas. Prueba los datos guardados.
- Las pestañas **Plugins**, **Acerca de** y **Usuarios** muestran lo que expone la API. Los interruptores activan y desactivan plugins.
- La ficha se abre sin esperar a la instalación remota: cada pestaña remota se carga por AJAX al abrirla y las acciones (test de conexión, activar plugins, borrar errores) se notifican con avisos emergentes.
- La API key solo se envía al navegador en el campo del formulario (igual que el core); no aparece en las pestañas remotas, en las respuestas AJAX ni en exportaciones.
- Con VisorErrores 1.6+ en la remota: **Visor de Errores** lista los errores críticos y permite borrar uno o todos; **Logs de base de datos** muestra los últimos errores del canal `database`.

## Configuración de desarrollo

Solo en desarrollo se puede permitir `http://`. Añade en `config.php`:

```php
define('FS_MISINSTALACIONES_PERMITIR_HTTP', true);
```

Sin esta constante, o con un valor distinto de `true`, solo se aceptan URLs `https://`.

## Limitaciones de esta versión

- No incluye la reconstrucción de plugins.
- La API key se guarda sin cifrar, igual que en el core.
- No comprueba actualizaciones del core ni de los plugins remotos.
