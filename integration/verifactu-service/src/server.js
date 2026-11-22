import { crearApp } from './app.js';
import { abrirDb } from './store.js';
import { entornoActivo } from './entorno.js';
import { claveMaestra } from './certificados.js';

entornoActivo(); // aborta el arranque si VERIFACTU_ENTORNO=prod mientras producción esté deshabilitada
claveMaestra(); // aborta si falta CERT_MASTER_KEY
const port = Number(process.env.PORT ?? 8787), host = process.env.HOST ?? '127.0.0.1';
crearApp({ db: abrirDb(process.env.DB_PATH ?? './verifactu.sqlite') }).listen(port, host, () => console.log(`verifactu-service (entorno ${entornoActivo()}) en ${host}:${port}`));
