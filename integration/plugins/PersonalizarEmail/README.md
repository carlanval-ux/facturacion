# PersonalizarEmail

<p align="center">
  <img src="https://img.shields.io/badge/FacturaScripts-2026+-2670c9?style=for-the-badge&logoColor=white" alt="FacturaScripts 2026+">
  <img src="https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/github/license/antongomez/PersonalizarEmail?style=for-the-badge" alt="License">
  <a href="https://github.com/antongomez/PersonalizarEmail/releases/latest"><img src="https://img.shields.io/github/v/release/antongomez/PersonalizarEmail?style=for-the-badge&logo=github&logoColor=white" alt="Latest Release"></a>
</p>

Plugin para [FacturaScripts](https://facturascripts.com) que permite previsualizar y personalizar
el formato de los emails que envía el programa (facturas, presupuestos, albaranes, pedidos y
cualquier otra notificación), sin tocar el código del core.

## Características

### Vista previa

- Nueva pestaña **Vista previa** en _Panel de control → Email_, con selector para ver cualquier
  notificación renderizada con datos de ejemplo.
- La misma pestaña aparece al editar una notificación concreta (_EditEmailNotification_).
- La vista previa incluye los **cambios sin guardar** del formulario (asunto, cuerpo y todos los
  ajustes de estilo), para probar antes de guardar.
- Botón **Enviar prueba**: envía la vista previa como email real a la dirección indicada
  (prellenada con la del usuario conectado), con el asunto prefijado `[TEST]`.

### Personalización (pestaña en _Panel de control → Email_)

- **Cabecera**: título personalizado (por defecto, el nombre de la empresa), tipo de letra,
  tamaño y color. Casilla para ocultar el título por completo.
- **Logo**: posición vertical (antes del título, después del título, entre el cuerpo y el pie,
  abajo del todo, o no mostrar) y alineación horizontal (izquierda, centro, derecha).
  El logo se elige en la pestaña Email del core (campo Logo).
- **Cuerpo**: tipo de letra, tamaño y color del texto, más color de fondo, del contenedor
  y de los botones.
- **Pie**: texto adicional, tipo de letra, color y tamaño.
- Los tipos de letra se limitan a familias seguras para clientes de correo
  (Arial, Verdana, Tahoma, Trebuchet MS, Georgia, Times New Roman, Courier New).

### Logo incrustado

- Opción **Incrustar el logo**: adjunta el logo dentro del propio mensaje (imagen CID) en lugar
  de enlazarlo a la URL de la instancia. Imprescindible si FacturaScripts no es accesible desde
  internet, ya que de lo contrario los destinatarios verían la imagen rota.
- En la vista previa en pantalla el logo se muestra siempre (como data URI), sea cual sea
  esta opción.

## Requisitos

- FacturaScripts 2026 o superior.

## Instalación

1. Copiar la carpeta `PersonalizarEmail` en el directorio `Plugins/` de FacturaScripts.
2. Activar el plugin desde _Administrador → Plugins_ (o rehacer el despliegue de Dinamic).

## Notas de diseño

- Sin configuración guardada, los emails son **idénticos a los del core**: todos los valores
  por defecto reproducen la plantilla original.
- No se modifica ningún archivo de `Core/` ni `Dinamic/`. La plantilla se sobrescribe vía
  `View/Email/NewTemplate.html.twig` y el renderizado del logo vía la herencia de
  `Lib/Email/NewMail.php`, ambos mecanismos estándar de plugins.
- Los ajustes se guardan en el grupo de configuración `emailstyle`.
- El plugin es independiente y compatible con otros plugins de email (por ejemplo,
  EnviarFacturaEmailApi): no declara dependencias y respeta la cadena de clases de Dinamic.

## Tests

Copiar la carpeta `Test/` del plugin en `Test/Plugins/PersonalizarEmail/` y ejecutar:

```bash
vendor/bin/phpunit -c phpunit-plugins.xml
```

## Licencia

Este plugin se distribuye bajo la licencia
[GNU Lesser General Public License v3 (LGPL-3.0)](LICENSE), la misma que FacturaScripts.

Copyright (C) 2026 Anton Gomez
