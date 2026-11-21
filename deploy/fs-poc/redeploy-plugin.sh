#!/usr/bin/env bash
# Copia el plugin al contenedor y lo reactiva (ciclo de desarrollo del PoC). Ejecutar desde la raíz del repo, dentro de WSL.
set -eu
docker cp integration/plugins/TelecoBridge/. fs-poc-fs-1:/var/www/html/Plugins/TelecoBridge/
docker exec fs-poc-fs-1 sh -c 'chown -R www-data:www-data /var/www/html/Plugins && rm -rf /var/www/html/Dinamic/Controller/ApiTeleco* /var/www/html/MyFiles/Tmp/FileCache'
