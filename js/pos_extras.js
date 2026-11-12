/* POS extras: smart "amount tendered" buttons for the cash payment step.
   Builds Exact + the next bills above the total, and fills the existing input,
   then fires its normal `input` event so the page's own calculateChange() runs. */
(function () {
    const box   = document.getElementById('quickTender');
    const total = document.getElementById('cashTotalDisplay');
    const input = document.getElementById('cashReceivedInput');
    if (!box || !total || !input) return;

    const BILLS = [20, 50, 100, 200, 500, 1000, 2000];
    const peso  = n => '\u20B1' + n.toLocaleString('en-PH');

    function render() {
        const t = parseFloat((total.textContent || '').replace(/[^\d.]/g, '')) || 0;
        box.innerHTML = '';
        if (t <= 0) return;
        const options = [];
        const exact = Math.round(t * 100) / 100;
        options.push({ label: 'Exact', value: exact, exact: true });
        // next bills at or above the total (e.g. 387 -> 400, 500, 1000)
        const roundedUp = Math.ceil(t / 50) * 50;
        const above = [roundedUp].concat(BILLS.filter(b => b > roundedUp)).filter((v, i, a) => a.indexOf(v) === i && v > exact);
        above.slice(0, 3).forEach(v => options.push({ label: peso(v), value: v }));
        options.forEach(o => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = o.label;
            b.dataset.value = String(o.value);
            if (o.exact) b.className = 'is-exact';
            box.appendChild(b);
        });
    }

    box.addEventListener('click', e => {
        const b = e.target.closest('button[data-value]');
        if (!b) return;
        input.value = parseFloat(b.dataset.value).toFixed(2);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    });

    new MutationObserver(render).observe(total, { childList: true, characterData: true, subtree: true });
    render();
})();
