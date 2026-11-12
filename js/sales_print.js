/**
 * Sales Report, printed directly (no file to download first).
 *
 *   SalesPrint.print({
 *       generatedAt: new Date(), period: 'All dates',
 *       stats: { daily, monthly, customers, month },
 *       topProducts: [{ product, quantity, total }],
 *       records: [{ order_no, created_at, customer, payment, quantity, total, items: [{ name, quantity }] }]
 *   });
 *
 * It lays out the same report as the PDF (header, summary, most sold, every sales record
 * with a total row) as a print-ready page and opens the browser's print dialog for it.
 * Unlike the PDF, the print page can show the peso sign. The page is written into a hidden
 * frame, so the dashboard itself (charts, filters, buttons) never ends up on the paper and
 * no pop-up window is needed.
 */
(function (global) {
    'use strict';

    function escapeHTML(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
        });
    }

    function whole(value) {
        return (Number(value) || 0).toLocaleString('en-PH');
    }

    function money(value) {
        return '\u20B1' + (Number(value) || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    var STYLE = [
        '@page { size: A4; margin: 14mm 12mm; }',
        '* { box-sizing: border-box; }',
        'html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }',
        'body { margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 10.5px; color: #222; }',
        '.head { display: flex; justify-content: space-between; align-items: flex-end; padding-bottom: 8px; border-bottom: 2px solid #6b4029; margin-bottom: 14px; }',
        '.brand { font-size: 20px; font-weight: 700; color: #6b4029; }',
        '.title { font-size: 13px; font-weight: 700; margin-top: 2px; }',
        '.meta { text-align: right; font-size: 10px; color: #6f6f6f; line-height: 1.5; }',
        '.summary { display: flex; gap: 10px; margin-bottom: 16px; }',
        '.box { flex: 1; border: 1px solid #d4d4d4; border-radius: 6px; padding: 8px 10px; }',
        '.box .label { font-size: 10px; font-weight: 700; }',
        '.box .value { font-size: 16px; font-weight: 700; margin: 4px 0 2px; }',
        '.box .sub { font-size: 9px; color: #6f6f6f; }',
        'h2 { font-size: 13px; margin: 0 0 8px; }',
        '.section { margin-bottom: 16px; }',
        '.muted { color: #6f6f6f; }',
        '.top { width: 100%; border-collapse: collapse; }',
        '.top td { padding: 3px 0; vertical-align: middle; }',
        '.top .name { width: 34%; }',
        '.top .bar-cell { width: 42%; }',
        '.bar { display: inline-block; height: 10px; background: #6b4029; border-radius: 2px; vertical-align: middle; }',
        '.top .sold { font-weight: 700; margin-left: 6px; }',
        '.top .amount { text-align: right; color: #6f6f6f; }',
        'table.records { width: 100%; border-collapse: collapse; }',
        'table.records th { background: #ede6de; text-align: left; font-size: 10px; padding: 6px; }',
        'table.records td { padding: 5px 6px; border-bottom: 1px solid #d4d4d4; vertical-align: top; }',
        'table.records .num { text-align: right; white-space: nowrap; }',
        'table.records .order { font-weight: 700; white-space: nowrap; }',
        'table.records .when { white-space: nowrap; }',
        'table.records ul { margin: 0; padding: 0; list-style: none; }',
        'thead { display: table-header-group; }',   // the header repeats on every page
        'tfoot { display: table-row-group; }',      // the total row is printed once, at the end
        'tr { page-break-inside: avoid; }',
        'table.records tfoot th { background: #ede6de; padding: 7px 6px; }',
        '.empty { padding: 10px 6px; color: #6f6f6f; }'
    ].join('\n');

    function summaryBoxes(stats) {
        return [
            { label: 'Daily Sales', value: money(stats.daily), sub: 'This Day' },
            { label: 'Weekly Sales', value: money(stats.weekly), sub: 'This Week' },
            { label: 'Monthly Sales', value: money(stats.monthly), sub: stats.month || '' },
            { label: 'Total Customers', value: whole(stats.customers), sub: 'This Day' }
        ].map(function (box) {
            return '<div class="box"><div class="label">' + escapeHTML(box.label) + '</div>' +
                '<div class="value">' + escapeHTML(box.value) + '</div>' +
                '<div class="sub">' + escapeHTML(box.sub) + '</div></div>';
        }).join('');
    }

    function mostSold(products) {
        if (!products.length) {
            return '<p class="muted">No completed sales recorded for this period.</p>';
        }

        var max = Math.max.apply(null, products.map(function (p) { return Number(p.quantity) || 0; })) || 1;

        return '<table class="top">' + products.map(function (product, index) {
            var quantity = Number(product.quantity) || 0;
            var width = Math.max(2, Math.round(100 * quantity / max));

            return '<tr><td class="name">' + (index + 1) + '. ' + escapeHTML(product.product) + '</td>' +
                '<td class="bar-cell"><span class="bar" style="width:' + (width * 0.6) + '%"></span>' +
                '<span class="sold">' + whole(quantity) + ' sold</span></td>' +
                '<td class="amount">' + money(product.total) + '</td></tr>';
        }).join('') + '</table>';
    }

    function recordsTable(orders) {
        var head = '<thead><tr><th>Order ID</th><th>Date</th><th>Customer</th><th>Items</th>' +
            '<th class="num">Qty</th><th>Payment</th><th class="num">Total</th></tr></thead>';

        if (!orders.length) {
            return '<table class="records">' + head +
                '<tbody><tr><td colspan="7" class="empty">No completed orders recorded for this period.</td></tr></tbody></table>';
        }

        var totalQuantity = 0;
        var totalSales = 0;

        var rows = orders.map(function (order) {
            totalQuantity += Number(order.quantity) || 0;
            totalSales += Number(order.total) || 0;

            var items = (order.items || []).map(function (item) {
                return '<li>' + whole(item.quantity) + 'x ' + escapeHTML(item.name) + '</li>';
            }).join('');

            return '<tr>' +
                '<td class="order">' + escapeHTML(order.order_no) + '</td>' +
                '<td class="when">' + escapeHTML(String(order.created_at || '').slice(0, 16)) + '</td>' +
                '<td>' + escapeHTML(order.customer) + '</td>' +
                '<td>' + (items ? '<ul>' + items + '</ul>' : '-') + '</td>' +
                '<td class="num">' + whole(order.quantity) + '</td>' +
                '<td>' + escapeHTML(order.payment) + '</td>' +
                '<td class="num">' + money(order.total) + '</td></tr>';
        }).join('');

        var foot = '<tfoot><tr><th colspan="4">Total (' + orders.length + ' order' + (orders.length !== 1 ? 's' : '') + ')</th>' +
            '<th class="num">' + whole(totalQuantity) + '</th><th></th>' +
            '<th class="num">' + money(totalSales) + '</th></tr></tfoot>';

        return '<table class="records">' + head + '<tbody>' + rows + '</tbody>' + foot + '</table>';
    }

    /** The whole print page as HTML text. */
    function buildHtml(options) {
        var generated = options.generatedAt instanceof Date ? options.generatedAt : new Date();
        var stats = options.stats || {};

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
            '<title>D&amp;JC Food Products - Sales Report</title><style>' + STYLE + '</style></head><body>' +
            '<div class="head"><div><div class="brand">D&amp;JC Food Products</div><div class="title">Sales Report</div></div>' +
            '<div class="meta">Generated: ' + escapeHTML(generated.toLocaleString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
            })) + '<br>Period: ' + escapeHTML(options.period || 'All dates') + '</div></div>' +
            '<div class="summary">' + summaryBoxes(stats) + '</div>' +
            '<div class="section"><h2>Most Sold Products</h2>' + mostSold(options.topProducts || []) + '</div>' +
            '<div class="section"><h2>Sales Records</h2>' + recordsTable(options.records || []) + '</div>' +
            '</body></html>';
    }

    /** Write the report into a hidden frame and open the print dialog. */
    function print(options) {
        return new Promise(function (resolve, reject) {
            var frame = document.createElement('iframe');
            var done = false;

            frame.setAttribute('aria-hidden', 'true');
            frame.tabIndex = -1;
            frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';

            function cleanup() {
                if (frame.parentNode) frame.parentNode.removeChild(frame);
            }

            function finish(error) {
                if (done) return;
                done = true;

                if (error) {
                    cleanup();
                    reject(error);
                } else {
                    resolve();
                }
            }

            frame.onload = function () {
                // A frame can report a load more than once (e.g. its empty starting page).
                // Only the first load that has the report in it is printed.
                if (done) return;

                var win = frame.contentWindow;

                if (win && win.location.href === 'about:blank') return;

                if (!win) {
                    finish(new Error('Unable to open the print preview.'));
                    return;
                }

                // Remove the frame once printing is finished or cancelled.
                win.onafterprint = cleanup;

                // Some browsers return from print() at once, so the frame is also
                // removed after a while instead of straight away.
                setTimeout(cleanup, 120000);

                try {
                    win.focus();
                    win.print();
                    finish();
                } catch (error) {
                    finish(error);
                }
            };

            // The report goes in BEFORE the frame is attached, so the frame never loads an empty
            // page first (which would print a blank sheet).
            frame.srcdoc = buildHtml(options);
            document.body.appendChild(frame);
        });
    }

    global.SalesPrint = { print: print, buildHtml: buildHtml };
})(window);
