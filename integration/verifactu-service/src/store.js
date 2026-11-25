// Almacén SQLite (node:sqlite, sin dependencias). Todas las consultas de negocio filtran por instalacion_id: el aislamiento entre tenants vive aquí y en app.js.
import { DatabaseSync } from 'node:sqlite';

export function abrirDb(ruta = ':memory:') {
  const db = new DatabaseSync(ruta);
  db.exec(`
    PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;
    CREATE TABLE IF NOT EXISTS instalaciones (
      id TEXT PRIMARY KEY, nombre TEXT NOT NULL, nif TEXT NOT NULL, nombre_empresa TEXT NOT NULL,
      token_hash TEXT NOT NULL UNIQUE, activa INTEGER NOT NULL DEFAULT 1, modo_real INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, instalacion_id TEXT NOT NULL, accion TEXT NOT NULL, detalle TEXT, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS certificados (
      id TEXT PRIMARY KEY, instalacion_id TEXT NOT NULL REFERENCES instalaciones(id), entorno TEXT NOT NULL CHECK (entorno IN ('test','prod')),
      tipo TEXT NOT NULL, alias TEXT NOT NULL, titular_nif TEXT, titular_nombre TEXT, emisor TEXT, serie TEXT, huella_sha256 TEXT,
      valido_desde TEXT NOT NULL, valido_hasta TEXT NOT NULL, cualificado INTEGER NOT NULL, autofirmado INTEGER NOT NULL,
      apoderamiento_ref TEXT, apoderamiento_nif TEXT, apoderamiento_hasta TEXT, estado TEXT NOT NULL DEFAULT 'activo' CHECK (estado IN ('activo','revocado')),
      avisos TEXT NOT NULL DEFAULT '[]', p12_cifrado BLOB NOT NULL, pass_cifrada BLOB NOT NULL, dek_cifrada BLOB NOT NULL, created_at TEXT NOT NULL);
    -- una instalación = una empresa = un certificado activo por entorno
    CREATE UNIQUE INDEX IF NOT EXISTS ux_cert_activo ON certificados(instalacion_id, entorno) WHERE estado = 'activo';
    CREATE TABLE IF NOT EXISTS registros (
      id INTEGER PRIMARY KEY AUTOINCREMENT, instalacion_id TEXT NOT NULL REFERENCES instalaciones(id), entorno TEXT NOT NULL,
      tipo TEXT NOT NULL CHECK (tipo IN ('alta','anulacion')), subsanacion INTEGER NOT NULL DEFAULT 0, numserie TEXT NOT NULL, fecha TEXT NOT NULL,
      importe TEXT, huella TEXT NOT NULL, huella_anterior TEXT, xml TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'pendiente',
      csv TEXT, resp_codigo TEXT, resp_texto TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_reg_base ON registros(instalacion_id, entorno, tipo, numserie, fecha) WHERE subsanacion = 0;
  `);
  return db;
}
