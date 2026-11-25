// VERIFACTU-FACTURALO: registros de facturación (alta/anulación), huella SHA-256 encadenada, sobre SOAP de remisión y lectura de la respuesta de la AEAT.
// Fuentes oficiales (docs/verifactu-aeat/ENDPOINTS-Y-REQUISITOS.md): SuministroInformacion.xsd, SuministroLR.xsd, RespuestaSuministro.xsd, Veri-Factu_especificaciones_huella_hash_registros.
// SOLO PRUEBAS: construye y valida; el envío real pasa siempre por transporte.js + entorno.js. Datos ficticios.
import { createHash } from 'node:crypto';
// half-up; cent y bp enteros no negativos
export const mulBp = (cent, bp) => Math.floor((cent * bp + 5000) / 10000);

export const NS_SF = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
export const NS_LR = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
export const NS_RESP = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/RespuestaSuministro.xsd';
export const MAX_REGISTROS_ENVIO = 1000; // XSD (maxOccurs) y VAL §3.1.2

export const xmlEsc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
export const importe = (cent) => { const c = Math.abs(cent), s = `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`; return cent < 0 ? `-${s}` : s; };
// DD-MM-AAAA en Europe/Madrid.
export const fechaAEAT = (d) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric' }).format(d).replace(/\//g, '-');
// ISO 8601 con huso de Madrid (2026-11-19T12:00:00+01:00).
export function fechaHoraHuso(d) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid', hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).formatToParts(d).map((x) => [x.type, x.value]));
  const local = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
  const off = Math.round((local - Math.floor(d.getTime() / 1000) * 1000) / 60000);
  const sg = off < 0 ? '-' : '+', a = Math.abs(off);
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}${sg}${String(Math.floor(a / 60)).padStart(2, '0')}:${String(a % 60).padStart(2, '0')}`;
}

// Huella (HUE §3-5): «nombre=valor&…», UTF-8, SHA-256, hexadecimal en MAYÚSCULAS. Campo vacío → «Nombre=».
const sha = (cadena) => createHash('sha256').update(cadena, 'utf8').digest('hex').toUpperCase();
const par = (campos) => campos.map(([k, v]) => `${k}=${String(v ?? '').trim()}`).join('&');
export const huellaAlta = ({ nif, numserie, fecha, tipo, cuotaTotal, importeTotal, huellaAnterior = '', fechaHora }) => sha(par([['IDEmisorFactura', nif], ['NumSerieFactura', numserie],
  ['FechaExpedicionFactura', fecha], ['TipoFactura', tipo], ['CuotaTotal', cuotaTotal], ['ImporteTotal', importeTotal], ['Huella', huellaAnterior], ['FechaHoraHusoGenRegistro', fechaHora]]));
export const huellaAnulacion = ({ nif, numserie, fecha, huellaAnterior = '', fechaHora }) => sha(par([['IDEmisorFacturaAnulada', nif], ['NumSerieFacturaAnulada', numserie],
  ['FechaExpedicionFacturaAnulada', fecha], ['Huella', huellaAnterior], ['FechaHoraHusoGenRegistro', fechaHora]]));

const SISTEMA_PRUEBA = { nombreRazon: 'Facturalo (RED TELECOM CONECTA, S.L.)', nif: process.env.SISTEMA_NIF || 'B00000000', nombreSistema: 'FacturaloVerifactu', id: 'FV', version: '0.1', instalacion: '1' };
const sistemaXml = (s) => `<sf:SistemaInformatico><sf:NombreRazon>${xmlEsc(s.nombreRazon)}</sf:NombreRazon><sf:NIF>${xmlEsc(s.nif)}</sf:NIF><sf:NombreSistemaInformatico>${xmlEsc(s.nombreSistema)}</sf:NombreSistemaInformatico>`
  + `<sf:IdSistemaInformatico>${xmlEsc(s.id)}</sf:IdSistemaInformatico><sf:Version>${xmlEsc(s.version)}</sf:Version><sf:NumeroInstalacion>${xmlEsc(s.instalacion)}</sf:NumeroInstalacion>`
  + '<sf:TipoUsoPosibleSoloVerifactu>S</sf:TipoUsoPosibleSoloVerifactu><sf:TipoUsoPosibleMultiOT>N</sf:TipoUsoPosibleMultiOT><sf:IndicadorMultiplesOT>N</sf:IndicadorMultiplesOT></sf:SistemaInformatico>';
const encadenamientoXml = (a) => `<sf:Encadenamiento>${a
  ? `<sf:RegistroAnterior><sf:IDEmisorFactura>${xmlEsc(a.nif)}</sf:IDEmisorFactura><sf:NumSerieFactura>${xmlEsc(a.numserie)}</sf:NumSerieFactura><sf:FechaExpedicionFactura>${a.fecha}</sf:FechaExpedicionFactura><sf:Huella>${a.huella}</sf:Huella></sf:RegistroAnterior>`
  : '<sf:PrimerRegistro>S</sf:PrimerRegistro>'}</sf:Encadenamiento>`;

// Desglose por tipo de IVA desde las líneas de la factura de Teleco ({base_cent, iva_bp}). 0 % se declara como operación exenta (E1) en las pruebas.
export function desglosar(lineas) {
  const porTipo = new Map();
  for (const l of lineas) porTipo.set(l.iva_bp, (porTipo.get(l.iva_bp) ?? 0) + l.base_cent);
  const filas = [...porTipo].sort((a, b) => b[0] - a[0]).map(([bp, base]) => ({ bp, base, cuota: mulBp(base, bp) }));
  return { filas, cuotaTotal: filas.reduce((s, f) => s + f.cuota, 0), baseTotal: filas.reduce((s, f) => s + f.base, 0) };
}
const detalleXml = (f) => `<sf:DetalleDesglose><sf:Impuesto>01</sf:Impuesto><sf:ClaveRegimen>01</sf:ClaveRegimen>${f.bp === 0 ? '<sf:OperacionExenta>E1</sf:OperacionExenta>'
  : `<sf:CalificacionOperacion>S1</sf:CalificacionOperacion><sf:TipoImpositivo>${(f.bp / 100).toFixed(2)}</sf:TipoImpositivo>`}<sf:BaseImponibleOimporteNoSujeto>${importe(f.base)}</sf:BaseImponibleOimporteNoSujeto>`
  + `${f.bp === 0 ? '' : `<sf:CuotaRepercutida>${importe(f.cuota)}</sf:CuotaRepercutida>`}</sf:DetalleDesglose>`;

// Registro de alta (factura completa F1 con destinatario). anterior = {nif, numserie, fecha, huella} del registro previo del mismo emisor, o null si es el primero.
export function construirAlta({ emisor, factura, subsanacion = false, anterior = null, sistema = SISTEMA_PRUEBA, momento = new Date() }) {
  const d = desglosar(factura.lineas), tipo = factura.tipo ?? 'F1', fecha = fechaAEAT(factura.fecha), fechaHora = fechaHoraHuso(momento);
  const cuotaTotal = importe(d.cuotaTotal), importeTotal = importe(d.baseTotal + d.cuotaTotal);
  const huella = huellaAlta({ nif: emisor.nif, numserie: factura.numserie, fecha, tipo, cuotaTotal, importeTotal, huellaAnterior: anterior?.huella ?? '', fechaHora });
  const xml = `<sf:RegistroAlta><sf:IDVersion>1.0</sf:IDVersion><sf:IDFactura><sf:IDEmisorFactura>${xmlEsc(emisor.nif)}</sf:IDEmisorFactura><sf:NumSerieFactura>${xmlEsc(factura.numserie)}</sf:NumSerieFactura>`
    + `<sf:FechaExpedicionFactura>${fecha}</sf:FechaExpedicionFactura></sf:IDFactura><sf:NombreRazonEmisor>${xmlEsc(emisor.nombre)}</sf:NombreRazonEmisor>${subsanacion ? '<sf:Subsanacion>S</sf:Subsanacion><sf:RechazoPrevio>S</sf:RechazoPrevio>' : ''}<sf:TipoFactura>${tipo}</sf:TipoFactura>`
    + `<sf:DescripcionOperacion>${xmlEsc(factura.descripcion ?? 'Prestación de servicios')}</sf:DescripcionOperacion>${factura.destinatario ? `<sf:Destinatarios><sf:IDDestinatario><sf:NombreRazon>${xmlEsc(factura.destinatario.nombre)}</sf:NombreRazon><sf:NIF>${xmlEsc(factura.destinatario.nif)}</sf:NIF></sf:IDDestinatario></sf:Destinatarios>` : ''}<sf:Desglose>${d.filas.map(detalleXml).join('')}</sf:Desglose><sf:CuotaTotal>${cuotaTotal}</sf:CuotaTotal><sf:ImporteTotal>${importeTotal}</sf:ImporteTotal>`
    + `${encadenamientoXml(anterior)}${sistemaXml(sistema)}<sf:FechaHoraHusoGenRegistro>${fechaHora}</sf:FechaHoraHusoGenRegistro><sf:TipoHuella>01</sf:TipoHuella><sf:Huella>${huella}</sf:Huella></sf:RegistroAlta>`;
  return { xml, huella, tipo: 'alta', fechaHora, cuotaTotal, importeTotal, fecha };
}
export function construirAnulacion({ emisor, factura, anterior = null, sistema = SISTEMA_PRUEBA, momento = new Date() }) {
  const fecha = fechaAEAT(factura.fecha), fechaHora = fechaHoraHuso(momento);
  const huella = huellaAnulacion({ nif: emisor.nif, numserie: factura.numserie, fecha, huellaAnterior: anterior?.huella ?? '', fechaHora });
  const xml = `<sf:RegistroAnulacion><sf:IDVersion>1.0</sf:IDVersion><sf:IDFactura><sf:IDEmisorFacturaAnulada>${xmlEsc(emisor.nif)}</sf:IDEmisorFacturaAnulada><sf:NumSerieFacturaAnulada>${xmlEsc(factura.numserie)}</sf:NumSerieFacturaAnulada>`
    + `<sf:FechaExpedicionFacturaAnulada>${fecha}</sf:FechaExpedicionFacturaAnulada></sf:IDFactura>${encadenamientoXml(anterior)}${sistemaXml(sistema)}<sf:FechaHoraHusoGenRegistro>${fechaHora}</sf:FechaHoraHusoGenRegistro>`
    + `<sf:TipoHuella>01</sf:TipoHuella><sf:Huella>${huella}</sf:Huella></sf:RegistroAnulacion>`;
  return { xml, huella, tipo: 'anulacion', fechaHora, fecha };
}

// Sobre SOAP 1.1 (document/literal) para RegFactuSistemaFacturacion. `representante` solo si el remitente es un colaborador social o apoderado.
export function construirRegFactu({ obligado, registros, representante = null }) {
  if (!registros.length || registros.length > MAX_REGISTROS_ENVIO) throw new Error(`Un envío admite entre 1 y ${MAX_REGISTROS_ENVIO} registros`);
  const pf = (x) => `<sf:NombreRazon>${xmlEsc(x.nombre)}</sf:NombreRazon><sf:NIF>${xmlEsc(x.nif)}</sf:NIF>`;
  return `<sfLR:RegFactuSistemaFacturacion xmlns:sfLR="${NS_LR}" xmlns:sf="${NS_SF}"><sfLR:Cabecera><sf:ObligadoEmision>${pf(obligado)}</sf:ObligadoEmision>${representante ? `<sf:Representante>${pf(representante)}</sf:Representante>` : ''}</sfLR:Cabecera>`
    + `${registros.map((r) => `<sfLR:RegistroFactura>${r}</sfLR:RegistroFactura>`).join('')}</sfLR:RegFactuSistemaFacturacion>`;
}
export const construirEnvio = (datos) => `<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header/><soapenv:Body>${construirRegFactu(datos)}</soapenv:Body></soapenv:Envelope>`;

// Lectura de la respuesta (RespuestaSuministro.xsd) o de un Fault SOAP. Sin DTD ni entidades: se rechaza cualquier DOCTYPE.
const trozo = (xml, tag) => { const m = new RegExp(`<(?:[\\w-]+:)?${tag}>([\\s\\S]*?)</(?:[\\w-]+:)?${tag}>`).exec(xml); return m ? m[1].trim() : null; };
export function leerRespuesta(xml) {
  if (/<!DOCTYPE|<!ENTITY/i.test(xml)) throw new Error('Respuesta no válida: contiene DOCTYPE o entidades');
  if (/<(?:[\w-]+:)?Fault[\s>]/.test(xml)) return { fault: true, codigo: trozo(xml, 'faultcode'), mensaje: trozo(xml, 'faultstring') };
  const estadoEnvio = trozo(xml, 'EstadoEnvio');
  if (!estadoEnvio) throw new Error('Respuesta no reconocida: falta EstadoEnvio');
  const lineas = [...xml.matchAll(/<(?:[\w-]+:)?RespuestaLinea>([\s\S]*?)<\/(?:[\w-]+:)?RespuestaLinea>/g)].map((m) => ({ numserie: trozo(m[1], 'NumSerieFactura'),
    estado: trozo(m[1], 'EstadoRegistro'), codigo: trozo(m[1], 'CodigoErrorRegistro'), descripcion: trozo(m[1], 'DescripcionErrorRegistro') }));
  return { fault: false, estadoEnvio, csv: trozo(xml, 'CSV'), tiempoEspera: Number(trozo(xml, 'TiempoEsperaEnvio')) || 60, lineas };
}
