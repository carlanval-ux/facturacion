-- Uso: sed "s/__KEY__/$(openssl rand -hex 24)/" provision.sql | mariadb ... (clave solo con permisos mínimos; NO fullaccess)
UPDATE settings SET properties = JSON_SET(properties, '$.enable_api', true, '$.codpago', 'CONT') WHERE name = 'default';
INSERT INTO api_keys (apikey, creationdate, description, enabled, fullaccess, nick) VALUES ('__KEY__', CURDATE(), 'teleco-bridge-poc', 1, 0, 'admin');
SET @id = LAST_INSERT_ID();
INSERT INTO api_access (idapikey, resource, allowget, allowpost, allowput, allowdelete) VALUES
 (@id,'clientes',1,1,1,0),(@id,'facturaclientes',1,0,0,0),(@id,'telecoFacturaCliente',0,1,0,0),
 (@id,'crearFacturaRectificativaCliente',0,1,0,0),(@id,'pagarFacturaCliente',0,1,0,0),(@id,'exportarFacturaCliente',1,0,0,0),(@id,'productos',1,1,1,0);
