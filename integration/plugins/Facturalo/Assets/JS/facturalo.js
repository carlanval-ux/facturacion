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
