#!/usr/bin/env bash
# Instala FacturaScripts sin asistente (PoC FS-001, datos FICTICIOS). Idempotente en lo posible.
set -eu
B=${FS_URL:-http://127.0.0.1:8085}; U=${FS_ADMIN:-admin}; P=${FS_ADMIN_PASS:-poc-admin-local}; CJ=$(mktemp)
tok() { grep -oE 'multireqtoken(=|" value=")[^"&]+' | head -1 | sed -E 's/.*(=|value=")//'; }
curl -s -m 300 -X POST "$B/" -d "unattended=1&fs_db_host=db&fs_db_name=facturascripts&fs_db_user=root&fs_db_pass=${FS_DB_PASS:-poc-root-local}&fs_db_port=3306&fs_db_type=mysql&fs_initial_user=$U&fs_initial_pass=$P&fs_lang=es_ES&fs_timezone=Europe/Madrid" | grep -q OK || echo "(ya instalado)"
T=$(curl -s -c $CJ -b $CJ "$B/login" | tok)
T=$(curl -s -L -c $CJ -b $CJ -m 400 --data-urlencode "multireqtoken=$T" -d "action=login&fsNick=$U&fsPassword=$P" "$B/login" | tok)
R=$(curl -s -c $CJ -b $CJ -m 400 --data-urlencode "multireqtoken=$T" -d "action=step1&codpais=ESP&empresa=Gestoria%20Ficticia%20SL&direccion=Calle%20Falsa%201&ciudad=Madrid&provincia=Madrid&codpostal=28001&email=poc@example.invalid" "$B/Wizard")
T=$(echo "$R" | tok)
curl -s -c $CJ -b $CJ -m 600 -D /tmp/fsh.$$ -o /dev/null --data-urlencode "multireqtoken=$T" -d "action=step2&cifnif=B00000000&tipoidfiscal=CIF&regimeniva=General&defaultplan=1&invoice_start_number=1" "$B/Wizard"
T=$(grep -i '^refresh' /tmp/fsh.$$ | sed -E 's/.*multireqtoken=//' | tr -d '\r\n'); rm -f /tmp/fsh.$$
curl -s -c $CJ -b $CJ -m 900 -o /dev/null -w "step3:%{http_code}\n" "$B/Wizard?action=step3&multireqtoken=$T"
# activa el plugin TelecoBridge (token CSRF de la propia página de plugins)
T=$(curl -s -c $CJ -b $CJ "$B/AdminPlugins" | tok)
curl -s -c $CJ -b $CJ -m 900 -o /dev/null -w "plugin-enable:%{http_code}\n" --data-urlencode "multireqtoken=$T" -d "action=enable&plugin=TelecoBridge" "$B/AdminPlugins"
rm -f $CJ
