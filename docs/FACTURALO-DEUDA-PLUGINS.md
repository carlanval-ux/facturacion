# Deuda de desarrollo: funciones propias a construir, derivadas del análisis de plugins del ecosistema, clean-room

# Deuda de desarrollo: plugins del ecosistema para Facturalo

Método: revisados los 259 del catálogo (52 de pago + 207 de lista gratuita) por su descripción pública. Todo se desarrolla clean-room, desde la descripción, sin leer código ni copiar textos. No se abrieron páginas: la descripción bastó.
Recuento: 52 de pago, de los cuales 36 son de sector ajeno (*Gest, Taxi, Dental, Agri, etc.) y se descartan; 16 aportan idea. De los 207 gratuitos se descartan unos 150 (sector, temas, TPV, producción, stock, logística, localizaciones LATAM, utilidades de admin, módulos que cubre el core, el motor propio o Clarto). Quedan unos 40 candidatos.

## 1. Tabla priorizada

| Prio | Función propia | Plugin ref. | Valor concreto | Esf. | Dependencias / riesgos |
|---|---|---|---|---|---|
| P1 | Recordatorio de impagos automático (3 pasos: antes, día, después) | RecordatorioImpagos, GestionCobros | El autónomo cobra antes sin perseguir. Es lo que más ve el cliente | M | Correo propio; texto sobre deuda: tono y RGPD |
| P1 | Cobro por enlace en la factura (Redsys/Bizum o Stripe) | MaxRedsys, TpvRedsys, ImportadorStripe | "Pagar ahora" en el email y el PDF | L | **Pasarela = gasto y contrato por cliente**; PCI vía redirección; conciliar recibo |
| P1 | Remesas SEPA (cobro domiciliado) | RemesasSEPA, IeSepaMandato | Cobro recurrente sin esfuerzo; encaja con recurrentes | M | Datos bancarios (RGPD); mandato firmado; B2B/CORE |
| P1 | Cobro rápido + pagos múltiples | PagoSimplificado, PagosMultiples, FechaPagado | Marcar cobrado en 1 clic o por lote | S | Sin riesgo; no tocar facturas ya registradas en VERI*FACTU |
| P1 | Paquete trimestral para la gestoría (303, 111, 115, 130, 347, 349) en Excel/PDF | Modelo303/111/115/130/347/349 | Gestoría y cliente ven las cifras sin teclear. **No presentar**: solo cuadrar | L | Normativa cambiante; presentar ante AEAT queda fuera; revisar con Clarto |
| P1 | Exportación masiva de facturas (ZIP + índice) | ExportInvoicesZIP, FacturaZip | Entregar el trimestre a la gestoría en 1 clic | S | Datos personales: enlace caducado y con acceso |
| P1 | Presupuesto a factura en 1 clic y con aceptación online | MaxSign, FirmarDocumentos, PortalCliente | Cierra ventas más rápido. Aceptar = botón en enlace, no firma cualificada | M | Evidencia (IP, fecha); no ofrecer firma avanzada |
| P1 | Anticipos y cobros a cuenta | Anticipos, zAnticipos, PrePagos | Señal y factura final sin descuadres | M | Interacción con VERI*FACTU (factura de anticipo, rectificativas) |
| P2 | Panel de cobros/vencimientos y previsión de tesorería | PrevisionPagos, VencimientosRecibos, PanelGerencial | "Cuánto cobro y pago este mes" | M | Solo lectura |
| P2 | Cuadro de mandos simple (ventas, gastos, IVA estimado, top clientes) | Informes, InformesEstadisticos, DashboardRentabilidad | Visión en 5 s para el autónomo | M | Sin riesgo |
| P2 | Importar factura de proveedor con IA/OCR | AiScan, AIDocumentos, EscanearDocumentos | Gestoría recibe gastos ya capturados | L | **Datos personales a terceros (DPA)**; coste por llamada = gasto |
| P2 | Portal de cliente final (ver/pagar/descargar) | PortalCliente | Autoservicio: menos llamadas | L | Accesos y RGPD |
| P2 | Criterio de caja (RECC) y textos de exención | CriterioCaja, ExcepcionesIVA | Evita errores de IVA típicos | M | Impacta en motor de impuestos y VERI*FACTU |
| P2 | Rectificativas guiadas y "duplicar documentos" | IeDuplicaDocumentos | Menos errores y tiempo | S | Rectificativa debe respetar VERI*FACTU |
| P2 | Conciliación bancaria (Norma 43 / CSV) | ConciliacionBancaria | Cobros marcados solos | L | Datos bancarios; formatos por banco |
| P2 | Campos y textos reutilizables en facturas | EasyFields, ExtraDataFactura, Textos, Comentarioprivado | Pedido nº, observaciones fijas | S | Sin riesgo |
| P2 | Pagos fraccionados (plazos de pago) | PlazosPago | Vencimientos 30/60/90 | S | Sin riesgo |
| P2 | Comisiones de agentes | Comisiones, DobleAgente | Pymes con comerciales | M | Nicho |
| P2 | Moneda extranjera | Multidivisa | Clientes UE/USD | M | Impacta en VERI*FACTU e informes |
| P2 | Factura electrónica B2B/Facturae/FACe | Facturae, Elefact | Facturar a administración pública | M | Formato oficial; lo cubre el motor propio si procede |
| P2 | Envío por WhatsApp (enlace al PDF) | WhatsappDirecto, WhatsappWidget | Canal preferido del autónomo | S | Solo `wa.me`; sin API de pago |
| P2 | Alertas/avisos internos | Notificaciones, Avisos | Vencidas, fallos de envío | S | Sin riesgo |
| P2 | Clientes y catálogo: importar CSV y búsqueda por nombre comercial | CSVimport, BuscarPorNombreComercial | Alta rápida en onboarding | S | Datos personales: validar y limitar |
| P3 | Contratos/renovaciones de servicios | Contratos, ServiceRenewals | Aviso de renovaciones | M | Solape con recurrentes |
| P3 | Suscripciones con configurador | SubscriptionConfigurator | Venta de planes | L | Pasarela = gasto |
| P3 | Gastos de empleados y control horario | RegistroHorario, HumanResources, ControlTiempo | Obligación laboral | L | Datos laborales; fuera del foco |
| P3 | Partes de trabajo/proyectos/tiempos | Proyectos, PartesDeTrabajo, FacturarDias | Facturar horas de servicios | M | Nicho |
| P3 | Amortizaciones y asientos | Amortizaciones, AsientosPredefinidos | Contabilidad (gestoría) | L | Es terreno Clarto |
| P3 | CRM ligero y calendario | CRM, MaxCRM, Calendario | Seguimiento | L | Solape con gestoría |
| P3 | Comparar precios de compra | HistoricoCompras | Control de costes | S | Nicho |
| P3 | Informes de intercambios (Intrastat, SII, Modelo 369) | Intrastat, InformeSII, Modelo369 | Grandes volúmenes/UE | L | Nicho; fuera del foco |

## 2. Agrupación por temas
- **Cobros y tesorería:** recordatorio de impagos, enlace de pago, remesas SEPA, cobro rápido/múltiple, anticipos, plazos, previsión, conciliación.
- **Ventas:** presupuesto a factura y aceptación online, duplicar, campos extra, comisiones, multidivisa.
- **Compras/proveedores:** OCR de facturas de proveedor, histórico de precios.
- **Comunicación y marca:** WhatsApp, avisos, textos reutilizables, portal cliente.
- **Informes y fiscalidad:** paquete trimestral, ZIP de facturas, panel, criterio de caja, exenciones.
- **Seguridad/plataforma:** registro de accesos y IP segura (valorar auditoría propia del SaaS), Google login (P3).
- **Productividad:** importación CSV, búsqueda, alertas.

## 3. Ya en marcha (no reabrir)
Fecha de factura protegida (FechaFacturaShield, FacturasCompraUniq), recurrentes (DocumentosRecurrentes, IeAyudaFacturacion), envío automático (AutoEnvioFacturas, EnviarDocumentos), nombre de PDF (PdfFileNamer, NombreClienteDocsPDF), VERI*FACTU (Verifactu, Template1_ext), plantillas PDF y marca (PlantillasPDF, FacturaPDF1, PersonalizarEmail, LoginScreen), traducciones, ValidaNIF, copias de seguridad (a nivel de plataforma), multiempresa, enlace con Clarto.

## 4. Comprar vs desarrollar
- **Pasarela de pago:** desarrollar solo la capa de enlace; usar el proveedor (Redsys/Stripe) como servicio. No tiene sentido fabricar la pasarela.
- **OCR/IA de proveedor:** comprar/contratar el motor de IA y desarrollar solo la integración; no entrenar modelo propio.
- No hay otro caso claro: los modelos fiscales y recordatorios son más baratos de hacer que de licenciar para marca blanca, y las licencias de pago no garantizan reventa en SaaS.

## 5. Qué desarrollar primero (top 10)
1. Cobro rápido y pagos múltiples (S, desbloquea todo lo demás).
2. Recordatorio de impagos automático.
3. Exportación masiva de facturas en ZIP para la gestoría.
4. Remesas SEPA (junto a recurrentes).
5. Paquete trimestral de cifras (303, 111, 130, 347) para la gestoría.
6. Panel de cobros/vencimientos y previsión.
7. Presupuesto a factura con aceptación online.
8. Anticipos y cobros a cuenta.
9. Enlace de pago (Redsys/Stripe) en email y PDF.
10. Campos/textos reutilizables y plazos de pago (quick wins de factura).

Nota: P2 posteriores sugeridos: criterio de caja y exenciones de IVA, OCR de proveedores, portal de cliente.
