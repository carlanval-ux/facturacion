import test from 'node:test';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { generarP12, levantar, subirCert, factura, NIF_A, NIF_B, PASS } from './util.js';

const p12A = generarP12({ nif: NIF_A });

test('flujo completo: certificado → alta encadenada → idempotencia → subsanación → anulación', async (t) => {
  const s = await levantar(); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A);
  assert.equal((await s.api('GET', '/v1/estado', { token: A.token })).json.certificado, null);

  const up = await subirCert(s.api, A.token, p12A);
  assert.equal(up.status, 201, up.texto);
  assert.equal(up.json.certificado.titular_nif, NIF_A);
  assert.equal(up.json.certificado.vigente, true);
  // nunca sale el material secreto
  for (const prohibido of [PASS, p12A.toString('base64').slice(0, 40), 'p12_cifrado', 'pass_cifrada', 'dek_cifrada']) assert.ok(!up.texto.includes(prohibido), `la respuesta contiene ${prohibido}`);
  const est = await s.api('GET', '/v1/estado', { token: A.token });
  assert.equal(est.json.prod_habilitado, false); assert.equal(est.json.entorno, 'test'); assert.ok(!est.texto.includes(PASS));

  const r1 = await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura(1) } });
  assert.equal(r1.status, 201, r1.texto);
  assert.equal(r1.json.registro.estado, 'aceptado'); assert.equal(r1.json.registro.csv, 'CSVPRUEBA0001'); assert.equal(r1.json.registro.importe, '121.00');
  assert.match(r1.json.registro.qr_url, /^https:\/\/prewww2\.aeat\.es\/wlpl\/TIKE-CONT\/ValidarQR\?nif=B12345674&numserie=A%2F1&fecha=20-11-2026&importe=121\.00$/);
  assert.match(s.sim.recibidos[0], /<sf:PrimerRegistro>S<\/sf:PrimerRegistro>/);
  assert.ok(!s.sim.recibidos[0].includes('<sf:Representante>'), 'un sello de empresa no lleva representante');

  const r2 = await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura(2) } });
  assert.ok(s.sim.recibidos[1].includes(`<sf:Huella>${r1.json.registro.huella}</sf:Huella></sf:RegistroAnterior>`), 'encadena con la huella anterior');

  const rep = await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura(1) } });
  assert.equal(rep.status, 200); assert.equal(rep.json.repetido, true); assert.equal(s.sim.recibidos.length, 2, 'un alta aceptada no se reenvía');

  const sub = await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura(2), subsanacion: true } });
  assert.equal(sub.status, 201, sub.texto); assert.match(s.sim.recibidos[2], /<sf:Subsanacion>S<\/sf:Subsanacion>/);
  assert.ok(s.sim.recibidos[2].includes(`<sf:Huella>${r2.json.registro.huella}</sf:Huella></sf:RegistroAnterior>`));
  assert.equal((await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura(9), subsanacion: true } })).status, 409, 'subsanar sin alta previa');

  const an = await s.api('POST', '/v1/registros/anulacion', { token: A.token, body: { factura: { numserie: 'A/1', fecha: '2026-11-20' } } });
  assert.equal(an.status, 201, an.texto); assert.match(s.sim.recibidos[3], /<sf:RegistroAnulacion>/);
  assert.equal((await s.api('POST', '/v1/registros/anulacion', { token: A.token, body: { factura: { numserie: 'Z/1', fecha: '2026-11-20' } } })).status, 409, 'anular lo que no existe');

  const lista = await s.api('GET', '/v1/registros?numserie=A%2F1', { token: A.token });
  assert.deepEqual(lista.json.registros.map((r) => r.tipo), ['alta', 'anulacion']);
});

test('negativos del certificado: ajeno, de otra empresa, caducado, no es de prueba, no es de firma', async (t) => {
  const s = await levantar(); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A);
  const ajeno = await subirCert(s.api, A.token, generarP12({ nif: NIF_B }));
  assert.equal(ajeno.status, 422); assert.match(ajeno.json.problemas.join(), /no es la empresa emisora/);
  const caducado = await subirCert(s.api, A.token, generarP12({ desde: '20240101000000Z', hasta: '20240201000000Z' }));
  assert.equal(caducado.status, 422); assert.match(caducado.json.problemas.join(), /caducado/);
  const real = await subirCert(s.api, A.token, generarP12({ cn: 'Empresa Real SL' }));
  assert.equal(real.status, 422); assert.match(real.json.problemas.join(), /PRUEBA/);
  const sinFirma = await subirCert(s.api, A.token, generarP12({ ku: 'keyEncipherment' }));
  assert.equal(sinFirma.status, 422); assert.match(sinFirma.json.problemas.join(), /firma digital/);
  assert.equal((await subirCert(s.api, A.token, p12A, { passphrase: 'mala' })).status, 422);
  assert.equal((await subirCert(s.api, A.token, p12A, { nif_empresa: NIF_B })).status, 409, 'el plugin declara otro NIF');
  assert.equal((await s.api('GET', '/v1/estado', { token: A.token })).json.certificado, null, 'nada se guardó');
  assert.equal((await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura() } })).status, 409, 'sin certificado no se remite');
  assert.equal(s.sim.recibidos.length, 0);
});

test('aislamiento entre tenants y autenticación', async (t) => {
  let llamadas = 0;
  const s = await levantar({ transporte: async () => { llamadas++; throw new Error('no debería llamarse'); } }); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A), B = await s.instalacion(NIF_B);
  assert.equal((await subirCert(s.api, A.token, p12A)).status, 201);
  assert.equal((await s.api('GET', '/v1/estado', { token: B.token })).json.certificado, null, 'B no ve el certificado de A');
  assert.equal((await s.api('POST', '/v1/registros/alta', { token: B.token, body: { factura: factura() } })).status, 409, 'B no puede usar el certificado de A');
  assert.equal(llamadas, 0);

  // sobre cifrado copiado de A a la fila de B: la AAD (id:instalación) impide descifrarlo → no se remite nada
  const fila = s.db.prepare('SELECT * FROM certificados WHERE instalacion_id=?').get(A.id);
  s.db.prepare(`INSERT INTO certificados(id,instalacion_id,entorno,tipo,alias,titular_nif,valido_desde,valido_hasta,cualificado,autofirmado,p12_cifrado,pass_cifrada,dek_cifrada,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
    .run(randomUUID(), B.id, fila.entorno, fila.tipo, 'copia', fila.titular_nif, fila.valido_desde, fila.valido_hasta, 0, 1, fila.p12_cifrado, fila.pass_cifrada, fila.dek_cifrada, fila.created_at);
  const robo = await s.api('POST', '/v1/registros/alta', { token: B.token, body: { factura: factura() } });
  assert.equal(robo.json.registro.estado, 'error'); assert.equal(llamadas, 0, 'no se descifró ni se transmitió');

  assert.deepEqual((await s.api('GET', '/v1/registros', { token: A.token })).json.registros, []);
  for (const token of [undefined, 'x'.repeat(43), A.token.slice(0, -1) + (A.token.endsWith('a') ? 'b' : 'a')]) assert.equal((await s.api('GET', '/v1/estado', { token })).status, 401);
  assert.equal((await s.api('POST', '/admin/instalaciones', { token: A.token, body: { nombre: 'x', nif: NIF_B, nombre_empresa: 'x' } })).status, 401, 'un token de instalación no administra');
  await s.api('POST', '/admin/instalaciones/desactivar', { token: s.env.ADMIN_TOKEN, body: { id: A.id } });
  assert.equal((await s.api('GET', '/v1/estado', { token: A.token })).status, 401, 'instalación desactivada');
});

test('en reposo: ni el PKCS#12 ni la contraseña aparecen en la base de datos', async (t) => {
  const s = await levantar(); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A); await subirCert(s.api, A.token, p12A);
  const f = s.db.prepare('SELECT * FROM certificados').get();
  for (const campo of ['p12_cifrado', 'pass_cifrada', 'dek_cifrada']) {
    assert.ok(!f[campo].includes(Buffer.from(PASS)) && !Buffer.from(f[campo]).includes(p12A.subarray(20, 60)), campo);
  }
  assert.ok(!JSON.stringify({ ...f, p12_cifrado: 0, pass_cifrada: 0, dek_cifrada: 0 }).includes(PASS));
});

test('producción bloqueada: el servicio no guarda certificados ni transmite', async (t) => {
  let llamadas = 0;
  const s = await levantar({ env: { VERIFACTU_ENTORNO: 'prod' }, transporte: async () => { llamadas++; } }); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A);
  const up = await subirCert(s.api, A.token, p12A);
  assert.equal(up.status, 403); assert.equal(up.json.codigo, 'prod_deshabilitado');
  assert.equal((await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura() } })).status, 403);
  assert.equal((await s.api('GET', '/v1/estado', { token: A.token })).status, 403);
  assert.equal(llamadas, 0);
});

test('destino de producción y redirecciones: se bloquean antes de enviar', async (t) => {
  const s1 = await levantar({ env: { VERIFACTU_URL_TEST: 'https://www1.agenciatributaria.gob.es/wlpl/x' } }); t.after(s1.cerrar);
  const A = await s1.instalacion(NIF_A); await subirCert(s1.api, A.token, p12A);
  const r = await s1.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: factura() } });
  assert.equal(r.status, 409); assert.equal(r.json.codigo, 'prod_bloqueado_en_test');

  const s2 = await levantar(); t.after(s2.cerrar);
  const B = await s2.instalacion(NIF_A); await subirCert(s2.api, B.token, p12A);
  s2.sim.modo = 'redirect';
  const r2 = await s2.api('POST', '/v1/registros/alta', { token: B.token, body: { factura: factura() } });
  assert.equal(r2.status, 409); assert.equal(r2.json.codigo, 'redireccion');
  assert.equal(s2.sim.recibidos.length, 1, 'el 302 hacia producción no se siguió (solo hubo una petición al simulado)');
});

test('validación de entrada', async (t) => {
  const s = await levantar(); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A); await subirCert(s.api, A.token, p12A);
  for (const f of [factura(1, { fecha: '20/11/2026' }), factura(1, { lineas: [] }), factura(1, { tipo: 'R1' }), factura(1, { destinatario: null }), factura(1, { numserie: '' })])
    assert.equal((await s.api('POST', '/v1/registros/alta', { token: A.token, body: { factura: f } })).status, 422);
  assert.equal((await s.api('POST', '/admin/instalaciones', { token: s.env.ADMIN_TOKEN, body: { nombre: 'x', nif: 'corto', nombre_empresa: 'x' } })).status, 422);
});

test('rotación de token: el anterior deja de valer y los datos se conservan', async (t) => {
  const s = await levantar(); t.after(s.cerrar);
  const A = await s.instalacion(NIF_A); await subirCert(s.api, A.token, p12A);
  const r = await s.api('POST', '/admin/instalaciones/rotar-token', { token: s.env.ADMIN_TOKEN, body: { id: A.id } });
  assert.equal(r.status, 200);
  assert.equal((await s.api('GET', '/v1/estado', { token: A.token })).status, 401);
  assert.equal((await s.api('GET', '/v1/estado', { token: r.json.token })).json.certificado.titular_nif, NIF_A);
  assert.equal((await s.api('POST', '/admin/instalaciones/rotar-token', { token: A.token, body: { id: A.id } })).status, 401);
});
