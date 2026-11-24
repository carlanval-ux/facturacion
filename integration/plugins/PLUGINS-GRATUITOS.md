# Plugins gratuitos de terceros en Facturalo (tag `fs-v2026.72-plug1`)

Plugins de la tienda de FacturaScripts, todos **gratuitos, licencia LGPL**, descargados el 2026-11-24 de
`https://facturascripts.com/DownloadBuild/<id>/stable` (enlace público de la ficha `/plugins/<nombre>`; sin sesión ni registro de instalación).
Solo se **despliegan** con la release (carpeta `Plugins/`); se **activan** por tenant (ver «Activación»). Producción (`redtelecom`) no se toca.

| Plugin | Versión | Origen (build) | min_version / PHP | Uso |
|---|---|---|---|---|
| AutoEnvioFacturas | 1.2 | build 706 | 2026 / 8.1 | Envía por email las facturas emitidas (respeta Verifactu) |
| PersonalizarEmail | 1.1 | build 684 · github antongomez/PersonalizarEmail | 2026 | Logo/colores/pie del email |
| PdfFileNamer | 5.3 | build 539 · github erseco/facturascripts-plugin-PdfFileNamer | 2025.43 / 8.1 | Nombre de los PDF |
| FacturaZip | 1.1 | build 818 | 2025 | ZIP de facturas seleccionadas |
| Informes | 4.4 | build 175 · github FacturaScripts/informes | 2026.1 | Informes y gráficos |
| MuestraAdjuntoEmail | 1.1 | build 571 | 2026.7 | Adjuntos clicables en el diálogo de email |
| FiltroEjercicioActual | 2.0 | build 819 | 2026.65 / 8.4 | Filtros guardados por usuario |
| ValidaNIF | 1.1 | build 666 · github javiermartingonzalez/facturascripts-plugin-validaNIF | 2026 / 8.1 (+soap, openssl, dom) | Valida NIF con la AEAT (necesita certificado) |
| VisorErrores | 1.6 | build 469 · github elguitarraverde/VisorErrores | 2025.6 | **Solo staff** |
| MisInstalaciones | 1 | build 829 | 2026.5 | **Solo staff** |

Todos compatibles con FS 2026.72 y PHP 8.4/8.5 por su `.ini`; ninguno declara dependencias de otros plugins.
Revisión de código (grep de HTTP/curl/SOAP/eval/exec/base64, enlaces y marca): no hay telemetría ni llamadas a terceros salvo las indicadas abajo.

## Cambios sobre el original (marca blanca)

- **Informes**: borrado `Translation/updater.php` (script de desarrollo que descarga traducciones de facturascripts.com); borrado `package-lock.json`;
  `node_modules` recortado a lo que sirve la vista (`apexcharts/dist/apexcharts.min.js`, los `.css`, `LICENSE` y `package.json`; 9 MB → ~2 MB).
- **PdfFileNamer**: borrado `blueprint.json` (playground que apuntaba a GitHub).
- **VisorErrores**: el hash del error ya no enlaza a `facturascripts.com/errores/…` (ahora es texto).
- **PdfFileNamer, PersonalizarEmail, ValidaNIF**: en las traducciones, «FacturaScripts» → «Facturalo» (textos visibles al cliente).
  Los comentarios de cabecera (autor/licencia) y `namespace FacturaScripts\…` se dejan: no se muestran y la LGPL exige conservar el aviso.
- **MisInstalaciones**: sin cambios (solo staff; es un cliente REST que habla únicamente con las URLs que el staff configure).
- **Facturalo** (`Lib/Staff.php`): `visorerrores`, `listinstalacion`, `editinstalacion` se añaden a las páginas bloqueadas a clientes (menú y acceso),
  y la API `/api/3/visorerrores/*` exige clave de staff igual que `/api/3/plugins`.

Llamadas externas que quedan (aceptadas): **ValidaNIF** → servicios SOAP de la AEAT (esa es su función; con certificado de la instalación).

## ValidaNIF (solo documentado; no se activa con certificado real)

Requiere un certificado `.p12/.pfx` válido **por instalación** (el del propio autónomo/empresa). Pasos: activar el plugin → *Administrador > ValidaNIF*
→ subir el certificado y su clave → «prueba de conexión» → validar desde la ficha de cliente/proveedor. Cada tenant sube el suyo
(igual que VERI*FACTU); no compartir certificados entre tenants. Recomendado dejarlo desactivado hasta que un cliente lo pida.

## Activación

- `deploy/fs-tpv/fs-bootstrap-tenant.sh` activa `TelecoBridge Facturalo` y, si se define `FS_EXTRA_PLUGINS="..."`, esos plugins extra (solo ese tenant).
- Orden recomendado: `PersonalizarEmail PdfFileNamer FacturaZip MuestraAdjuntoEmail FiltroEjercicioActual AutoEnvioFacturas Informes`;
  plataforma: `VisorErrores MisInstalaciones` (invisibles a no-staff gracias a `Staff`).
- Tenants ya instalados: activar desde el panel de staff (AdminPlugins) o con el script de prueba de las notas.
