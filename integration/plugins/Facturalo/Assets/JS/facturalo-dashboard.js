/* Facturalo · gráficas del inicio (Chart.js 2.x local). Datos de FacturaloStats (JSON). Cada gráfica lleva su tabla alternativa. */
(function () {
    'use strict';
    var BLUE = '#152D56', RED = '#D60000', GREY = '#8C97AB', INK = '#1C1C1C';
    var root = document.getElementById('flCharts');
    if (!root || typeof Chart === 'undefined') { return; }

    function eur(v, cur) {
        try { return new Intl.NumberFormat('es-ES', {style: 'currency', currency: cur || 'EUR', maximumFractionDigits: 0}).format(v); }
        catch (e) { return Math.round(v) + ' ' + cur; }
    }
    function el(id) { return document.getElementById(id); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function empty(card, msg) {
        card.querySelector('.fl-canvas').hidden = true;
        var p = card.querySelector('.fl-empty');
        p.textContent = msg || 'Aún no hay facturas';
        p.hidden = false;
    }
    function table(id, head, rows) {
        el(id).innerHTML = '<caption>' + head[0] + '</caption><thead><tr><th scope="col">' + head[1] +
            '</th><th scope="col">' + head[2] + '</th></tr></thead><tbody>' + rows.map(function (r) {
                return '<tr><th scope="row">' + r[0] + '</th><td>' + r[1] + '</td></tr>';
            }).join('') + '</tbody>';
    }
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    Chart.defaults.global.defaultFontFamily = getComputedStyle(document.body).fontFamily;
    Chart.defaults.global.defaultFontColor = INK;

    function render(d) {
        var cur = d.currency;
        // 1) ventas mensuales
        var m = d.monthly, c1 = el('flChartMonthly').closest('.fl-chart');
        var labels = m.map(function (x) { return MESES[+x.month.slice(5) - 1] + ' ' + x.month.slice(2, 4); });
        if (!m.some(function (x) { return x.total !== 0; })) {
            empty(c1);
        } else {
            new Chart(el('flChartMonthly'), {
                type: 'bar',
                data: {labels: labels, datasets: [{data: m.map(function (x) { return x.total; }), backgroundColor: BLUE}]},
                options: {maintainAspectRatio: false, legend: {display: false},
                    scales: {yAxes: [{ticks: {beginAtZero: true, callback: function (v) { return eur(v, cur); }}}]},
                    tooltips: {callbacks: {label: function (t) { return eur(t.yLabel, cur); }}}}
            });
            table('flTableMonthly', ['Ventas mensuales (sin IVA)', 'Mes', 'Importe'],
                m.map(function (x, i) { return [labels[i], eur(x.total, cur)]; }));
        }
        // 2) estado de facturas
        var s = d.status, c2 = el('flChartStatus').closest('.fl-chart');
        var vals = [s.paid, s.pending, s.overdue], names = ['Cobradas', 'Pendientes', 'Vencidas'];
        if (!(vals[0] + vals[1] + vals[2])) {
            empty(c2);
        } else {
            new Chart(el('flChartStatus'), {
                type: 'doughnut',
                data: {labels: names, datasets: [{data: vals, backgroundColor: [BLUE, GREY, RED], borderWidth: 2}]},
                options: {maintainAspectRatio: false, cutoutPercentage: 62, legend: {position: 'bottom'}}
            });
            table('flTableStatus', ['Facturas por estado (ejercicio actual)', 'Estado', 'Facturas'],
                names.map(function (n, i) { return [n, vals[i]]; }));
        }
        // 3) top clientes
        var t = d.top, c3 = el('flChartTop').closest('.fl-chart');
        if (!t.length) {
            empty(c3);
        } else {
            new Chart(el('flChartTop'), {
                type: 'horizontalBar',
                data: {labels: t.map(function (x) { return x.name; }), datasets: [{data: t.map(function (x) { return x.total; }), backgroundColor: BLUE}]},
                options: {maintainAspectRatio: false, legend: {display: false},
                    scales: {xAxes: [{ticks: {beginAtZero: true, callback: function (v) { return eur(v, cur); }}}]},
                    tooltips: {callbacks: {label: function (x) { return eur(x.xLabel, cur); }}}}
            });
            table('flTableTop', ['Top 5 clientes por facturación (ejercicio actual)', 'Cliente', 'Facturado'],
                t.map(function (x) { return [esc(x.name), eur(x.total, cur)]; }));
        }
    }

    function fail() {
        root.querySelectorAll('.fl-chart').forEach(function (c) { empty(c, 'No se han podido cargar los datos. Recarga la página.'); });
    }

    fetch('FacturaloStats', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
        .then(function (d) { try { render(d); } catch (e) { fail(); } })
        .catch(fail);
})();
