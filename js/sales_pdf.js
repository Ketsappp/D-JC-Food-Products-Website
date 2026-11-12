/**
 * Sales Report as a PDF file, built in the browser (no libraries needed).
 *
 *   const blob = SalesPdf.build({
 *       generatedAt: new Date(),
 *       period: 'All dates',
 *       stats: { daily: '544.00', monthly: '544.00', customers: 5, month: 'November 2026' },
 *       topProducts: [{ product, quantity, total }],
 *       records: [{ order_no, created_at, customer, payment, quantity, total,
 *                   items: [{ name, quantity }] }]   // one per completed order
 *   });
 *
 * The PDF uses the standard Helvetica fonts, so the peso sign is written as
 * "PHP" (Helvetica has no peso symbol) and characters outside Latin-1 become "?".
 */
(function (global) {
    'use strict';

    const PAGE_WIDTH = 595.28;   // A4, in points
    const PAGE_HEIGHT = 841.89;
    const MARGIN = 40;
    const CONTENT_WIDTH = PAGE_WIDTH - MARGIN * 2;
    const FOOTER_SPACE = 36;

    // Colours (RGB, 0-1)
    const INK = [0.13, 0.13, 0.13];
    const MUTED = [0.45, 0.45, 0.45];
    const BROWN = [0.42, 0.25, 0.16];
    const HEADER_FILL = [0.93, 0.9, 0.87];
    const LINE = [0.82, 0.82, 0.82];

    // Width of the characters 32..126 in Helvetica / Helvetica-Bold (1/1000 em).
    const HELVETICA = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584
    ];

    const HELVETICA_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584
    ];

    const REPLACEMENTS = {
        '\u20b1': 'PHP ', '\u2013': '-', '\u2014': '-', '\u2018': "'", '\u2019': "'",
        '\u201c': '"', '\u201d': '"', '\u2022': '*', '\u2026': '...', '\u00d7': 'x',
        '\u00a0': ' ', '\t': ' ', '\n': ' ', '\r': ' '
    };

    // ---------- text helpers ----------

    // Keep only what the standard PDF fonts can show.
    function toPdfText(value) {
        let out = '';

        for (const character of String(value === null || value === undefined ? '' : value)) {
            if (REPLACEMENTS[character] !== undefined) {
                out += REPLACEMENTS[character];
                continue;
            }

            const code = character.codePointAt(0);

            out += (code >= 32 && code <= 126) || (code >= 161 && code <= 255)
                ? character
                : '?';
        }

        return out;
    }

    function escapePdf(text) {
        return text.replace(/[\\()]/g, '\\$&');
    }

    function measure(text, size, bold) {
        const widths = bold ? HELVETICA_BOLD : HELVETICA;
        let total = 0;

        for (let i = 0; i < text.length; i++) {
            const code = text.charCodeAt(i);
            total += code >= 32 && code <= 126 ? widths[code - 32] : 556;
        }

        return total * size / 1000;
    }

    // Cut a text with "..." so it fits one line.
    function fit(text, size, bold, maxWidth) {
        if (measure(text, size, bold) <= maxWidth) return text;

        let cut = text.length;

        while (cut > 1 && measure(text.slice(0, cut) + '...', size, bold) > maxWidth) {
            cut--;
        }

        return text.slice(0, cut) + '...';
    }

    // Split a text into lines that fit maxWidth (long words are broken).
    function wrap(text, size, bold, maxWidth) {
        const lines = [];
        let line = '';

        text.split(' ').forEach(function (rawWord) {
            let word = rawWord;

            while (measure(word, size, bold) > maxWidth) {
                let cut = word.length;

                while (cut > 1 && measure(word.slice(0, cut), size, bold) > maxWidth) {
                    cut--;
                }

                if (line) {
                    lines.push(line);
                    line = '';
                }

                lines.push(word.slice(0, cut));
                word = word.slice(cut);
            }

            const candidate = line ? line + ' ' + word : word;

            if (measure(candidate, size, bold) <= maxWidth) {
                line = candidate;
            } else {
                if (line) lines.push(line);
                line = word;
            }
        });

        if (line) lines.push(line);

        return lines.length ? lines : [''];
    }

    function money(value) {
        return 'PHP ' + (Number(value) || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function whole(value) {
        return (Number(value) || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function n(value) {
        return (Math.round(value * 100) / 100).toString();
    }

    // ---------- drawing ----------

    // A document is a list of pages; every page is a list of PDF drawing commands.
    // Positions use the top-left corner as the origin (y grows downwards).
    function createDocument() {
        const doc = {
            pages: [],
            y: MARGIN,

            newPage: function () {
                this.pages.push([]);
                this.y = MARGIN;
            },

            add: function (command) {
                this.pages[this.pages.length - 1].push(command);
            },

            text: function (value, x, baseline, options) {
                const o = options || {};
                const size = o.size || 10;
                const bold = !!o.bold;
                const text = toPdfText(value);
                const width = measure(text, size, bold);
                let left = x;

                if (o.align === 'right') left = x - width;
                if (o.align === 'center') left = x - width / 2;

                this.add(
                    (o.color || INK).join(' ') + ' rg BT /' + (bold ? 'F2' : 'F1') + ' ' + size +
                    ' Tf ' + n(left) + ' ' + n(PAGE_HEIGHT - baseline) + ' Td (' + escapePdf(text) + ') Tj ET'
                );
            },

            fillRect: function (x, top, width, height, color) {
                this.add(
                    color.join(' ') + ' rg ' + n(x) + ' ' + n(PAGE_HEIGHT - top - height) +
                    ' ' + n(width) + ' ' + n(height) + ' re f'
                );
            },

            strokeRect: function (x, top, width, height, color) {
                this.add(
                    color.join(' ') + ' RG 0.8 w ' + n(x) + ' ' + n(PAGE_HEIGHT - top - height) +
                    ' ' + n(width) + ' ' + n(height) + ' re S'
                );
            },

            line: function (x1, y1, x2, y2, color, lineWidth) {
                this.add(
                    color.join(' ') + ' RG ' + (lineWidth || 0.6) + ' w ' +
                    n(x1) + ' ' + n(PAGE_HEIGHT - y1) + ' m ' + n(x2) + ' ' + n(PAGE_HEIGHT - y2) + ' l S'
                );
            },

            // Start a new page when the next block would not fit.
            ensureSpace: function (height) {
                if (this.y + height > PAGE_HEIGHT - MARGIN - FOOTER_SPACE) {
                    this.newPage();
                    return true;
                }

                return false;
            }
        };

        doc.newPage();

        return doc;
    }

    // ---------- the report ----------

    function drawHeader(doc, options) {
        doc.text('D&JC Food Products', MARGIN, doc.y + 18, { size: 18, bold: true, color: BROWN });
        doc.text('Sales Report', MARGIN, doc.y + 36, { size: 12, bold: true });

        const generated = options.generatedAt instanceof Date ? options.generatedAt : new Date();

        doc.text(
            'Generated: ' + generated.toLocaleString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
            }),
            PAGE_WIDTH - MARGIN, doc.y + 18, { size: 9, align: 'right', color: MUTED }
        );

        doc.text('Period: ' + (options.period || 'All dates'),
            PAGE_WIDTH - MARGIN, doc.y + 32, { size: 9, align: 'right', color: MUTED });

        doc.line(MARGIN, doc.y + 46, PAGE_WIDTH - MARGIN, doc.y + 46, BROWN, 1.2);
        doc.y += 66;
    }

    function drawSummary(doc, stats) {
        const gap = 12;
        const boxWidth = (CONTENT_WIDTH - gap * 3) / 4;
        const boxHeight = 56;

        [
            { label: 'Daily Sales', value: money(stats.daily), sub: 'This Day' },
            { label: 'Weekly Sales', value: money(stats.weekly), sub: 'This Week' },
            { label: 'Monthly Sales', value: money(stats.monthly), sub: stats.month || '' },
            { label: 'Total Customers', value: whole(stats.customers), sub: 'This Day' }
        ].forEach(function (box, index) {
            const x = MARGIN + index * (boxWidth + gap);

            doc.strokeRect(x, doc.y, boxWidth, boxHeight, LINE);
            doc.text(box.label, x + 12, doc.y + 17, { size: 9, bold: true });
            doc.text(box.value, x + 12, doc.y + 37, { size: 14, bold: true });
            doc.text(box.sub, x + 12, doc.y + 50, { size: 8, color: MUTED });
        });

        doc.y += boxHeight + 26;
    }

    function drawSectionTitle(doc, title) {
        doc.text(title, MARGIN, doc.y + 12, { size: 13, bold: true });
        doc.y += 24;
    }

    // Bar graph of the most sold products.
    function drawMostSold(doc, products) {
        drawSectionTitle(doc, 'Most Sold Products');

        if (!products.length) {
            doc.text('No completed sales recorded for this period.', MARGIN, doc.y + 10, { size: 10, color: MUTED });
            doc.y += 30;
            return;
        }

        const labelWidth = 170;
        const barArea = 200;
        const rowHeight = 22;
        const maxQuantity = Math.max.apply(null, products.map(function (p) { return Number(p.quantity) || 0; })) || 1;

        products.forEach(function (product, index) {
            const top = doc.y;
            const quantity = Number(product.quantity) || 0;

            doc.text((index + 1) + '. ' + fit(toPdfText(product.product), 10, false, labelWidth - 18),
                MARGIN, top + 12, { size: 10 });

            const barWidth = Math.max(2, barArea * quantity / maxQuantity);

            doc.fillRect(MARGIN + labelWidth, top + 3, barWidth, 12, BROWN);

            doc.text(whole(quantity) + ' sold', MARGIN + labelWidth + barWidth + 8, top + 12,
                { size: 9, bold: true });

            doc.text(money(product.total), PAGE_WIDTH - MARGIN, top + 12,
                { size: 9, align: 'right', color: MUTED });

            doc.y += rowHeight;
        });

        doc.y += 14;
    }

    // Sales Records table: one row per completed order.
    function drawRecords(doc, orders) {
        const columns = [
            { title: 'Order ID', width: 44, align: 'left' },
            { title: 'Date', width: 70, align: 'left' },
            { title: 'Customer', width: 78, align: 'left' },
            { title: 'Items', width: 163, align: 'left' },
            { title: 'Qty', width: 28, align: 'right' },
            { title: 'Payment', width: 44, align: 'left' },
            { title: 'Total', width: CONTENT_WIDTH - 44 - 70 - 78 - 163 - 28 - 44, align: 'right' }
        ];

        const padding = 6;
        const size = 8.5;
        const leading = 11;

        function columnX(index) {
            return MARGIN + columns.slice(0, index).reduce(function (sum, c) { return sum + c.width; }, 0);
        }

        function textX(index) {
            const column = columns[index];

            return column.align === 'right'
                ? columnX(index) + column.width - padding
                : columnX(index) + padding;
        }

        function drawTableHeader() {
            doc.fillRect(MARGIN, doc.y, CONTENT_WIDTH, 24, HEADER_FILL);

            columns.forEach(function (column, index) {
                doc.text(column.title, textX(index), doc.y + 16, { size: size, bold: true, align: column.align });
            });

            doc.y += 24;
        }

        // The lines of text a cell needs, wrapped to its column.
        function lines(text, index) {
            return wrap(toPdfText(text), size, false, columns[index].width - padding * 2);
        }

        drawSectionTitle(doc, 'Sales Records');

        // Keep the heading together with the first rows.
        doc.ensureSpace(24 + 40);
        drawTableHeader();

        if (!orders.length) {
            doc.text('No completed orders recorded for this period.', MARGIN + padding, doc.y + 18, { size: 10, color: MUTED });
            doc.y += 30;
            return;
        }

        let totalQuantity = 0;
        let totalSales = 0;

        orders.forEach(function (order) {
            const itemLines = [];

            (order.items || []).forEach(function (item) {
                lines((Number(item.quantity) || 0) + 'x ' + item.name, 3).forEach(function (line) {
                    itemLines.push(line);
                });
            });

            const cells = {
                0: lines(String(order.order_no), 0),
                1: lines(String(order.created_at || '').slice(0, 16), 1),
                2: lines(order.customer, 2),
                3: itemLines.length ? itemLines : ['-'],
                4: [whole(order.quantity)],
                5: lines(order.payment, 5),
                6: [money(order.total)]
            };

            const rowLines = Math.max.apply(null, Object.keys(cells).map(function (key) { return cells[key].length; }));
            const rowHeight = rowLines * leading + 8;

            // A new page repeats the table header.
            if (doc.ensureSpace(rowHeight)) {
                drawTableHeader();
            }

            Object.keys(cells).forEach(function (key) {
                const index = Number(key);

                cells[key].forEach(function (line, lineIndex) {
                    doc.text(line, textX(index), doc.y + 13 + lineIndex * leading,
                        { size: size, align: columns[index].align, bold: index === 0 });
                });
            });

            doc.line(MARGIN, doc.y + rowHeight, MARGIN + CONTENT_WIDTH, doc.y + rowHeight, LINE, 0.5);
            doc.y += rowHeight;

            totalQuantity += Number(order.quantity) || 0;
            totalSales += Number(order.total) || 0;
        });

        // Totals row
        if (doc.ensureSpace(28)) {
            drawTableHeader();
        }

        doc.fillRect(MARGIN, doc.y, CONTENT_WIDTH, 24, HEADER_FILL);

        doc.text('Total (' + orders.length + ' order' + (orders.length !== 1 ? 's' : '') + ')',
            textX(0), doc.y + 16, { size: size, bold: true });
        doc.text(whole(totalQuantity), textX(4), doc.y + 16, { size: size, bold: true, align: 'right' });
        doc.text(money(totalSales), textX(6), doc.y + 16, { size: size, bold: true, align: 'right' });

        doc.y += 24;
    }

    // "Page 1 of 3" on every page (written last, when the page count is known).
    function drawFooters(doc) {
        const count = doc.pages.length;

        doc.pages.forEach(function (commands, index) {
            const label = toPdfText('Page ' + (index + 1) + ' of ' + count);

            commands.push(
                MUTED.join(' ') + ' rg BT /F1 8 Tf ' + n(MARGIN) + ' 24 Td (' +
                escapePdf(toPdfText('D&JC Food Products - Sales Report')) + ') Tj ET'
            );

            commands.push(
                MUTED.join(' ') + ' rg BT /F1 8 Tf ' +
                n(PAGE_WIDTH - MARGIN - measure(label, 8, false)) + ' 24 Td (' +
                escapePdf(label) + ') Tj ET'
            );
        });
    }

    // ---------- PDF file ----------

    function serialize(doc, title) {
        const objects = [];     // index 0 = object 1

        function addObject(body) {
            objects.push(body);
            return objects.length;
        }

        addObject('<< /Type /Catalog /Pages 2 0 R >>');
        addObject('PAGES_PLACEHOLDER');
        addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');

        const pageIds = [];

        doc.pages.forEach(function (commands) {
            const stream = commands.join('\n');
            const contentId = objects.length + 2;

            pageIds.push(addObject(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + PAGE_WIDTH + ' ' + PAGE_HEIGHT + '] ' +
                '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' + contentId + ' 0 R >>'
            ));

            addObject('<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream');
        });

        objects[1] = '<< /Type /Pages /Kids [' +
            pageIds.map(function (id) { return id + ' 0 R'; }).join(' ') +
            '] /Count ' + pageIds.length + ' >>';

        const infoId = addObject(
            '<< /Title (' + escapePdf(toPdfText(title)) + ') /Producer (D&JC Sales Report) >>'
        );

        // Every character is below 256, so one character is one byte.
        let file = '%PDF-1.4\n%\u00e2\u00e3\u00cf\u00d3\n';
        const offsets = [];

        objects.forEach(function (body, index) {
            offsets.push(file.length);
            file += (index + 1) + ' 0 obj\n' + body + '\nendobj\n';
        });

        const xrefStart = file.length;

        file += 'xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n';

        offsets.forEach(function (offset) {
            file += String(offset).padStart(10, '0') + ' 00000 n \n';
        });

        file += 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root 1 0 R /Info ' + infoId +
            ' 0 R >>\nstartxref\n' + xrefStart + '\n%%EOF\n';

        const bytes = new Uint8Array(file.length);

        for (let i = 0; i < file.length; i++) {
            bytes[i] = file.charCodeAt(i) & 255;
        }

        return bytes;
    }

    function buildBytes(options) {
        const doc = createDocument();

        drawHeader(doc, options);
        drawSummary(doc, options.stats || {});
        drawMostSold(doc, options.topProducts || []);
        drawRecords(doc, options.records || []);
        drawFooters(doc);

        return serialize(doc, 'D&JC Sales Report');
    }

    global.SalesPdf = {
        buildBytes: buildBytes,
        build: function (options) {
            return new Blob([buildBytes(options)], { type: 'application/pdf' });
        },
        // exposed for tests
        _measure: measure,
        _toPdfText: toPdfText,
        _tables: { HELVETICA: HELVETICA, HELVETICA_BOLD: HELVETICA_BOLD }
    };
})(typeof window !== 'undefined' ? window : globalThis);
