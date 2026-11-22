// VERIFACTU-FACTURALO: transporte SOAP sobre HTTP(S) con certificado de cliente (mTLS). Cada petición pasa antes por validarDestino (entorno.js): si el destino no está
// permitido para el entorno activo se lanza ErrorEntorno SIN abrir ningún socket. No se siguen redirecciones (un 3xx es un error). Nunca se registra el cuerpo ni el material
// del certificado.
import https from 'node:https';
import http from 'node:http';
import { validarDestino, ErrorEntorno } from './entorno.js';

export const MAX_RESPUESTA = 5 * 1024 * 1024;
export class ErrorTransporte extends Error {
  constructor(mensaje, { reintentable = false, status = 0 } = {}) { super(mensaje); this.name = 'ErrorTransporte'; this.reintentable = reintentable; this.status = status; }
}

// cert: { p12: Buffer, passphrase: string } (descifrados un instante por quien llama). Devuelve { status, cuerpo }.
export async function enviarSoap({ entorno, url, xml, soapAction = '', cert = null, timeoutMs = 30_000, motor = null }) {
  const destino = validarDestino(entorno, url); // lanza ErrorEntorno antes de cualquier conexión
  return await new Promise((resolve, reject) => {
    const cliente = destino.protocol === 'https:' ? https : http;
    const opciones = {
      method: 'POST', hostname: destino.hostname, port: destino.port || undefined, path: `${destino.pathname}${destino.search}`, timeout: timeoutMs,
      headers: { 'Content-Type': 'text/xml; charset=utf-8', SOAPAction: soapAction, 'Content-Length': Buffer.byteLength(xml) },
      ...(destino.protocol === 'https:' ? { rejectUnauthorized: true, ...(cert ? { pfx: cert.p12, passphrase: cert.passphrase } : {}), ...(motor?.ca ? { ca: motor.ca } : {}) } : {}),
    };
    const req = cliente.request(opciones, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400) { res.resume(); return reject(new ErrorEntorno('Redirección no permitida', 'redireccion')); }
      const trozos = []; let n = 0;
      res.on('data', (d) => { n += d.length; if (n > MAX_RESPUESTA) { req.destroy(); reject(new ErrorTransporte('Respuesta demasiado grande')); } else trozos.push(d); });
      res.on('end', () => resolve({ status: res.statusCode, cuerpo: Buffer.concat(trozos).toString('utf8') }));
    });
    req.on('timeout', () => { req.destroy(); reject(new ErrorTransporte('Tiempo de espera agotado', { reintentable: true })); });
    req.on('error', (e) => reject(e instanceof ErrorEntorno ? e : new ErrorTransporte(`Error de red: ${e.code ?? e.name}`, { reintentable: true })));
    req.end(xml);
  });
}
