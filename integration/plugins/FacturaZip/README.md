# FacturaZip

Plugin para FacturaScripts que añade el botón **Descargar seleccionadas en ZIP** al listado de facturas de venta.

## Instalación

1. En FacturaScripts, abre **Administrador > Plugins**.
2. Pulsa **Añadir plugin** y sube `FacturaZip.zip`.
3. Instálalo y actívalo.

## Uso

1. Abre **Ventas > Facturas**.
2. Marca una o varias facturas.
3. Pulsa **Descargar seleccionadas en ZIP**.

El archivo contiene un PDF independiente por factura. El plugin usa `ExportManager`, la interfaz estándar de FacturaScripts, por lo que emplea el exportador PDF que esté activo en la instalación.
