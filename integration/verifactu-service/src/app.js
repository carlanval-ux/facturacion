// API HTTP del servicio central VERI*FACTU de Facturalo (solo PRUEBAS). Una instalación = una empresa = un certificado; el tenant sale SIEMPRE del token, nunca del cuerpo.
// Ninguna respuesta incluye el PKCS#12, la contraseña ni la DEK; no se registra ningún cuerpo de petición.
import http from 'node:http';
import { createHash, randomBytes, randomUUID, timingSafeEqual } from 'node:crypto';
import { entornoActivo, ErrorEntorno, PROD_HABILITADO } from './entorno.js';
import { TIPOS, claveMaestra, cifrarSecretos, descifrarSecretos, inspeccionarP12, verificarCertificado, ErrorCertificado } from './certificados.js';
import { construirAlta, construirAnulacion, construirEnvio, leerRespuesta, fechaAEAT } from './registro.js';
import { enviarSoap } from './transporte.js';

const PATH_SERVICIO = '/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
const HOST_PRUEBAS = { sello_empresa: 'prewww10.aeat.es', representante: 'prewww1.aeat.es', colaborador_social: 'prewww1.aeat.es' };
const sha = (s) => createHash('sha256').update(s).digest('hex');
const NIF_RE = /^[A-Z0-9]{9}$/;
const iguales = (a, b) => { const x = Buffer.from(a), y = Buffer.from(b); return x.length === y.length && timingSafeEqual(x, y); };

class Http extends Error { constructor(status, mensaje, extra = {}) { super(mensaje); this.status = status; this.extra = extra; } }
const exige = (c, status, msg) => { if (!c) throw new Http(status, msg); };
const txt = (v, max, nombre) => { exige(typeof v === 'string' && v.trim() && v.length <= max, 422, `${nombre} no válido`); return v.trim(); };

const META_CERT = ['id', 'entorno', 'tipo', 'alias', 'titular_nif', 'titular_nombre', 'emisor', 'serie', 'huella_sha256', 'valido_desde', 'valido_hasta', 'cualificado', 'autofirmado',
  'apoderamiento_ref', 'apoderamiento_nif', 'apoderamiento_hasta', 'estado', 'created_at'];
const metaCert = (c, ahora) => c && { ...Object.fromEntries(META_CERT.map((k) => [k, c[k]])), cualificado: !!c.cualificado, autofirmado: !!c.autofirmado, avisos: JSON.parse(c.avisos),
  vigente: c.estado === 'activo' && new Date(c.valido_desde) <= ahora && new Date(c.valido_hasta) > ahora, dias_restantes: Math.max(0, Math.floor((new Date(c.valido_hasta) - ahora) / 864e5)) };

export function crearApp({ db, env = process.env, transporte = enviarSoap, ahora = () => new Date() }) {
  const q = (sql, ...p) => db.prepare(sql).get(...p);
  const qa = (sql, ...p) => db.prepare(sql).all(...p);
  const run = (sql, ...p) => db.prepare(sql).run(...p);

  const qrUrl = (nif, r) => `https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=${encodeURIComponent(nif)}&numserie=${encodeURIComponent(r.numserie)}&fecha=${r.fecha}&importe=${r.importe}`; // solo pruebas
  const vista = (inst, r) => ({ id: r.id, tipo: r.tipo, subsanacion: !!r.subsanacion, numserie: r.numserie, fecha: r.fecha, estado: r.estado, huella: r.huella, csv: r.csv, codigo: r.resp_codigo,
    mensaje: r.resp_texto, importe: r.importe, entorno: r.entorno, ...(r.tipo === 'alta' ? { qr_url: qrUrl(inst.nif, r) } : {}), created_at: r.created_at });

  function autenticar(req) {
    const m = /^Bearer ([\w-]{20,100})$/.exec(req.headers.authorization ?? '');
    exige(m, 401, 'No autorizado');
    const inst = q('SELECT * FROM instalaciones WHERE token_hash=? AND activa=1', sha(m[1]));
    exige(inst, 401, 'No autorizado');
    return inst;
  }
  const esAdmin = (req) => {
    const t = env.ADMIN_TOKEN ?? '';
    exige(t.length >= 20, 503, 'Administración no configurada');
    exige(iguales(String(req.headers.authorization ?? ''), `Bearer ${t}`), 401, 'No autorizado');
  };

  // Certificado activo y vigente de la instalación para el entorno; undefined si no hay.
  const certActivo = (inst, entorno) => {
    const t = ahora().toISOString();
    return q("SELECT * FROM certificados WHERE instalacion_id=? AND entorno=? AND estado='activo' AND valido_desde<=? AND valido_hasta>?", inst.id, entorno, t, t);
  };

  async function remitir(inst, entorno, reg) {
    const cert = certActivo(inst, entorno);
    exige(cert, 409, 'No hay un certificado de pruebas activo y vigente para esta empresa');
    const kek = claveMaestra(env);
    const rep = cert.tipo === 'sello_empresa' ? null : { nombre: cert.titular_nombre, nif: cert.titular_nif };
    const cuerpo = construirEnvio({ obligado: { nif: inst.nif, nombre: inst.nombre_empresa }, registros: [reg.xml], representante: rep });
    const url = env.VERIFACTU_URL_TEST || `https://${HOST_PRUEBAS[cert.tipo]}${PATH_SERVICIO}`;
    let estado = 'error', codigo = null, texto = null, csv = null;
    try {
      const secretos = descifrarSecretos({ ...cert, aad: `${cert.id}:${inst.id}`, kek });
      const r = await transporte({ entorno, url, xml: cuerpo, cert: secretos, motor: env.VERIFACTU_CA_TEST ? { ca: env.VERIFACTU_CA_TEST } : null });
      const l = leerRespuesta(r.cuerpo);
      if (l.fault) { codigo = 'fault'; texto = `${l.codigo ?? ''} ${l.mensaje ?? ''}`.trim(); }
      else {
        csv = l.csv; const linea = l.lineas[0];
        estado = l.estadoEnvio === 'Correcto' ? 'aceptado' : linea?.estado === 'AceptadoConErrores' ? 'aceptado_con_errores' : 'rechazado';
        codigo = linea?.codigo ?? l.estadoEnvio; texto = linea?.descripcion ?? l.estadoEnvio;
      }
    } catch (e) {
      if (e instanceof ErrorEntorno) throw e; // destino no permitido: se informa y no queda como «error de red»
      texto = String(e.message ?? e).slice(0, 300);
    }
    run('UPDATE registros SET estado=?, csv=?, resp_codigo=?, resp_texto=?, updated_at=? WHERE id=? AND instalacion_id=?', estado, csv, codigo, (texto ?? '').slice(0, 1000), ahora().toISOString(), reg.id, inst.id);
    return q('SELECT * FROM registros WHERE id=? AND instalacion_id=?', reg.id, inst.id);
  }

  function fechaYSerie(f) {
    exige(f && typeof f === 'object', 422, 'Falta factura');
    exige(/^\d{4}-\d{2}-\d{2}$/.test(f.fecha ?? '') && !Number.isNaN(Date.parse(f.fecha)), 422, 'Fecha no válida (AAAA-MM-DD)');
    return { numserie: txt(f.numserie, 60, 'Número de serie'), fecha: new Date(`${f.fecha}T12:00:00Z`) };
  }
  function leerFactura(f) {
    const base = fechaYSerie(f), tipo = f.tipo ?? 'F1';
    exige(['F1', 'F2'].includes(tipo), 422, 'Tipo de factura no soportado todavía (F1, F2)');
    exige(Array.isArray(f.lineas) && f.lineas.length > 0 && f.lineas.length <= 500
      && f.lineas.every((l) => Number.isInteger(l.base_cent) && l.base_cent >= 0 && Number.isInteger(l.iva_bp) && l.iva_bp >= 0 && l.iva_bp <= 10000), 422, 'Líneas no válidas');
    let destinatario = null;
    if (tipo === 'F1') {
      exige(f.destinatario, 422, 'F1 exige destinatario');
      destinatario = { nombre: txt(f.destinatario.nombre, 120, 'Nombre del destinatario'), nif: txt(f.destinatario.nif, 20, 'NIF del destinatario').toUpperCase() };
    }
    return { ...base, tipo, descripcion: f.descripcion ? txt(f.descripcion, 500, 'Descripción') : undefined, destinatario, lineas: f.lineas };
  }
  const anteriorDe = (inst, entorno) => { // último registro generado de la cadena, sea cual sea su estado
    const a = q('SELECT huella, numserie, fecha FROM registros WHERE instalacion_id=? AND entorno=? ORDER BY id DESC LIMIT 1', inst.id, entorno);
    return a ? { nif: inst.nif, numserie: a.numserie, fecha: a.fecha, huella: a.huella } : null;
  };
  const insertar = (inst, entorno, r) => run(`INSERT INTO registros(instalacion_id,entorno,tipo,subsanacion,numserie,fecha,importe,huella,huella_anterior,xml,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`,
    inst.id, entorno, r.tipo, +!!r.subsanacion, r.numserie, r.fecha, r.importe ?? null, r.huella, r.huellaAnterior, r.xml, ahora().toISOString(), ahora().toISOString()).lastInsertRowid;

  const rutas = {
    'POST /admin/instalaciones': async (req, body) => {
      esAdmin(req);
      const nif = txt(body.nif, 9, 'NIF').toUpperCase(); exige(NIF_RE.test(nif), 422, 'NIF no válido');
      const id = randomUUID(), token = randomBytes(32).toString('base64url');
      run('INSERT INTO instalaciones(id,nombre,nif,nombre_empresa,token_hash,created_at) VALUES (?,?,?,?,?,?)', id, txt(body.nombre, 80, 'Nombre'), nif, txt(body.nombre_empresa, 120, 'Nombre de la empresa'), sha(token), ahora().toISOString());
      return [201, { id, nif, token }]; // el token solo se ve ahora; en BD queda su hash
    },
    'POST /admin/instalaciones/desactivar': async (req, body) => {
      esAdmin(req);
      exige(run('UPDATE instalaciones SET activa=0 WHERE id=?', txt(body.id, 60, 'id')).changes, 404, 'No existe');
      return [200, { ok: true }];
    },

    // Rotación del token de un tenant (el anterior deja de valer al instante). Lo invoca el backend de la plataforma, nunca el navegador del cliente.
    'POST /admin/instalaciones/rotar-token': async (req, body) => {
      esAdmin(req);
      const token = randomBytes(32).toString('base64url');
      exige(run('UPDATE instalaciones SET token_hash=? WHERE id=? AND activa=1', sha(token), txt(body.id, 60, 'id')).changes, 404, 'No existe');
      return [200, { token }];
    },

    'GET /v1/estado': async (req) => {
      const inst = autenticar(req), entorno = entornoActivo(env);
      const c = q("SELECT * FROM certificados WHERE instalacion_id=? AND entorno=? AND estado='activo'", inst.id, entorno);
      return [200, { entorno, prod_habilitado: PROD_HABILITADO, empresa: { nif: inst.nif, nombre: inst.nombre_empresa }, certificado: metaCert(c, ahora()) ?? null }];
    },
    // Alta: se inspecciona y verifica ANTES de guardar. Sustituye al certificado activo anterior (revocado) en una sola transacción.
    'PUT /v1/certificado': async (req, body) => {
      const inst = autenticar(req), entorno = entornoActivo(env); // en prod lanza prod_deshabilitado
      const tipo = body.tipo; exige(TIPOS.includes(tipo), 422, 'Tipo de certificado no válido');
      exige(typeof body.p12_base64 === 'string' && body.p12_base64.length > 100 && body.p12_base64.length < 90_000, 422, 'Certificado PKCS#12 no válido');
      exige(typeof body.passphrase === 'string' && body.passphrase.length >= 1 && body.passphrase.length <= 200, 422, 'Indica la contraseña');
      const ap = body.apoderamiento ?? null;
      if (ap) exige(typeof ap.referencia === 'string' && typeof ap.nif_representado === 'string', 422, 'Apoderamiento: referencia y NIF representado');
      if (body.nif_empresa !== undefined) exige(String(body.nif_empresa).toUpperCase() === inst.nif, 409, 'El NIF indicado no corresponde a esta instalación');
      const kek = claveMaestra(env), p12 = Buffer.from(body.p12_base64, 'base64');
      const info = await inspeccionarP12(p12, body.passphrase);
      const v = verificarCertificado(info, { empresa: { nif: inst.nif }, tipo, entorno, apoderamiento: ap, ahora: ahora() });
      if (!v.ok) throw new Http(422, 'El certificado no es válido para esta empresa y entorno', { problemas: v.problemas, avisos: v.avisos });
      const id = randomUUID(), s = cifrarSecretos({ p12, passphrase: body.passphrase, aad: `${id}:${inst.id}`, kek });
      const cn = (info.sujeto.split('\n').find((x) => x.startsWith('CN=')) ?? '').replace(/^CN=/, '').slice(0, 120);
      db.exec('BEGIN');
      try {
        run("UPDATE certificados SET estado='revocado' WHERE instalacion_id=? AND entorno=? AND estado='activo'", inst.id, entorno);
        run(`INSERT INTO certificados(id,instalacion_id,entorno,tipo,alias,titular_nif,titular_nombre,emisor,serie,huella_sha256,valido_desde,valido_hasta,cualificado,autofirmado,apoderamiento_ref,apoderamiento_nif,
          apoderamiento_hasta,avisos,p12_cifrado,pass_cifrada,dek_cifrada,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`, id, inst.id, entorno, tipo, txt(body.alias ?? (cn || 'certificado'), 100, 'Alias'), info.nif_titular, cn,
        info.emisor.replace(/\n/g, ', ').slice(0, 300), info.serie, info.huella_sha256, info.valido_desde.toISOString(), info.valido_hasta.toISOString(), +info.cualificado, +info.autofirmado,
        ap?.referencia ?? null, ap?.nif_representado ?? null, ap?.hasta ?? null, JSON.stringify(v.avisos), s.p12_cifrado, s.pass_cifrada, s.dek_cifrada, ahora().toISOString());
        db.exec('COMMIT');
      } catch (e) { db.exec('ROLLBACK'); throw e; }
      return [201, { certificado: metaCert(q('SELECT * FROM certificados WHERE id=?', id), ahora()) }];
    },
    'DELETE /v1/certificado': async (req) => {
      const inst = autenticar(req), entorno = entornoActivo(env);
      return [200, { revocados: run("UPDATE certificados SET estado='revocado' WHERE instalacion_id=? AND entorno=? AND estado='activo'", inst.id, entorno).changes }];
    },

    'POST /v1/registros/alta': async (req, body) => {
      const inst = autenticar(req), entorno = entornoActivo(env), f = leerFactura(body.factura), sub = body.subsanacion === true, fecha = fechaAEAT(f.fecha);
      const previo = q("SELECT * FROM registros WHERE instalacion_id=? AND entorno=? AND tipo='alta' AND numserie=? AND fecha=? AND subsanacion=0", inst.id, entorno, f.numserie, fecha);
      if (!sub && previo) { // idempotente: se reenvía el MISMO XML salvo que ya esté aceptado
        const r = previo.estado === 'aceptado' ? previo : await remitir(inst, entorno, previo);
        return [200, { registro: vista(inst, r), repetido: true }];
      }
      exige(!sub || previo, 409, 'No hay un alta previa que subsanar');
      exige(certActivo(inst, entorno), 409, 'No hay un certificado de pruebas activo y vigente para esta empresa');
      const ant = anteriorDe(inst, entorno);
      const a = construirAlta({ emisor: { nif: inst.nif, nombre: inst.nombre_empresa }, factura: f, subsanacion: sub, anterior: ant, momento: ahora() });
      const id = insertar(inst, entorno, { tipo: 'alta', subsanacion: sub, numserie: f.numserie, fecha: a.fecha, importe: a.importeTotal, huella: a.huella, huellaAnterior: ant?.huella ?? null, xml: a.xml });
      return [201, { registro: vista(inst, await remitir(inst, entorno, { id, xml: a.xml })) }];
    },
    'POST /v1/registros/anulacion': async (req, body) => {
      const inst = autenticar(req), entorno = entornoActivo(env), f = fechaYSerie(body.factura), fecha = fechaAEAT(f.fecha);
      exige(q("SELECT 1 AS x FROM registros WHERE instalacion_id=? AND entorno=? AND tipo='alta' AND numserie=? AND fecha=?", inst.id, entorno, f.numserie, fecha), 409, 'No existe un alta de esa factura que anular');
      const previo = q("SELECT * FROM registros WHERE instalacion_id=? AND entorno=? AND tipo='anulacion' AND numserie=? AND fecha=?", inst.id, entorno, f.numserie, fecha);
      if (previo) return [200, { registro: vista(inst, previo.estado === 'aceptado' ? previo : await remitir(inst, entorno, previo)), repetido: true }];
      exige(certActivo(inst, entorno), 409, 'No hay un certificado de pruebas activo y vigente para esta empresa');
      const ant = anteriorDe(inst, entorno), a = construirAnulacion({ emisor: { nif: inst.nif, nombre: inst.nombre_empresa }, factura: f, anterior: ant, momento: ahora() });
      const id = insertar(inst, entorno, { tipo: 'anulacion', numserie: f.numserie, fecha: a.fecha, huella: a.huella, huellaAnterior: ant?.huella ?? null, xml: a.xml });
      return [201, { registro: vista(inst, await remitir(inst, entorno, { id, xml: a.xml })) }];
    },
    'GET /v1/registros': async (req, _b, url) => {
      const inst = autenticar(req), numserie = url.searchParams.get('numserie');
      const filas = numserie ? qa('SELECT * FROM registros WHERE instalacion_id=? AND numserie=? ORDER BY id', inst.id, numserie) : qa('SELECT * FROM registros WHERE instalacion_id=? ORDER BY id DESC LIMIT 100', inst.id);
      return [200, { registros: filas.map((r) => vista(inst, r)) }];
    },
  };

  // El plugin de FacturaScripts solo dispone de GET y POST JSON (Core/Http): alias POST de PUT/DELETE.
  rutas['POST /v1/certificado'] = rutas['PUT /v1/certificado'];
  rutas['POST /v1/certificado/revocar'] = rutas['DELETE /v1/certificado'];

  const MAX_CUERPO = 256 * 1024;
  const leerCuerpo = (req) => new Promise((resolve, reject) => {
    const tr = []; let n = 0;
    req.on('data', (d) => { n += d.length; if (n > MAX_CUERPO) { reject(new Http(413, 'Cuerpo demasiado grande')); req.destroy(); } else tr.push(d); });
    req.on('end', () => { try { resolve(n ? JSON.parse(Buffer.concat(tr).toString('utf8')) : {}); } catch { reject(new Http(400, 'JSON no válido')); } });
    req.on('error', reject);
  });
  const enviar = (res, status, obj) => { res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' }); res.end(JSON.stringify(obj)); };

  return http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://x'), h = rutas[`${req.method} ${url.pathname}`];
      if (!h) throw new Http(404, 'No existe');
      const body = ['POST', 'PUT'].includes(req.method) ? await leerCuerpo(req) : {};
      const [status, obj] = await h(req, body, url);
      enviar(res, status, obj);
    } catch (e) {
      if (e instanceof Http) return enviar(res, e.status, { error: e.message, ...e.extra });
      if (e instanceof ErrorEntorno) return enviar(res, e.codigo === 'prod_deshabilitado' ? 403 : 409, { error: e.message, codigo: e.codigo });
      if (e instanceof ErrorCertificado) return enviar(res, ['sin_clave_maestra', 'sin_openssl'].includes(e.codigo) ? 503 : 422, { error: e.message, codigo: e.codigo });
      enviar(res, 500, { error: 'Error interno' }); // nunca se devuelve el mensaje original: podría contener material sensible
    }
  });
}
