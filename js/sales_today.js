/**
 * Staff view of the Sales page: TODAY's completed orders, as a table. Nothing else.
 *
 * The server (get_sales.php) decides what a staff account may see: only today's
 * records, no charts, no monthly totals. This script just shows that table,
 * lets staff search it, and refreshes it every 30 seconds so new sales appear.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var REFRESH_MS = 30000;

    var alertBox = document.getElementById('alertBox');
    var searchInput = document.getElementById('salesSearchInput');
    var countLabel = document.getElementById('salesRecordCount');
    var body = document.getElementById('salesTableBody');
    var foot = document.getElementById('salesTableFoot');

    var records = [];

    function escapeHTML(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
        });
    }

    function peso(value) {
        return '\u20B1' + (Number(value) || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Today's records: show just the time ("10:15 AM"), the day is in the page heading.
    function formatTime(value) {
        var m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);

        if (!m) return String(value || '');

        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5])
            .toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function showError(message) {
        if (!alertBox) return;

        alertBox.textContent = message;
        alertBox.className = 'alert-box error';
        alertBox.classList.remove('hidden');
    }

    function clearError() {
        if (alertBox) alertBox.classList.add('hidden');
    }

    function matches(order, term) {
        if (!term) return true;

        var text = [order.order_no, order.customer, order.payment]
            .concat((order.items || []).map(function (item) { return item.name; }))
            .join(' ')
            .toLowerCase();

        return text.indexOf(term.replace(/^#/, '')) !== -1;
    }

    function render() {
        var term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var orders = records.filter(function (order) { return matches(order, term); });

        if (countLabel) {
            countLabel.textContent = 'Showing ' + orders.length + ' of ' + records.length +
                ' order' + (records.length !== 1 ? 's' : '') + ' today';
        }

        if (orders.length === 0) {
            body.innerHTML =
                '<tr><td colspan="7" class="sales-empty">' +
                (term ? 'No orders match your search.' : 'No completed orders yet today.') +
                '</td></tr>';
            foot.innerHTML = '';
            return;
        }

        body.innerHTML = orders.map(function (order) {
            var items = (order.items || []).map(function (item) {
                return '<li><span class="item-qty">' + Number(item.quantity).toLocaleString('en-PH') +
                    '&times;</span> ' + escapeHTML(item.name) + '</li>';
            }).join('');

            return '<tr>' +
                '<td class="order-cell">' + escapeHTML(order.order_no) + '</td>' +
                '<td class="date-cell">' + escapeHTML(formatTime(order.created_at)) + '</td>' +
                '<td>' + escapeHTML(order.customer) + '</td>' +
                '<td class="items-cell">' + (items ? '<ul class="order-items">' + items + '</ul>' : '&mdash;') + '</td>' +
                '<td class="num">' + Number(order.quantity).toLocaleString('en-PH') + '</td>' +
                '<td>' + escapeHTML(order.payment) + '</td>' +
                '<td class="num">' + peso(order.total) + '</td>' +
                '</tr>';
        }).join('');

        var totalQty = orders.reduce(function (sum, o) { return sum + Number(o.quantity); }, 0);
        var totalSales = orders.reduce(function (sum, o) { return sum + Number(o.total); }, 0);

        foot.innerHTML =
            '<tr><th scope="row" colspan="4">Total (' + orders.length + ' order' + (orders.length !== 1 ? 's' : '') + ')</th>' +
            '<td class="num">' + totalQty.toLocaleString('en-PH') + '</td><td></td>' +
            '<td class="num">' + peso(totalSales) + '</td></tr>';
    }

    async function load() {
        try {
            var response = await fetch('../php/get_sales.php', { cache: 'no-store' });
            var data = await response.json();

            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to load today\u2019s sales.');
            }

            records = data.report || [];
            clearError();
            render();
        } catch (error) {
            showError(error.message || 'Unable to load today\u2019s sales.');

            if (countLabel && records.length === 0) countLabel.textContent = '';
        }
    }

    if (searchInput) searchInput.addEventListener('input', render);

    load();
    setInterval(load, REFRESH_MS);
});
