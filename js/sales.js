document.addEventListener('DOMContentLoaded', function () {
    const alertBox = document.getElementById('alertBox');
    const salesSearchInput = document.getElementById('salesSearchInput');
    const salesRecordCount = document.getElementById('salesRecordCount');
    const downloadBtn = document.getElementById('downloadBtn');
    const printBtn = document.getElementById('printBtn');

    // How many products the "Most Sold Products" bar graph shows.
    const MOST_SOLD_LIMIT = 5;

    let allSalesRecords = [];
    let mostSoldProducts = [];
    let mostSoldMetric = 'quantity';   // 'quantity' or 'total'

    // Chart instances are reused during automatic refresh.
    let dailySalesChart = null;
    let mostSoldChart = null;

    // The date range that is currently applied.
    let activeStartDate = '';
    let activeEndDate = '';

    // The quick period chosen with the Daily / Weekly / Monthly cards ('' = all sales).
    let activePeriod = '';
    let activePeriodText = '';

    // Only the newest request may update the page (an older, slower answer is ignored).
    let requestCounter = 0;

    function showAlert(message, type) {
        alertBox.textContent = message;
        alertBox.className = 'alert-box ' + type;
        alertBox.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function peso(value) {
        return '₱' + (Number(value) || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function createOrUpdateChart(existingChart, canvasId, config) {
        const canvas = document.getElementById(canvasId);

        if (!canvas || typeof Chart === 'undefined') {
            return existingChart;
        }

        if (existingChart) {
            existingChart.data = config.data;
            existingChart.options = config.options;
            existingChart.update('none');
            return existingChart;
        }

        return new Chart(canvas, config);
    }

    // ---------- analytics ----------

    const TREND_INFO = {
        daily:   { title: 'Daily Sales Trend',   label: 'Daily Sales (₱)' },
        weekly:  { title: 'Weekly Sales Trend',  label: 'Weekly Sales (₱)' },
        monthly: { title: 'Monthly Sales Trend', label: 'Monthly Sales (₱)' }
    };

    // "2026-11-09" -> a Date at local midnight.
    function parseYmd(value) {
        const p = String(value || '').split('-');

        return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    }

    function shortDate(d) {
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    // Axis label of one point of the trend: "Nov 4", "Nov 3-9" or "Nov 2026".
    function trendLabel(point, period) {
        const start = parseYmd(point.date);

        if (period === 'monthly') {
            return start.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        }

        if (period === 'weekly') {
            const end = parseYmd(point.end);

            return start.getMonth() === end.getMonth()
                ? shortDate(start) + '-' + end.getDate()
                : shortDate(start) + '-' + shortDate(end);
        }

        return shortDate(start);
    }

    // Longer text for the tooltip: "Nov 3 - Nov 9, 2026".
    function trendTooltipTitle(point, period) {
        const start = parseYmd(point.date);

        if (period === 'monthly') {
            return start.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        }

        if (period === 'weekly') {
            return 'Week of ' + shortDate(start) + ' - ' + shortDate(parseYmd(point.end)) + ', ' + parseYmd(point.end).getFullYear();
        }

        return start.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    // The sales trend chart follows the chosen period: daily, weekly or monthly.
    function renderTrend(data) {
        // For a hand-picked range the server chooses days, weeks or months to fit its length.
        const unit = data.trend_unit || data.period;
        const period = TREND_INFO[unit] ? unit : 'daily';
        const info = TREND_INFO[period];
        const trend = data.trend || [];

        const title = document.getElementById('trendTitle');

        if (title) title.textContent = info.title;

        // Under the title: which dates the chart covers and what they add up to.
        const rangeNote = document.getElementById('trendRange');

        if (rangeNote) {
            if (data.period === 'custom' && data.range) {
                const total = trend.reduce((sum, point) => sum + Number(point.total), 0);

                rangeNote.textContent = longDate(parseYmd(data.range.start)) + ' - ' +
                    longDate(parseYmd(data.range.end)) + ' \u00B7 ' + peso(total) + ' in sales';
                rangeNote.classList.remove('hidden');
            } else {
                rangeNote.textContent = '';
                rangeNote.classList.add('hidden');
            }
        }

        dailySalesChart = createOrUpdateChart(
            dailySalesChart,
            'dailySalesChart',
            {
                type: 'line',
                data: {
                    labels: trend.map(point => trendLabel(point, period)),
                    datasets: [{
                        label: info.label,
                        data: trend.map(point => Number(point.total)),
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                title: items => items.length ? trendTooltipTitle(trend[items[0].dataIndex], period) : '',
                                label: context => peso(context.parsed.y)
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: value => '₱' + value
                            }
                        }
                    }
                }
            }
        );

        const canvas = document.getElementById('dailySalesChart');

        if (canvas) canvas.setAttribute('aria-label', info.title);
    }

    // Bar graph of the products that sold the most.
    function renderMostSold() {
        const top = mostSoldProducts.slice(0, MOST_SOLD_LIMIT);
        const byQuantity = mostSoldMetric === 'quantity';

        mostSoldChart = createOrUpdateChart(
            mostSoldChart,
            'mostSoldChart',
            {
                type: 'bar',
                data: {
                    labels: top.map(item => item.product),
                    datasets: [{
                        label: byQuantity ? 'Units sold' : 'Sales (₱)',
                        data: top.map(item => Number(byQuantity ? item.quantity : item.total)),
                        // The best seller stands out.
                        backgroundColor: top.map((item, index) => index === 0 ? '#6b3f2a' : '#c98a4b'),
                        borderRadius: 6,
                        maxBarThickness: 34
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const item = top[context.dataIndex];

                                    return [
                                        Number(item.quantity).toLocaleString('en-PH') + ' sold',
                                        peso(item.total) + ' in sales'
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: byQuantity
                                ? { precision: 0 }
                                : { callback: value => '₱' + value }
                        },
                        y: {
                            ticks: {
                                autoSkip: false,
                                // Keep long product names on the chart.
                                callback: function (value) {
                                    const label = String(this.getLabelForValue(value));

                                    return label.length > 22 ? label.slice(0, 21) + '…' : label;
                                }
                            }
                        }
                    }
                }
            }
        );

        // Short note under the chart.
        const note = document.getElementById('mostSoldNote');

        if (note) {
            if (top.length === 0) {
                note.textContent = 'No completed sales recorded yet.';
            } else if (top.length < 3) {
                note.textContent =
                    'Only ' + top.length + ' product' + (top.length !== 1 ? 's have' : ' has') +
                    ' sold so far. More will appear here as sales come in.';
            } else if (activePeriod) {
                note.textContent = 'Showing ' + activePeriodText.toLowerCase() + '.';
            } else if (activeStartDate || activeEndDate) {
                note.textContent = 'Showing the date range selected in Sales Records.';
            } else {
                note.textContent = '';
            }
        }

        // Top-selling product summary
        const topProductElement = document.getElementById('topSellingProduct');

        if (topProductElement) {
            topProductElement.textContent = top.length
                ? 'Top seller: ' + top[0].product + ' — ' + Number(top[0].quantity).toLocaleString('en-PH') + ' sold'
                : 'No completed sales recorded yet.';
        }
    }

    // Quantity / Sales (₱) switch above the bar graph.
    document.querySelectorAll('.chart-toggle button').forEach(function (button) {
        button.addEventListener('click', function () {
            mostSoldMetric = button.dataset.metric;

            document.querySelectorAll('.chart-toggle button').forEach(function (other) {
                const isActive = other === button;

                other.classList.toggle('active', isActive);
                other.setAttribute('aria-pressed', String(isActive));
            });

            renderMostSold();
        });
    });

    // ---------- sales records (table): one row per completed order ----------

    // "2026-11-03 10:15:00" -> "Nov 3, 2026, 10:15 AM" (the time is shown as saved).
    function formatOrderDate(value) {
        const parts = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);

        if (!parts) return String(value || '');

        return new Date(
            Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]),
            Number(parts[4]), Number(parts[5])
        ).toLocaleString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
        });
    }

    // Search by order number, customer, payment method or any product in the order.
    function orderMatches(order, term) {
        if (!term) return true;

        const text = [
            order.order_no,
            order.customer,
            order.payment,
            ...(order.items || []).map(item => item.name)
        ].join(' ').toLowerCase();

        return text.includes(term.replace(/^#/, ''));
    }

    function renderSalesTable() {
        const body = document.getElementById('salesTableBody');
        const foot = document.getElementById('salesTableFoot');

        if (!body) return;

        const searchTerm = salesSearchInput
            ? salesSearchInput.value.trim().toLowerCase()
            : '';

        const orders = allSalesRecords.filter(order => orderMatches(order, searchTerm));

        if (salesRecordCount) {
            salesRecordCount.textContent =
                `Showing ${orders.length} of ${allSalesRecords.length} orders`;
        }

        if (orders.length === 0) {
            body.innerHTML = `
                <tr>
                    <td colspan="7" class="sales-empty">
                        ${searchTerm
                            ? 'No orders match your search.'
                            : 'No completed orders recorded yet.'}
                    </td>
                </tr>
            `;

            if (foot) foot.innerHTML = '';
            return;
        }

        body.innerHTML = orders.map(order => {
            const items = (order.items || []).map(item => `
                <li>
                    <span class="item-qty">${Number(item.quantity).toLocaleString('en-PH')}&times;</span>
                    ${escapeHTML(item.name)}
                </li>
            `).join('');

            return `
                <tr>
                    <td class="order-cell">${escapeHTML(order.order_no)}</td>
                    <td class="date-cell">${escapeHTML(formatOrderDate(order.created_at))}</td>
                    <td>${escapeHTML(order.customer)}</td>
                    <td class="items-cell">
                        ${items ? `<ul class="order-items">${items}</ul>` : '&mdash;'}
                    </td>
                    <td class="num">${Number(order.quantity).toLocaleString('en-PH')}</td>
                    <td>${escapeHTML(order.payment)}</td>
                    <td class="num">${peso(order.total)}</td>
                </tr>
            `;
        }).join('');

        // Totals of the orders that are shown.
        const totalQuantity = orders.reduce((sum, order) => sum + Number(order.quantity), 0);
        const totalSales = orders.reduce((sum, order) => sum + Number(order.total), 0);

        if (foot) {
            foot.innerHTML = `
                <tr>
                    <th scope="row" colspan="4">
                        Total (${orders.length} order${orders.length !== 1 ? 's' : ''})
                    </th>
                    <td class="num">${totalQuantity.toLocaleString('en-PH')}</td>
                    <td></td>
                    <td class="num">${peso(totalSales)}</td>
                </tr>
            `;
        }
    }

    // ---------- loading ----------

    async function fetchSalesData(startDate = '', endDate = '') {
        try {
            const params = new URLSearchParams();

            if (startDate) params.append('start_date', startDate);
            if (endDate) params.append('end_date', endDate);

            // The chart and the customer count follow the chosen card.
            // (a hand-picked date range is "custom": the trend then follows those dates)
            const pastMonth = activePeriod === 'monthly' && selectedMonth && selectedMonth !== currentMonthKey();
            params.append('period', pastMonth ? 'custom' : (activePeriod || ((startDate || endDate) ? 'custom' : 'daily')));

            const queryString = params.toString();

            const url = queryString
                ? `../php/get_sales.php?${queryString}`
                : '../php/get_sales.php';

            const requestNumber = ++requestCounter;

            const response = await fetch(url);
            const data = await response.json();

            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to load sales data.');
            }

            // A newer request was sent while this one was loading: drop this answer.
            if (requestNumber !== requestCounter) return;

            // Summary cards
            document.getElementById('dailySalesVal').innerHTML =
                `&#8369; ${data.stats.daily_sales}`;

            document.getElementById('weeklySalesVal').innerHTML =
                `&#8369; ${data.stats.weekly_sales}`;

            document.getElementById('monthlySalesVal').innerHTML =
                `&#8369; ${data.stats.monthly_sales}`;

            document.getElementById('totalCustomerVal').textContent =
                data.stats.total_customers;

            updatePeriodLabels();

            // Sales records table and search
            allSalesRecords = Array.isArray(data.report) ? data.report : [];
            renderSalesTable();

            // Charts
            mostSoldProducts = Array.isArray(data.most_sold) ? data.most_sold : [];
            renderTrend(data);
            renderMostSold();

        } catch (error) {
            console.error('Error fetching sales report:', error);
        }
    }

    // Search the displayed records without another server request.
    if (salesSearchInput) {
        salesSearchInput.addEventListener('input', renderSalesTable);
    }

    // ---------- Daily / Weekly / Monthly quick periods ----------
    // The three sales cards and the Period buttons in the filter panel do the same thing:
    // they set the date range (and with it the records, the most sold chart and the sales
    // trend) to today, this week or this month. Selecting the active one again shows all sales.

    const periodButtons = document.querySelectorAll('.period-toggle button');
    const periodCards = document.querySelectorAll('.stat-cards [data-period]');
    const periodHint = document.getElementById('periodHint');
    const monthField = document.getElementById('monthField');
    const monthPicker = document.getElementById('monthPicker');

    // The Month list: the current month and the 35 months before it.
    if (monthPicker) {
        const now = new Date();

        for (let i = 0; i < 36; i++) {
            const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
            const option = document.createElement('option');

            option.value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
            option.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            monthPicker.appendChild(option);
        }

        monthPicker.addEventListener('change', function () {
            selectedMonth = monthPicker.value;
            choosePeriodMonth();
        });
    }

    // Show the month that is selected in the Month list.
    function choosePeriodMonth() {
        const range = periodRange('monthly');

        document.getElementById('startDate').value = range.start;
        document.getElementById('endDate').value = range.end;

        activeStartDate = range.start;
        activeEndDate = range.end;

        setActivePeriod('monthly', range.text);
        fetchSalesData(activeStartDate, activeEndDate);
    }
    const activePeriodNote = document.getElementById('activePeriodNote');

    // YYYY-MM-DD in the browser's local time (toISOString would shift the day).
    function ymd(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    function longDate(d) {
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    // The month chosen for the Monthly period, as "YYYY-MM" ('' = the current month).
    let selectedMonth = '';

    function currentMonthKey() {
        const now = new Date();

        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    }

    function periodRange(period) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

        if (period === 'weekly') {
            // Monday to Sunday of the current week
            const offset = (today.getDay() + 6) % 7;
            const monday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - offset);
            const sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6);
            return {
                start: ymd(monday),
                end: ymd(sunday),
                text: 'This week (' + shortDate(monday) + ' - ' + longDate(sunday) + ')',
                short: shortDate(monday) + ' - ' + shortDate(sunday)
            };
        }

        if (period === 'monthly') {
            // The month chosen in the Month list (the current month by default).
            const key = selectedMonth || currentMonthKey();
            const year = Number(key.slice(0, 4));
            const monthIndex = Number(key.slice(5, 7)) - 1;
            const first = new Date(year, monthIndex, 1);
            const last = new Date(year, monthIndex + 1, 0);
            const month = first.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            return { start: ymd(first), end: ymd(last), text: month, short: month };
        }

        return { start: ymd(today), end: ymd(today), text: 'Today (' + longDate(today) + ')', short: 'Today' };
    }

    // Small texts under the cards and above the table; they follow the chosen period.
    function updatePeriodLabels() {
        const weekly = document.getElementById('weeklySalesSub');
        const monthly = document.getElementById('monthlySalesSub');
        const customers = document.getElementById('totalCustomerSub');

        if (weekly) weekly.textContent = periodRange('weekly').short;
        if (monthly) monthly.textContent = new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

        if (customers) {
            customers.textContent = activePeriod === 'weekly' ? 'This Week'
                : activePeriod === 'monthly' ? periodRange('monthly').short
                : (!activePeriod && (activeStartDate || activeEndDate)) ? 'Selected dates'
                : 'This Day';
        }

        if (!activePeriodNote) return;

        let text = '';

        if (activePeriod) {
            text = 'Showing: ' + activePeriodText;
        } else if (activeStartDate || activeEndDate) {
            text = 'Showing: ' + (activeStartDate || 'start') + ' to ' + (activeEndDate || 'today');
        }

        activePeriodNote.textContent = text;
        activePeriodNote.classList.toggle('hidden', text === '');
    }

    function setActivePeriod(period, text) {
        activePeriod = period;
        activePeriodText = text;

        periodButtons.forEach(function (btn) {
            const on = btn.dataset.period === period;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        periodCards.forEach(function (card) {
            card.setAttribute('aria-pressed', card.dataset.period === period ? 'true' : 'false');
        });

        // Monthly asks for a month only (no dates); the other periods keep the date boxes.
        document.querySelectorAll('.date-field').forEach(function (field) {
            field.classList.toggle('hidden', period === 'monthly');
        });

        if (monthField) monthField.classList.toggle('hidden', period !== 'monthly');
        if (monthPicker && period === 'monthly') monthPicker.value = selectedMonth || currentMonthKey();

        if (periodHint) {
            periodHint.textContent = period === 'monthly'
                ? 'Choose the month:'
                : period
                    ? text + ' - or choose your own dates:'
                    : 'Or choose your own dates:';
        }

        updatePeriodLabels();
    }

    // Show all sales again (no period, no dates).
    function clearPeriodFilter() {
        selectedMonth = '';
        document.getElementById('startDate').value = '';
        document.getElementById('endDate').value = '';

        activeStartDate = '';
        activeEndDate = '';

        setActivePeriod('', '');
        fetchSalesData();
    }

    // toggle = true for the cards: selecting the chosen one again shows all sales.
    function choosePeriod(period, toggle) {
        if (toggle && period === activePeriod) {
            clearPeriodFilter();
            return;
        }

        // Choosing Monthly starts on the current month; the Month list then picks another one.
        if (period === 'monthly' && activePeriod !== 'monthly') {
            selectedMonth = currentMonthKey();
        }

        const range = periodRange(period);

        document.getElementById('startDate').value = range.start;
        document.getElementById('endDate').value = range.end;

        activeStartDate = range.start;
        activeEndDate = range.end;

        setActivePeriod(period, range.text);
        fetchSalesData(activeStartDate, activeEndDate);
    }

    periodButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            choosePeriod(btn.dataset.period, false);
        });
    });

    periodCards.forEach(function (card) {
        card.addEventListener('click', function () {
            choosePeriod(card.dataset.period, true);
        });
    });

    // Typing your own dates switches back to a custom range.
    ['startDate', 'endDate'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', function () {
            setActivePeriod('', '');
        });
    });

    // Apply the selected date range.
    document.getElementById('applyFiltersBtn').addEventListener('click', function () {
        // Monthly: apply the month that is chosen in the Month list.
        if (activePeriod === 'monthly') {
            selectedMonth = (monthPicker && monthPicker.value) || selectedMonth;
            choosePeriodMonth();
            return;
        }

        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;

        if (startDate && endDate && startDate > endDate) {
            showAlert('Start Date cannot be later than End Date.', 'error');
            return;
        }

        activeStartDate = startDate;
        activeEndDate = endDate;

        updatePeriodLabels();
        fetchSalesData(activeStartDate, activeEndDate);
    });

    // Reset the date filters.
    document.getElementById('resetFiltersBtn').addEventListener('click', clearPeriodFilter);

    // Show / hide the filter panel.
    document.getElementById('filtersBtn').addEventListener('click', function () {
        const filterPanel = document.getElementById('salesFilterPanel');

        if (filterPanel) {
            filterPanel.classList.toggle('hidden');
        }
    });

    // ---------- Export as PDF / Print ----------

    function todayText() {
        const now = new Date();

        return now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0');
    }

    function periodText(startDate, endDate) {
        if (startDate && endDate) return startDate + ' to ' + endDate;
        if (startDate) return 'From ' + startDate;
        if (endDate) return 'Up to ' + endDate;

        return 'All dates';
    }

    function fileName(startDate, endDate) {
        if (startDate && endDate) return `sales_report_${startDate}_to_${endDate}.pdf`;
        if (startDate) return `sales_report_from_${startDate}.pdf`;
        if (endDate) return `sales_report_until_${endDate}.pdf`;

        return `sales_report_${todayText()}.pdf`;
    }

    // The complete report for the dates in the filter (every record, not just the latest 10),
    // in the shape SalesPdf and SalesPrint both use. Throws if it cannot be loaded.
    async function loadFullReport(startDate, endDate) {
        const params = new URLSearchParams();

        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        params.append('all', '1');

        const response = await fetch(`../php/get_sales.php?${params.toString()}`);
        const data = await response.json();

        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to load the sales report.');
        }

        return {
            generatedAt: new Date(),
            period: periodText(startDate, endDate),
            stats: {
                daily: data.stats.daily_sales,
                weekly: data.stats.weekly_sales,
                monthly: data.stats.monthly_sales,
                customers: data.stats.total_customers,
                month: new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' })
            },
            topProducts: (data.most_sold || []).slice(0, MOST_SOLD_LIMIT),
            records: data.report || []
        };
    }

    // Both buttons use the dates in the filter; a wrong range stops them.
    function readReportDates() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;

        if (startDate && endDate && startDate > endDate) {
            showAlert('Start Date cannot be later than End Date.', 'error');
            return null;
        }

        return { startDate: startDate, endDate: endDate };
    }

    // Print: open the print dialog straight away (no file to save first).
    if (printBtn) {
        printBtn.addEventListener('click', async function () {
            const dates = readReportDates();

            if (!dates) return;

            const label = printBtn.textContent;

            printBtn.disabled = true;
            downloadBtn.disabled = true;
            printBtn.textContent = 'Preparing...';

            try {
                await SalesPrint.print(await loadFullReport(dates.startDate, dates.endDate));
            } catch (error) {
                console.error('Sales report print error:', error);
                showAlert('Unable to print the sales report. Please try again.', 'error');
            } finally {
                printBtn.disabled = false;
                downloadBtn.disabled = false;
                printBtn.textContent = label;
            }
        });
    }

    downloadBtn.addEventListener('click', async function () {
        const dates = readReportDates();

        if (!dates) return;

        const startDate = dates.startDate;
        const endDate = dates.endDate;

        const label = downloadBtn.textContent;

        downloadBtn.disabled = true;
        if (printBtn) printBtn.disabled = true;
        downloadBtn.textContent = 'Preparing PDF...';

        try {
            const pdf = SalesPdf.build(await loadFullReport(startDate, endDate));

            const downloadUrl = URL.createObjectURL(pdf);
            const link = document.createElement('a');

            link.href = downloadUrl;
            link.download = fileName(startDate, endDate);

            document.body.appendChild(link);
            link.click();
            link.remove();

            // Give the browser a moment to start the download first.
            setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000);

            showAlert('Sales report exported as a PDF.', 'success');

        } catch (error) {
            console.error('Sales report download error:', error);
            showAlert('Unable to export the sales report. Please try again.', 'error');

        } finally {
            downloadBtn.disabled = false;
            if (printBtn) printBtn.disabled = false;
            downloadBtn.textContent = label;
        }
    });

    // ---------- start ----------

    updatePeriodLabels();

    // Load initial data.
    fetchSalesData();

    // Automatically refresh every 5 seconds using the active filters.
    setInterval(function () {
        fetchSalesData(activeStartDate, activeEndDate);
    }, 5000);
});
