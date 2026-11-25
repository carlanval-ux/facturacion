// VERIFACTU-FACTURALO: separación TEST / PROD y bloqueo técnico de producción. Directriz del OWNER (19/11/2026): ninguna prueba ni configuración de TEST puede conectar ni
// transmitir al entorno productivo de la AEAT, ni siquiera ante un error de configuración. Defensa en capas:
//   1. PROD está deshabilitado por defecto: la puerta global es el ajuste de despliegue VERIFACTU_PROD_HABILITADO (solo el texto exacto «true» la abre; defecto cerrada). Abrirla
//      requiere autorización expresa del OWNER; además cada tenant necesita su check modo_real (app.js) con certificado real y confirmación.
//   2. En TEST el destino se valida por LISTA BLANCA (pruebas de la AEAT + loopback/.test de los simulados): lo que no esté en la lista se rechaza, y además cualquier
//      host de producción conocido se rechaza primero por lista negra (defensa doble ante una lista blanca mal editada).
//   3. Sin redirecciones (un 302 hacia producción no se sigue) y la comprobación se hace en CADA petición, antes de abrir ningún socket.
//   4. Los certificados de TEST no sirven en PROD ni al revés (se comprueba también en certificados.js).
// Hosts: ver docs/verifactu-propio.md (§Fuentes) y la documentación oficial de la AEAT (fuente oficial de la AEAT, consultada el 19/11/2026).
export const prodHabilitadoDe = (env = process.env) => env.VERIFACTU_PROD_HABILITADO === 'true'; // estricto: '1', 'TRUE', 'yes' o vacío = cerrada

// Producción de la AEAT: lista NEGRA exacta y por sufijo (cualquier subdominio).
export const HOSTS_PROD = ['www1.agenciatributaria.gob.es', 'www10.agenciatributaria.gob.es', 'www2.agenciatributaria.gob.es'];
export const SUFIJOS_PROD = ['agenciatributaria.gob.es', 'agenciatributaria.es'];
// Pruebas externas de la AEAT: lista BLANCA exacta.
export const HOSTS_TEST_AEAT = ['prewww1.aeat.es', 'prewww10.aeat.es', 'prewww2.aeat.es'];
const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

export class ErrorEntorno extends Error {
  constructor(mensaje, codigo) { super(mensaje); this.name = 'ErrorEntorno'; this.codigo = codigo; }
}

// Normaliza el host como lo haría un resolutor: minúsculas, sin punto final, sin corchetes IPv6 ni credenciales.
export const normalizarHost = (hostname) => String(hostname).toLowerCase().replace(/\.+$/, '');
const esProd = (h) => HOSTS_PROD.includes(h) || SUFIJOS_PROD.some((s) => h === s || h.endsWith(`.${s}`));
const esSimulado = (h) => LOOPBACK.has(h) || h.endsWith('.localhost') || h.endsWith('.test');

// Valida un destino SIN abrir ningún socket. Devuelve la URL normalizada o lanza ErrorEntorno.
export function validarDestino(entorno, destino, prodHabilitado = false) { // cerrada salvo que quien llama pase la puerta abierta
  let u;
  try { u = new URL(destino); } catch { throw new ErrorEntorno('URL de destino no válida', 'url_invalida'); }
  if (u.username || u.password) throw new ErrorEntorno('La URL de destino no admite credenciales', 'url_credenciales');
  const host = normalizarHost(u.hostname);
  if (entorno === 'test') {
    if (esProd(host)) throw new ErrorEntorno(`TEST no puede conectar con producción de la AEAT (${host})`, 'prod_bloqueado_en_test');
    if (u.protocol !== 'https:' && !(u.protocol === 'http:' && esSimulado(host))) throw new ErrorEntorno('Solo HTTPS (HTTP únicamente hacia un simulado local)', 'protocolo');
    if (!HOSTS_TEST_AEAT.includes(host) && !esSimulado(host)) throw new ErrorEntorno(`Destino fuera de la lista blanca de TEST (${host})`, 'fuera_de_lista_blanca');
    return u;
  }
  if (entorno === 'prod') {
    if (!prodHabilitado) throw new ErrorEntorno('Producción deshabilitada', 'prod_deshabilitado');
    if (!HOSTS_PROD.includes(host) || u.protocol !== 'https:') throw new ErrorEntorno('Destino de producción no reconocido', 'destino_prod');
    return u;
  }
  throw new ErrorEntorno('Entorno no válido', 'entorno_invalido');
}
