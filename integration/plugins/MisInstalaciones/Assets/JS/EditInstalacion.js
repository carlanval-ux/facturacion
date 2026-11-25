/**
 * Pestañas remotas de EditInstalacion: todo se pide por AJAX al propio controlador, que es quien llama a la API remota.
 */
document.addEventListener('DOMContentLoaded', () => {
    const paneles = document.querySelectorAll('.instalacion-remota');
    if (paneles.length === 0) {
        return;
    }

    const cfg = paneles[0].dataset;
    const cargadas = new Set();
    let token = cfg.token;

    const enlace = vista => document.querySelector('#mainTabs a[href="#' + vista + '"]');
    const panel = vista => document.querySelector('.instalacion-remota[data-vista="' + vista + '"]');
    const activa = () => (document.querySelector('#mainTabs a.active')?.getAttribute('href') ?? '').slice(1);
    const escapar = texto => {
        const div = document.createElement('div');
        div.textContent = texto ?? '';
        return div.innerHTML;
    };
    const quitarSpinner = () => document.querySelectorAll('#messages-toasts .toast-spinner').forEach(t => t.remove());

    function peticion(datos) {
        return fetch(cfg.url, {method: 'POST', body: new URLSearchParams({code: cfg.code, ...datos})})
            .then(respuesta => {
                if ((respuesta.headers.get('Content-Type') ?? '').includes('application/json')) {
                    return respuesta.json();
                }
                // un 200 sin JSON es la página de login (sesión caducada); cualquier otro código es un fallo del servidor
                return {status: 'error', message: respuesta.ok ? cfg.sesion : cfg.fallo + ' (HTTP ' + respuesta.status + ')'};
            })
            .then(json => {
                token = json.token ?? token;
                return json;
            })
            .catch(() => ({status: 'error', message: cfg.error}));
    }

    function mostrarVisor(visible) {
        ['ErroresInstalacion', 'LogsBdInstalacion'].forEach(vista => {
            enlace(vista)?.parentElement.classList.toggle('d-none', !visible);
        });
        if (!visible && ['ErroresInstalacion', 'LogsBdInstalacion'].includes(activa())) {
            bootstrap.Tab.getOrCreateInstance(enlace('PluginsInstalacion')).show();
        }
    }

    function cargar(vista) {
        if (!panel(vista)) {
            return Promise.resolve();
        }

        cargadas.add(vista);
        return peticion({action: 'remoto-cargar', seccion: vista}).then(json => {
            if (json.status !== 'success') {
                panel(vista).innerHTML = '<div class="alert alert-warning mb-0">' + escapar(json.message) + '</div>';
                return json;
            }

            panel(vista).innerHTML = json.html;
            mostrarVisor(json.visorErrores);
            return json;
        });
    }

    function accion(datos, boton = null) {
        if (boton) {
            boton.disabled = true;
        }
        setToast('', 'spinner', '', 0);

        return peticion({...datos, multireqtoken: token}).then(json => {
            quitarSpinner();
            setToast(escapar(json.message), json.status === 'success' ? 'success' : 'danger', '', json.status === 'success' ? 5000 : 0);
            if (boton) {
                boton.disabled = false;
            }
            return json;
        });
    }

    window.instalacionTestConexion = () => accion({action: 'remoto-test'});

    // botones e interruptores de los fragmentos remotos (data-accion, data-confirm y el resto de data-* como parámetros)
    document.addEventListener('click', evento => {
        const boton = evento.target.closest('.instalacion-remota [data-accion]');
        if (!boton) {
            return;
        }

        evento.preventDefault();
        const {accion: action, confirm: pregunta, ...datos} = boton.dataset;
        if (pregunta && !confirm(pregunta)) {
            return;
        }

        accion({action: action, ...datos}, boton).then(json => {
            if (json.status === 'success') {
                cargadas.clear();
                cargar(activa());
            }
        });
    });

    document.getElementById('mainTabs')?.addEventListener('shown.bs.tab', evento => {
        const vista = evento.target.getAttribute('href').slice(1);
        if (!cargadas.has(vista)) {
            cargar(vista);
        }
    });

    // petición inicial: los plugins indican si hay conexión y si se muestran las pestañas de VisorErrores.
    // Se lanza antes de ocultar esas pestañas: ocultarlas activa Plugins y, si aún no constara como cargada,
    // el evento shown.bs.tab pediría la misma pestaña otra vez en paralelo.
    const inicial = cargar('PluginsInstalacion');
    mostrarVisor(false);
    inicial.then(json => {
        if (json && json.status !== 'success') {
            setToast(escapar(json.message), 'warning', '', 0);
        }
        if (activa() !== 'PluginsInstalacion' && panel(activa())) {
            cargar(activa());
        }
    });
});
