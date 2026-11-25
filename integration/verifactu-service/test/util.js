// Utilidades de prueba: certificados de PRUEBA generados con openssl (nunca reales), simulado local de la AEAT y cliente HTTP.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { randomBytes } from 'node:crypto';
import { crearApp } from '../src/app.js';
import { abrirDb } from '../src/store.js';

export const PASS = 'clave-de-prueba-1';
export const NIF_A = 'B12345674', NIF_B = 'A58818501';

// opciones: { nif, cn, desde, hasta, ku, eku } — por defecto un sello de PRUEBA vigente 30 días.
export function generarP12({ nif = NIF_A, cn = 'Empresa Prueba SL', desde, hasta, ku = 'digitalSignature', eku = 'clientAuth', pass = PASS } = {}) {
  const dir = mkdtempSync(path.join(tmpdir(), 'vf-test-'));
  try {
    const f = (n) => path.join(dir, n);
    const fechas = desde && hasta ? ['-not_before', desde, '-not_after', hasta] : ['-days', '30'];
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', f('k.pem'), '-out', f('c.pem'), '-subj', `/serialNumber=IDCES-${nif}/CN=${cn}`,
      ...fechas, '-addext', `keyUsage=${ku}`, '-addext', `extendedKeyUsage=${eku}`], { stdio: 'ignore' });
    execFileSync('openssl', ['pkcs12', '-export', '-inkey', f('k.pem'), '-in', f('c.pem'), '-out', f('c.p12'), '-passout', `pass:${pass}`], { stdio: 'ignore' });
    return readFileSync(f('c.p12'));
  } finally { rmSync(dir, { recursive: true, force: true }); }
}

// Certificado «real» FICTICIO para probar el camino de modo real: firmado por una CA local (no autofirmado), sin la palabra prueba/test y con el OID de QcCompliance (0.4.0.1862.1.1)
// dentro de una extensión cualquiera: es el marcador que busca inspeccionarP12 para considerarlo cualificado. Nunca es un certificado de la FNMT ni válido ante la AEAT.
export function generarP12Real({ nif = NIF_A, cn = 'Empresa Real SL', pass = PASS } = {}) {
  const dir = mkdtempSync(path.join(tmpdir(), 'vf-real-'));
  try {
    const f = (n) => path.join(dir, n), sh = (a) => execFileSync('openssl', a, { stdio: 'ignore' });
    sh(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', f('ca.key'), '-out', f('ca.pem'), '-subj', '/CN=CA Ficticia Real', '-days', '60']);
    sh(['req', '-newkey', 'rsa:2048', '-nodes', '-keyout', f('k.pem'), '-out', f('c.csr'), '-subj', `/serialNumber=IDCES-${nif}/CN=${cn}`]);
    writeFileSync(f('ext.cnf'), 'keyUsage=digitalSignature\nextendedKeyUsage=clientAuth\n1.2.3.4=DER:06:04:00:8e:46:01:01\n');
    sh(['x509', '-req', '-in', f('c.csr'), '-CA', f('ca.pem'), '-CAkey', f('ca.key'), '-CAcreateserial', '-out', f('c.pem'), '-days', '30', '-extfile', f('ext.cnf')]);
    sh(['pkcs12', '-export', '-inkey', f('k.pem'), '-in', f('c.pem'), '-out', f('c.p12'), '-passout', `pass:${pass}`]);
    return readFileSync(f('c.p12'));
  } finally { rmSync(dir, { recursive: true, force: true }); }
}

export const RESPUESTA_OK = (csv = 'CSVPRUEBA0001') => `<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><tikR:RespuestaRegFactuSistemaFacturacion xmlns:tikR="x">`
  + `<tikR:CSV>${csv}</tikR:CSV><tikR:TiempoEsperaEnvio>60</tikR:TiempoEsperaEnvio><tikR:EstadoEnvio>Correcto</tikR:EstadoEnvio><tikR:RespuestaLinea><tikR:EstadoRegistro>Correcto</tikR:EstadoRegistro></tikR:RespuestaLinea>`
  + '</tikR:RespuestaRegFactuSistemaFacturacion></soapenv:Body></soapenv:Envelope>';

// Simulado de la AEAT en loopback: guarda los cuerpos recibidos; `modo` cambia la respuesta ('ok' | 'redirect' | 'fault').
export async function simulado() {
  const s = { recibidos: [], modo: 'ok' };
  s.server = http.createServer((req, res) => {
    const tr = []; req.on('data', (d) => tr.push(d)); req.on('end', () => {
      s.recibidos.push(Buffer.concat(tr).toString('utf8'));
      if (s.modo === 'redirect') { res.writeHead(302, { Location: 'https://www2.agenciatributaria.gob.es/' }); return res.end(); }
      res.writeHead(200, { 'Content-Type': 'text/xml' }); res.end(RESPUESTA_OK());
    });
  });
  await new Promise((r) => s.server.listen(0, '127.0.0.1', r));
  s.url = `http://127.0.0.1:${s.server.address().port}/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP`;
  s.cerrar = () => new Promise((r) => s.server.close(r));
  return s;
}

// Arranca el servicio con BD en memoria. `entorno` y `ahora` se pueden forzar.
export async function levantar({ env = {}, transporte, ahora, prodHabilitado } = {}) {
  const sim = await simulado();
  const e = { CERT_MASTER_KEY: randomBytes(32).toString('base64'), ADMIN_TOKEN: 'admin-token-de-prueba-0123456789', VERIFACTU_URL_TEST: sim.url, ...env };
  const db = abrirDb();
  const server = crearApp({ db, env: e, ...(transporte ? { transporte } : {}), ...(ahora ? { ahora } : {}), ...(prodHabilitado !== undefined ? { prodHabilitado } : {}) });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}`;
  const api = async (metodo, ruta, { token, body } = {}) => {
    const r = await fetch(base + ruta, { method: metodo, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
    const texto = await r.text();
    return { status: r.status, texto, json: texto ? JSON.parse(texto) : null };
  };
  const instalacion = async (nif, nombre = 'Empresa de prueba SL') => (await api('POST', '/admin/instalaciones', { token: e.ADMIN_TOKEN, body: { nombre: `inst-${nif}`, nif, nombre_empresa: nombre } })).json;
  const cerrar = async () => { await new Promise((r) => server.close(r)); await sim.cerrar(); };
  return { api, instalacion, sim, db, env: e, cerrar };
}

export const subirCert = (api, token, p12, extra = {}) => api('PUT', '/v1/certificado', { token, body: { tipo: 'sello_empresa', alias: 'prueba', p12_base64: p12.toString('base64'), passphrase: PASS, ...extra } });
export const factura = (n = 1, extra = {}) => ({ numserie: `A/${n}`, fecha: '2026-11-20', tipo: 'F1', descripcion: 'Servicios de prueba', destinatario: { nombre: 'Cliente de prueba SL', nif: 'B99999990' },
  lineas: [{ base_cent: 10000, iva_bp: 2100 }], ...extra });
