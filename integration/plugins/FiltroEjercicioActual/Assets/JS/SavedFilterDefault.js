/**
 * Adds a toggleable star next to FacturaScripts native saved-filter buttons.
 * The star submits the normal list form, so CSRF protection and the selected
 * tab continue to be handled by FacturaScripts itself.
 */
document.addEventListener('DOMContentLoaded', function () {
    const supportedViews = new Set([
        'ListAlbaranCliente', 'ListAlbaranProveedor',
        'ListFacturaCliente', 'ListFacturaProveedor',
        'ListPedidoCliente', 'ListPedidoProveedor',
        'ListPresupuestoCliente', 'ListPresupuestoProveedor',
        'ListReciboCliente', 'ListReciboProveedor'
    ]);

    function defaultFilterId(viewName) {
        return Array.from(document.querySelectorAll('button[onclick*="listViewSetLoadFilter"]')).find(function (button) {
            const match = button.getAttribute('onclick').match(/listViewSetLoadFilter\('([^']+)',\s*'([^']+)'\)/);
            return match !== null && match[1] === viewName
                && button.dataset.isDefaultFilter === '1';
        });
    }

    document.querySelectorAll('button[onclick*="listViewSetLoadFilter"]').forEach(function (filterButton) {
        const match = filterButton.getAttribute('onclick').match(/listViewSetLoadFilter\('([^']+)',\s*'([^']+)'\)/);
        if (match === null || !supportedViews.has(match[1]) || filterButton.dataset.defaultFilterStar === '1') {
            return;
        }

        const viewName = match[1];
        const filterId = match[2];
        const isDefault = filterButton.textContent.trim().startsWith('★')
            || filterButton.textContent.trim().endsWith('★');

        // The filter itself keeps its normal click: it loads the saved
        // filter. Its embedded star only changes the default state.
        filterButton.childNodes.forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                node.textContent = node.textContent.replace(/^\s*★\s*/, '').replace(/\s*★\s*$/, '');
            }
        });
        filterButton.classList.add('position-relative', 'pe-5');
        const star = document.createElement('span');
        star.className = 'fs-default-filter-star position-absolute top-50 end-0 translate-middle-y me-2'
            + (isDefault ? ' text-warning' : ' text-muted');
        star.dataset.viewName = viewName;
        star.dataset.filterId = filterId;
        star.dataset.isDefault = isDefault ? '1' : '0';
        star.title = isDefault ? 'Quitar como filtro predeterminado' : 'Usar como filtro predeterminado';
        star.innerHTML = '<i class="fa-solid fa-star fa-fw" aria-hidden="true"></i>';
        filterButton.dataset.defaultFilterStar = '1';
        filterButton.dataset.isDefaultFilter = isDefault ? '1' : '0';
        filterButton.appendChild(star);

        // FacturaScripts marks the selected saved filter as active. Make that
        // state explicit so it is immediately clear that its conditions are
        // currently applied to the list.
        if (filterButton.classList.contains('active')) {
            filterButton.classList.remove('btn-light');
            filterButton.classList.add('btn-success');
            filterButton.title = (filterButton.title ? filterButton.title + ' — ' : '') + 'Filtro aplicado';
        }
    });

    document.addEventListener('click', function (event) {
        const star = event.target.closest('.fs-default-filter-star');
        if (!star) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        const form = document.getElementById('form' + star.dataset.viewName);
        if (!form) {
            return;
        }

        form.querySelector('input[name="loadfilter"]').value = star.dataset.filterId;
        form.querySelector('input[name="action"]').value = star.dataset.isDefault === '1'
            ? 'clear-default-filter'
            : 'set-default-filter';
        if (typeof animateSpinner === 'function') {
            animateSpinner('add');
        }
        form.submit();
    }, true);

    // Intercept the tab before Bootstrap displays it. The native list form is
    // submitted with the saved filter, avoiding an unfiltered flash followed
    // by a second refresh.
    document.addEventListener('click', function (event) {
        const tab = event.target.closest('a[data-bs-toggle="tab"][href^="#"]');
        if (!tab) {
            return;
        }

        const viewName = tab.getAttribute('href').substring(1);
        if (!supportedViews.has(viewName)) {
            return;
        }

        const button = defaultFilterId(viewName);
        if (!button) {
            return;
        }

        const match = button.getAttribute('onclick').match(/listViewSetLoadFilter\('([^']+)',\s*'([^']+)'\)/);
        if (!match) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        listViewSetLoadFilter(viewName, match[2]);
    }, true);
});
