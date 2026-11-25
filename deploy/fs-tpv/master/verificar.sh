#!/usr/bin/env bash
# Comprobacion tras instalar-todo.sh. COMO ROOT. No modifica tenants, nginx ni sshd. Unica escritura: una peticion `status` firmada
# sobre el slug de prueba zz-verif (no existe; solo lectura de estado) que se procesa, se comprueba y se borra. Aborta si la cola no esta vacia.
set -uo pipefail
[[ $EUID -eq 0 ]] || { echo "ejecutar como root" >&2; exit 1; }
FAIL=0
ok() { echo "OK    $*"; }
ko() { echo "FALLO $*"; FAIL=1; }
info() { echo "INFO  $*"; }
Q=/var/lib/fs-master/sftp
KEY=/etc/fs-master/keys/m1.key

[[ -f /opt/fs-master/agent.php && -f /opt/fs-master/lib.php ]] && ok "agente en /opt/fs-master" || ko "agente no instalado"
[[ "$(stat -c '%U:%a' "$KEY" 2>/dev/null)" == "root:600" ]] && ok "m1.key root 600" || ko "m1.key ausente o con permisos distintos de root:600"
[[ "$(stat -c %s "$KEY" 2>/dev/null || echo 0)" -ge 32 ]] && ok "m1.key >= 32 bytes" || ko "m1.key demasiado corta"
for p in "$Q/requests:3770:root:fs-master" "$Q/responses:750:root:fs-master" "$Q:755:root:root"; do
  IFS=: read -r d m u g <<<"$p"
  [[ "$(stat -c '%a:%U:%G' "$d" 2>/dev/null)" == "$m:$u:$g" ]] && ok "$d $m $u:$g" || ko "$d: esperado $m $u:$g, hay $(stat -c '%a:%U:%G' "$d" 2>/dev/null)"
done
[[ "$(getent passwd fs-master | cut -d: -f7)" == /usr/sbin/nologin ]] && ok "fs-master sin shell" || ko "fs-master con shell"
sshd -t && ok "sshd -t" || ko "sshd -t"
EFF="$(sshd -T -C user=fs-master,host=x,addr=127.0.0.1 2>/dev/null)"
if [[ -e /etc/ssh/sshd_config.d/fs-master.conf ]] || grep -q "^# fs-master-begin" /etc/ssh/sshd_config; then
grep -qi '^chrootdirectory /var/lib/fs-master/sftp' <<<"$EFF" && ok "sshd: chroot SFTP para fs-master" || ko "sshd: no se aplica el Match de fs-master"
OTHER="$(sshd -T -C user=ploi,host=x,addr=127.0.0.1 2>/dev/null)"
grep -qi '^chrootdirectory none' <<<"$OTHER" && ok "sshd: ploi NO afectado por el Match" || ko "sshd: el Match afecta a otros usuarios"
else info "sshd sin configurar para fs-master (opt-in CONFIGURAR_SSHD=1; pendiente hasta que exista el panel)"; fi
if systemctl is-enabled fs-master-agent.timer >/dev/null 2>&1; then info "temporizador ACTIVADO"; else ok "temporizador sin activar (esperado antes del OK)"; fi
if [[ "$(stat -c '%U:%a' /etc/fs-master/email-template.json 2>/dev/null)" == "root:600" ]]; then ok "plantilla de correo /etc/fs-master/email-template.json root 600"; else ko "falta /etc/fs-master/email-template.json (root 600): sin ella provision falla en preflight"; fi
[[ "$(stat -c '%U:%a' /var/log/fs-master 2>/dev/null)" == "root:700" ]] && ok "/var/log/fs-master root 700 (salida completa de scripts fallidos)" || ko "falta /var/log/fs-master (root 700): repetir instalar-todo.sh"
[[ "$(stat -c '%U:%a' /usr/local/sbin/fs-enable-plugins 2>/dev/null)" == "root:750" ]] && ok "fs-enable-plugins instalado (paso plugins)" || ko "falta /usr/local/sbin/fs-enable-plugins (root 750): repetir instalar-todo.sh"
if [[ -e /etc/ssh/fs-master.authorized_keys ]]; then runuser -u fs-master -- test -r /etc/ssh/fs-master.authorized_keys && ok "fs-master puede leer /etc/ssh/fs-master.authorized_keys (sshd lo lee como ese usuario)" || ko "fs-master NO puede leer su authorized_keys: sshd rechazara su clave"; fi
info "claves SFTP autorizadas: $(grep -c . /etc/ssh/fs-master.authorized_keys 2>/dev/null || echo 0)"
TAG="$(cat /etc/fs-master/default-release 2>/dev/null)"
if [[ -f "/home/ploi/fs-releases/$TAG.tar.gz" && -f "/home/ploi/fs-releases/$TAG.tar.gz.sha256" ]]; then info "release por defecto $TAG: disponible"; else info "release por defecto $TAG: NO publicada (necesaria para provision)"; fi

echo "--- systemd-analyze security (exposicion de la unidad) ---"
systemd-analyze security fs-master-agent.service --no-pager 2>&1 | tail -3 || info "systemd-analyze no disponible"

echo "--- peticion status firmada (slug de prueba zz-verif) ---"
if [[ -n "$(ls -A "$Q/requests" 2>/dev/null)$(ls -A "$Q/responses" 2>/dev/null)" ]]; then
  ko "la cola no esta vacia: no se prueba (puede haber peticiones reales)"
else
  ID="$(php -r '$a="0123456789ABCDEFGHJKMNPQRSTVWXYZ";$s="";for($i=0;$i<26;$i++)$s.=$a[random_int(0,31)];echo $s;')"
  php -r 'require "/opt/fs-master/lib.php"; [$id,$key,$q]=[$argv[1],rtrim(file_get_contents($argv[2])),$argv[3]];
    $r=["version"=>1,"request_id"=>$id,"action"=>"status","tenant"=>["id"=>"01JD000000000000000000VERF","slug"=>"zz-verif","hostname"=>"zz-verif.facturalo.redtelecom.app"],"requested_at"=>gmdate("Y-m-d\TH:i:s\Z"),"requested_by"=>"verificar.sh"];
    $r["sig"]=["alg"=>"hmac-sha256","kid"=>"m1","value"=>FsMaster\sign($r,$key,"req")];
    file_put_contents("$q/requests/.$id.json.tmp",json_encode($r)); rename("$q/requests/.$id.json.tmp","$q/requests/$id.json");' "$ID" "$KEY" "$Q"
  php /opt/fs-master/agent.php --conf=/etc/fs-master/agent.json
  if [[ -f "$Q/responses/$ID.json" ]]; then
    if php -r 'require "/opt/fs-master/lib.php"; $r=json_decode(file_get_contents($argv[1]),true); $k=["m1"=>rtrim(file_get_contents($argv[2]))];
      $v=FsMaster\verify($r,$k,"resp"); echo ($v===null?"firma de respuesta valida":"FIRMA INVALIDA: $v"),"; status=",$r["status"],"; estado=",$r["tenant_state"],"\n";
      exit($v===null&&$r["status"]==="succeeded"?0:1);' "$Q/responses/$ID.json" "$KEY"; then ok "status firmado procesado y respuesta verificada"
    else ko "respuesta incorrecta ($Q/responses/$ID.json)"; fi
    rm -f "$Q/responses/$ID.json"
  else ko "el agente no genero respuesta"; fi
fi
if [[ $FAIL -eq 0 ]]; then echo "RESULTADO: todo OK (el temporizador sigue sin activar)"; else echo "RESULTADO: hay FALLOS"; fi
exit $FAIL
