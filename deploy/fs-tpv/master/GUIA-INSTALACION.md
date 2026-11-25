# Guía de instalación del agente MASTER (OWNER, root)
1. Copia la carpeta `deploy/fs-tpv/master/` a `rt-tpv-prod-01` (p. ej. `/root/fs-master/`) por la consola web de Ploi o ssh root.
2. Lee `instalar-todo.sh`: solo crea `fs-master`, `/var/lib/fs-master`, `/etc/fs-master`, `/opt/fs-master`, unidades systemd y un fragmento sshd; no toca tenants, nginx ni `/srv/fs`.
3. Ejecuta `sudo bash instalar-todo.sh` (sshd NO se toca salvo `CONFIGURAR_SSHD=1`, que ademas exige que la configuracion efectiva de `ploi` y `root` no cambie; opcional: `RELEASE_FROM=<ruta a un fs-release.tar.gz>` publica la release `fs-v2026.72-plug10`; `MASTER_PUBKEY`/`MASTER_IP` añaden la clave SFTP del panel).
4. Si falla algo, deshace lo creado y restaura sshd (con `CONFIGURAR_SSHD=1`: `sshd -t` y comparacion de `ploi`/`root` antes de recargar); es idempotente, se puede repetir.
5. Crea `/etc/fs-master/email-template.json` (root 600) con el grupo de ajustes `email` de FS (SMTP noreply, copiado de redtelecom); sin él `provision` falla antes de crear nada.
5. La clave de firma queda en `/etc/fs-master/keys/m1.key` (root 600) y no se imprime: cópiala al gestor de secretos del panel con `sudo cat` en una consola segura.
6. Añade la clave pública SFTP del panel a `/etc/ssh/fs-master.authorized_keys` como `from="<IP>",restrict ssh-ed25519 ...`.
7. Ejecuta `sudo bash verificar.sh`: permisos, `sshd -t`, chroot, `systemd-analyze security` y una petición `status` firmada sobre `zz-verif`.
8. Con todo OK, **no actives aún el temporizador**: el ensayo de alta con un slug de prueba requiere el OK del coordinador.
9. Tras ese OK: `systemctl enable --now fs-master-agent.timer` (parar: `systemctl disable --now fs-master-agent.timer`).
10. Marcha atrás: `systemctl disable --now fs-master-agent.timer; rm -rf /opt/fs-master /etc/systemd/system/fs-master-agent.* /etc/ssh/sshd_config.d/fs-master.conf; sshd -t && systemctl reload ssh`. Los datos de `/var/lib/fs-master` y las claves se conservan.
