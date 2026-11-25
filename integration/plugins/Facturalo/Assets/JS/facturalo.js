/* Facturalo: botón «ojo» para mostrar u ocultar cualquier campo de contraseña (acceso, cambio de contraseña, perfil, correo). */
(function () {
    'use strict';
    var ICON_SHOW = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
    var ICON_HIDE = '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>';

    function addEye(input) {
        if (input.dataset.flEye === '1') {
            return;
        }
        input.dataset.flEye = '1';
        var group = input.closest('.input-group');
        if (!group) {
            group = document.createElement('div');
            group.className = 'input-group';
            input.parentNode.insertBefore(group, input);
            group.appendChild(input);
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary fl-eye';
        btn.setAttribute('aria-label', 'Mostrar contraseña');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = ICON_SHOW;
        btn.addEventListener('click', function () {
            var visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.innerHTML = visible ? ICON_SHOW : ICON_HIDE;
            btn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
            btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
            input.focus();
        });
        group.appendChild(btn);
    }

    function scan(root) {
        (root || document).querySelectorAll('input[type="password"]').forEach(addEye);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        // modales y vistas que se pintan después (p. ej. «nueva contraseña»)
        new MutationObserver(function () { scan(document); }).observe(document.body, {childList: true, subtree: true});
    });
})();

/* Facturalo: «Nueva cuenta» junto al desplegable de cuenta bancaria de Formas de pago (alta sin salir de la pantalla). */
(function () {
    'use strict';
    function init() {
        var sel = document.querySelector('select[name="codcuentabanco"]');
        var form = sel && sel.closest('form');
        if (!sel || !form || sel.dataset.flNueva === '1' || !form.querySelector('input[name="multireqtoken"]')) {
            return;
        }
        sel.dataset.flNueva = '1';
        var group = sel.closest('.input-group');
        if (!group) {
            group = document.createElement('div');
            group.className = 'input-group';
            sel.parentNode.insertBefore(group, sel);
            group.appendChild(sel);
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-primary';
        btn.title = 'Crear una cuenta bancaria nueva';
        btn.innerHTML = '<i class="fa-solid fa-plus" aria-hidden="true"></i> Nueva';
        group.appendChild(btn);

        var box = document.createElement('div');
        box.className = 'mt-2 p-2 border rounded';
        box.hidden = true;
        box.innerHTML = '<div class="mb-2"><label class="form-label mb-0">IBAN</label>' +
            '<input type="text" class="form-control" data-f="iban" maxlength="34" autocomplete="off"></div>' +
            '<div class="mb-2"><label class="form-label mb-0">Descripción (opcional)</label>' +
            '<input type="text" class="form-control" data-f="descripcion" maxlength="100"></div>' +
            '<div class="text-danger small mb-2" data-f="error" hidden></div>' +
            '<button type="button" class="btn btn-sm btn-primary" data-f="ok">Crear y seleccionar</button> ' +
            '<button type="button" class="btn btn-sm btn-light" data-f="cancel">Cancelar</button>';
        group.parentNode.appendChild(box);
        var q = function (n) { return box.querySelector('[data-f="' + n + '"]'); };
        var err = q('error');

        btn.addEventListener('click', function () { box.hidden = !box.hidden; if (!box.hidden) { q('iban').focus(); } });
        q('cancel').addEventListener('click', function () { box.hidden = true; });
        q('ok').addEventListener('click', function () {
            // token firmado del formulario con sufijo propio: no consume el del guardado
            var fd = new FormData();
            fd.append('action', 'crear-cuenta-banco');
            fd.append('multireqtoken', form.querySelector('input[name="multireqtoken"]').value + 'x' + Date.now());
            fd.append('iban', q('iban').value);
            fd.append('descripcion', q('descripcion').value);
            var emp = form.querySelector('[name="idempresa"]');
            if (emp && emp.value) { fd.append('idempresa', emp.value); }
            err.hidden = true;
            fetch(window.location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (!r.ok) { err.textContent = r.error || 'Error'; err.hidden = false; return; }
                    sel.add(new Option(r.descripcion, r.codcuenta, true, true));
                    sel.value = r.codcuenta;
                    q('iban').value = q('descripcion').value = '';
                    box.hidden = true;
                })
                .catch(function () { err.textContent = 'Error de conexión'; err.hidden = false; });
        });
    }
    document.addEventListener('DOMContentLoaded', init);
})();
