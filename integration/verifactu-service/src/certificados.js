// VERIFACTU-FACTURALO: gestión de certificados digitales POR CLIENTE para la remisión de registros VERI*FACTU (directriz del OWNER, 19/11/2026).
// - El PKCS#12 y su contraseña se guardan CIFRADOS (AES-256-GCM, sobre por certificado: DEK aleatoria cifrada con la clave maestra CERT_MASTER_KEY) y ligados
//   al certificado y a la empresa (AAD). Nunca salen por API, log, error ni pantalla: las funciones públicas devuelven solo metadatos.
// - Se inspecciona con la CLI de openssl (contraseña por variable de entorno del proceso hijo, nunca en argv ni en disco; el PKCS#12 en un directorio temporal 0700
//   que se borra siempre). La clave privada no sale de la memoria de este módulo.
// - Verificación (directriz 7): vigencia, usos permitidos, titular y NIF, correspondencia con la empresa emisora y, para representantes o colaboradores sociales,
//   apoderamiento declarado. El apoderamiento real solo lo puede confirmar la AEAT: aquí queda como declaración y aviso «NO verificable sin conexión».
// - TEST solo admite certificados de PRUEBA (emisor o titular marcado «prueba»/«test»); PROD solo cualificados y nunca de prueba (directriz 3).
import { createCipheriv, createDecipheriv, randomBytes, X509Certificate } from 'node:crypto';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

export const TIPOS = ['sello_empresa', 'representante', 'colaborador_social'];
export class ErrorCertificado extends Error {
  constructor(mensaje, codigo) { super(mensaje); this.name = 'ErrorCertificado'; this.codigo = codigo; }
}

// ── Cifrado en reposo.
export function claveMaestra(env = process.env) {
  const k = Buffer.from(String(env.CERT_MASTER_KEY ?? ''), 'base64');
  if (k.length !== 32) throw new ErrorCertificado('CERT_MASTER_KEY ausente o no válida (32 bytes en base64)', 'sin_clave_maestra');
  return k;
}
const sellar = (clave, datos, aad) => {
  const iv = randomBytes(12), c = createCipheriv('aes-256-gcm', clave, iv);
  c.setAAD(Buffer.from(aad));
  const ct = Buffer.concat([c.update(datos), c.final()]);
  return Buffer.concat([iv, c.getAuthTag(), ct]);
};
const abrir = (clave, sobre, aad) => {
  const d = createDecipheriv('aes-256-gcm', clave, sobre.subarray(0, 12));
  d.setAAD(Buffer.from(aad)); d.setAuthTag(sobre.subarray(12, 28));
  return Buffer.concat([d.update(sobre.subarray(28)), d.final()]);
};
// Devuelve los tres sobres a guardar. `aad` = id del certificado + empresa: un sobre copiado a otra fila o empresa no descifra.
export function cifrarSecretos({ p12, passphrase, aad, kek }) {
  const dek = randomBytes(32);
  return { p12_cifrado: sellar(dek, p12, aad), pass_cifrada: sellar(dek, Buffer.from(passphrase, 'utf8'), aad), dek_cifrada: sellar(kek, dek, aad) };
}
export function descifrarSecretos({ p12_cifrado, pass_cifrada, dek_cifrada, aad, kek }) {
  const dek = abrir(kek, dek_cifrada, aad);
  return { p12: abrir(dek, p12_cifrado, aad), passphrase: abrir(dek, pass_cifrada, aad).toString('utf8') };
}

// ── Inspección con openssl.
const openssl = (args, { env, entrada } = {}) => new Promise((resolve, reject) => {
  const h = spawn('openssl', args, { env: { PATH: process.env.PATH, SYSTEMROOT: process.env.SYSTEMROOT, ...env }, windowsHide: true, timeout: 15_000 });
  let out = '';
  h.stdout.on('data', (d) => { if (out.length < (1 << 20)) out += d; });
  h.stderr.resume(); // el error de openssl puede aludir al material: no se conserva
  h.on('error', () => reject(new ErrorCertificado('openssl no disponible', 'sin_openssl')));
  h.on('close', (code) => (code === 0 ? resolve(out) : reject(new ErrorCertificado('openssl falló', 'openssl'))));
  h.stdin.end(entrada ?? '');
});
export async function inspeccionarP12(p12, passphrase) {
  const dir = await mkdtemp(path.join(tmpdir(), 'cert-'));
  const fichero = path.join(dir, 'c.p12');
  try {
    await writeFile(fichero, p12, { mode: 0o600 });
    const env = { CERT_PW: passphrase };
    let pem, claves;
    const intentos = [[], ['-legacy']]; // PKCS#12 antiguos (RC2/3DES) necesitan el proveedor legacy de OpenSSL 3
    for (const extra of intentos) {
      try {
        pem = await openssl(['pkcs12', '-in', fichero, '-nokeys', '-clcerts', '-passin', 'env:CERT_PW', ...extra], { env });
        claves = await openssl(['pkcs12', '-in', fichero, '-nocerts', '-nodes', '-passin', 'env:CERT_PW', ...extra], { env });
        break;
      } catch (e) { if (extra.length) throw new ErrorCertificado('No se pudo abrir el PKCS#12: contraseña o formato no válidos', 'p12_invalido'); }
    }
    if (!/-----BEGIN CERTIFICATE-----/.test(pem ?? '')) throw new ErrorCertificado('El PKCS#12 no contiene certificado', 'sin_certificado');
    if (!/-----BEGIN (?:ENCRYPTED |RSA |EC )?PRIVATE KEY-----/.test(claves ?? '')) throw new ErrorCertificado('El PKCS#12 no contiene clave privada', 'sin_clave_privada');
    const x = new X509Certificate(pem);
    const texto = await openssl(['x509', '-noout', '-text'], { entrada: pem });
    const bloque = (nombre) => (texto.match(new RegExp(`X509v3 ${nombre}:[^\\n]*\\n\\s+([^\\n]+)`)) ?? [])[1] ?? '';
    const ku = bloque('Key Usage'), eku = bloque('Extended Key Usage');
    return {
      sujeto: x.subject, emisor: x.issuer, serie: x.serialNumber, huella_sha256: x.fingerprint256.replace(/:/g, '').toLowerCase(),
      valido_desde: new Date(x.validFrom), valido_hasta: new Date(x.validTo), autofirmado: x.subject === x.issuer,
      firma_digital: ku === '' ? null : /Digital Signature/.test(ku), autenticacion_cliente: eku === '' ? null : /TLS Web Client Authentication/.test(eku),
      cualificado: x.raw.includes(Buffer.from('0604008e460101', 'hex')), // OID 0.4.0.1862.1.1 (QcCompliance, ETSI EN 319 412-5)
      nif_titular: extraerNif(x.subject),
    };
  } finally { await rm(dir, { recursive: true, force: true }); }
}

// NIF/CIF del titular: «serialNumber=IDCES-…», «organizationIdentifier=VATES-…» o un NIF en el CN (certificados españoles).
export function extraerNif(sujeto) {
  const NIF = /(?:IDCES-|VATES-|ES)?([A-Z]\d{7}[0-9A-Z]|\d{8}[A-Z]|[XYZ]\d{7}[A-Z])\b/;
  for (const clave of ['organizationIdentifier', '2.5.4.97', 'serialNumber', 'CN']) {
    const m = new RegExp(`(?:^|\\n|,)\\s*${clave.replace('.', '\\.')}=([^\\n,]+)`, 'i').exec(sujeto);
    const n = m && NIF.exec(m[1].toUpperCase());
    if (n) return n[1];
  }
  return null;
}
const nifNorm = (v) => String(v ?? '').toUpperCase().replace(/^(ES|VATES-|IDCES-)/, '').replace(/[^0-9A-Z]/g, '');
const esDePrueba = (info) => /\b(prueba|pruebas|test)\b/i.test(`${info.emisor}\n${info.sujeto}`);

// Verifica un certificado frente a la empresa emisora y el entorno. {ok, problemas, avisos}; nunca lanza por contenido.
export function verificarCertificado(info, { empresa, tipo, entorno, apoderamiento = null, ahora = new Date() }) {
  const problemas = [], avisos = [];
  if (!TIPOS.includes(tipo)) problemas.push('Tipo de certificado no válido');
  if (ahora < info.valido_desde) problemas.push('El certificado aún no es válido');
  if (ahora > info.valido_hasta) problemas.push('El certificado ha caducado');
  else if (info.valido_hasta - ahora < 30 * 864e5) avisos.push('El certificado caduca en menos de 30 días');
  if (info.firma_digital === false) problemas.push('El certificado no permite firma digital');
  if (info.autenticacion_cliente === false) problemas.push('El certificado no permite autenticación de cliente TLS');
  if (!info.nif_titular) problemas.push('No se pudo identificar el NIF del titular');
  const titular = nifNorm(info.nif_titular), emisora = nifNorm(empresa.nif);
  if (tipo === 'sello_empresa') {
    if (titular && titular !== emisora) problemas.push('El titular del certificado no es la empresa emisora');
  } else if (tipo === 'representante' || tipo === 'colaborador_social') {
    if (!apoderamiento?.referencia || !apoderamiento?.nif_representado) problemas.push('Un certificado de representante o colaborador social exige apoderamiento (referencia y NIF representado)');
    else {
      if (nifNorm(apoderamiento.nif_representado) !== emisora) problemas.push('El apoderamiento no corresponde a la empresa emisora');
      if (apoderamiento.hasta && new Date(apoderamiento.hasta) < ahora) problemas.push('El apoderamiento ha caducado');
      avisos.push('El apoderamiento solo lo puede confirmar la AEAT: NO verificable sin conexión');
    }
    if (titular && titular === emisora) avisos.push('El titular coincide con la empresa: considera el tipo «sello de empresa»');
  }
  if (entorno === 'test') {
    if (!esDePrueba(info)) problemas.push('TEST solo admite certificados de PRUEBA (emisor o titular marcado «prueba» o «test»)');
  } else if (entorno === 'prod') {
    if (esDePrueba(info)) problemas.push('Un certificado de prueba no sirve en producción');
    if (info.autofirmado) problemas.push('Un certificado autofirmado no sirve en producción');
    if (!info.cualificado) problemas.push('Producción exige un certificado electrónico cualificado');
  } else problemas.push('Entorno no válido');
  return { ok: problemas.length === 0, problemas, avisos };
}

// Lo único que puede salir de este módulo hacia la API o la pantalla.
export const METADATOS = ['id', 'client_id', 'entorno', 'tipo', 'alias', 'titular_nif', 'emisor', 'serie', 'huella_sha256', 'valido_desde', 'valido_hasta', 'cualificado', 'autofirmado',
  'apoderamiento_ref', 'apoderamiento_nif', 'apoderamiento_hasta', 'estado', 'avisos', 'created_at'];
