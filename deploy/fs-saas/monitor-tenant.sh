#!/usr/bin/env bash
# JSON de estado; exit 1 si algo falla (cron/alertas). TENANT=x [MAX_BACKUP_AGE_H=26] [MAX_VOL_MB=5000] bash monitor-tenant.sh
. "$(dirname "$0")/lib.sh"; need_tenant "${1:-}"
[ -f "$SDIR/compose.env" ] || { echo "{\"tenant\":\"$TENANT\",\"ok\":false,\"error\":\"tenant inexistente\"}"; exit 1; }
load_env
MAXAGE=${MAX_BACKUP_AGE_H:-26}; MAXVOL=${MAX_VOL_MB:-5000}
st() { docker inspect -f '{{.State.Status}}{{if .State.Health}}/{{.State.Health.Status}}{{end}}' "$PROJ-$1-1" 2>/dev/null || echo missing; }
DB_ST=$(st db); FS_ST=$(st fs)
CODE=$((10#$(api_code)))
# la hora de las reservas la escribe PHP en la zona de FS (Europe/Madrid): el corte se calcula allí
CUT=$(dc exec -T fs php -r "date_default_timezone_set('Europe/Madrid'); echo date('Y-m-d H:i:s', time()-600);" 2>/dev/null || true)
PEND=$(dbq_e "SELECT COUNT(*) FROM teleco_idempotencia WHERE estado='pending' AND creado < '$CUT'" || true); PEND=${PEND:-0}
[[ $PEND =~ ^[0-9]+$ ]] || PEND=-1
DB_MB=$(dc exec -T db du -sk /var/lib/mysql 2>/dev/null | cut -f1 || true); DB_MB=$(( ${DB_MB:-0} / 1024 ))
FS_MB=$(dc exec -T fs du -sk /var/www/html/MyFiles 2>/dev/null | cut -f1 || true); FS_MB=$(( ${FS_MB:-0} / 1024 ))
LAST=$(ls -1d "$BACKUP_DIR/$TENANT"/*/ 2>/dev/null | sort | tail -1 || true)
if [ -n "$LAST" ]; then AGE_H=$(( ( $(date +%s) - $(date -d "$(jq -r .date "$LAST/manifest.json")" +%s) ) / 3600 )); else AGE_H=-1; fi
J=$(jq -n --arg t "$TENANT" --arg dbs "$DB_ST" --arg fss "$FS_ST" --argjson code "$CODE" --argjson pend "$PEND" \
  --argjson dbmb "$DB_MB" --argjson fsmb "$FS_MB" --argjson age "$AGE_H" --argjson maxage "$MAXAGE" --argjson maxvol "$MAXVOL" --arg port "$FS_PORT" '
  {tenant:$t, port:($port|tonumber), time:(now|todate),
   containers:{db:$dbs, fs:$fss},
   api:{http:$code},
   idempotencia_pending_antiguas:$pend,
   volumes_mb:{db:$dbmb, myfiles:$fsmb},
   last_backup_age_h:$age,
   checks:{containers:($dbs=="running/healthy" and ($fss|startswith("running"))), api:($code==200),
           pending:($pend==0), volumes:($dbmb<$maxvol and $fsmb<$maxvol), backup:($age>=0 and $age<=$maxage)}}
  | .ok = (.checks | all)')
echo "$J"; jq -e .ok <<<"$J" >/dev/null
