import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { huellaAlta, importe, mulBp } from '../src/registro.js';
import { cifrarSecretos, descifrarSecretos, extraerNif, verificarCertificado } from '../src/certificados.js';
import { prodHabilitadoDe, validarDestino, ErrorEntorno } from '../src/entorno.js';
import { enviarSoap } from '../src/transporte.js';

test('huella: ejemplo oficial 1 de la AEAT (HUE §6)', () => {
  assert.equal(huellaAlta({ nif: '89890001K', numserie: '12345678/G33', fecha: '01-01-2024', tipo: 'F1', cuotaTotal: '12.35', importeTotal: '123.45', fechaHora: '2024-01-01T19:20:30+01:00' }),
    '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60');
});
test('importes en céntimos', () => { assert.equal(importe(12345), '123.45'); assert.equal(mulBp(10000, 2100), 2100); assert.equal(mulBp(5, 2100), 1); });

test('cifrado: sobre ligado a certificado y empresa; clave maestra errónea no abre', () => {
  const kek = randomBytes(32), p12 = randomBytes(500);
  const s = cifrarSecretos({ p12, passphrase: 'secreto', aad: 'cert1:empresaA', kek });
  assert.ok(!s.p12_cifrado.includes(p12.subarray(0, 32)) && !s.pass_cifrada.includes(Buffer.from('secreto')));
  assert.deepEqual(descifrarSecretos({ ...s, aad: 'cert1:empresaA', kek }).p12, p12);
  assert.throws(() => descifrarSecretos({ ...s, aad: 'cert1:empresaB', kek }), 'otra empresa');
  assert.throws(() => descifrarSecretos({ ...s, aad: 'cert2:empresaA', kek }), 'otro certificado');
  assert.throws(() => descifrarSecretos({ ...s, aad: 'cert1:empresaA', kek: randomBytes(32) }), 'otra clave maestra');
});

test('NIF del titular', () => { assert.equal(extraerNif('serialNumber=IDCES-B12345674\nCN=Empresa'), 'B12345674'); assert.equal(extraerNif('CN=Juan 12345678Z'), '12345678Z'); });

const info = (o = {}) => ({ sujeto: 'CN=Prueba', emisor: 'CN=CA Prueba', nif_titular: 'B12345674', valido_desde: new Date('2026-01-01'), valido_hasta: new Date('2027-01-01'), firma_digital: true, autenticacion_cliente: true, autofirmado: true, cualificado: false, ...o });
const ctx = (o = {}) => ({ empresa: { nif: 'B12345674' }, tipo: 'sello_empresa', entorno: 'test', ahora: new Date('2026-06-01'), ...o });
test('verificación: sello válido; ajeno, caducado, usos, representante sin apoderamiento, prod', () => {
  assert.equal(verificarCertificado(info(), ctx()).ok, true);
  assert.match(verificarCertificado(info({ nif_titular: 'A58818501' }), ctx()).problemas.join(), /no es la empresa emisora/);
  assert.match(verificarCertificado(info(), ctx({ ahora: new Date('2028-01-01') })).problemas.join(), /caducado/);
  assert.match(verificarCertificado(info({ firma_digital: false }), ctx()).problemas.join(), /firma digital/);
  assert.match(verificarCertificado(info({ nif_titular: '12345678Z' }), ctx({ tipo: 'representante' })).problemas.join(), /apoderamiento/);
  assert.equal(verificarCertificado(info({ nif_titular: '12345678Z' }), ctx({ tipo: 'representante', apoderamiento: { referencia: 'AP-1', nif_representado: 'B12345674' } })).ok, true);
  assert.match(verificarCertificado(info({ nif_titular: '12345678Z' }), ctx({ tipo: 'representante', apoderamiento: { referencia: 'AP-1', nif_representado: 'A58818501' } })).problemas.join(), /no corresponde/);
  assert.match(verificarCertificado(info({ emisor: 'CN=FNMT', sujeto: 'CN=Empresa' }), ctx()).problemas.join(), /solo admite certificados de PRUEBA/);
  assert.match(verificarCertificado(info(), ctx({ entorno: 'prod' })).problemas.join(), /prueba no sirve en producción/);
});

test('puerta global: solo «true» la abre', () => {
  for (const v of [undefined, '', 'false', '1', 'TRUE', 'yes', ' true']) assert.equal(prodHabilitadoDe({ VERIFACTU_PROD_HABILITADO: v }), false, String(v));
  assert.equal(prodHabilitadoDe({ VERIFACTU_PROD_HABILITADO: 'true' }), true);
  assert.equal(prodHabilitadoDe({}), false);
});

test('producción bloqueada por defecto y en destino', async () => {
  for (const u of ['https://www1.agenciatributaria.gob.es/x', 'https://www2.agenciatributaria.gob.es/x', 'https://sub.agenciatributaria.gob.es/x', 'https://WWW10.AgenciaTributaria.gob.es./x'])
    assert.throws(() => validarDestino('test', u), (e) => e.codigo === 'prod_bloqueado_en_test', u);
  assert.throws(() => validarDestino('test', 'https://example.com/x'), (e) => e.codigo === 'fuera_de_lista_blanca');
  assert.throws(() => validarDestino('test', 'https://u:p@prewww1.aeat.es/x'), (e) => e.codigo === 'url_credenciales');
  assert.throws(() => validarDestino('test', 'http://prewww1.aeat.es/x'), (e) => e.codigo === 'protocolo');
  assert.throws(() => validarDestino('prod', 'https://www1.agenciatributaria.gob.es/x'), (e) => e.codigo === 'prod_deshabilitado');
  assert.ok(validarDestino('test', 'https://prewww10.aeat.es/x') && validarDestino('test', 'http://127.0.0.1:9/x'));
  await assert.rejects(enviarSoap({ entorno: 'test', url: 'https://www1.agenciatributaria.gob.es/x', xml: '<a/>' }), ErrorEntorno); // falla antes de abrir un socket
});
