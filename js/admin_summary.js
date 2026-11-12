/* Inventory summary cards: live counts + click-to-filter.
   Reads the table itself, so the numbers stay right after stock edits.
   Uses the row's own availability chip, so it always matches what the table shows. */
(function () {
    const cards = document.querySelector('[data-inventory-stats]');
    if (!cards) return;

    const rows = () => Array.from(document.querySelectorAll('#inventoryTableBody .inventory-row, .inventory-table tbody .inventory-row'));

    function recount() {
        let active = 0, low = 0, out = 0, soon = 0, expired = 0;
        rows().forEach(r => {
            if (r.classList.contains('row-inactive')) return;
            active++;
            if (r.dataset.expiry === 'soon') soon++;
            else if (r.dataset.expiry === 'expired') expired++;
            if (r.querySelector('.availability.no-stock')) out++;
            else if (r.querySelector('.availability.low-stock')) low++;
        });
        const set = (k, v) => { const el = cards.querySelector('[data-stat="' + k + '"]'); if (el) el.textContent = v; };
        set('active', active); set('low', low); set('out', out); set('expiring', soon); set('expired', expired);
    }

    function applyFilter(value) {
        const panel  = document.getElementById('filtersPanel');
        const toggle = document.getElementById('filtersBtn');
        const sel    = document.getElementById('filterAvailability');
        if (!sel) return;
        if (panel && panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }
        sel.value = value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        sel.dispatchEvent(new Event('input',  { bubbles: true }));
    }

    function applyExpiryFilter(value) {
        const panel  = document.getElementById('filtersPanel');
        const toggle = document.getElementById('filtersBtn');
        const sel    = document.getElementById('filterExpiry');
        if (!sel) return;
        if (panel && panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }
        sel.value = value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    cards.addEventListener('click', e => {
        const expiryBtn = e.target.closest('button[data-expiry-filter]');
        if (expiryBtn) {
            const wasOn = expiryBtn.getAttribute('aria-pressed') === 'true';
            cards.querySelectorAll('button[data-expiry-filter]').forEach(b => b.setAttribute('aria-pressed', 'false'));
            if (wasOn) { applyExpiryFilter(''); return; }
            expiryBtn.setAttribute('aria-pressed', 'true');
            applyExpiryFilter(expiryBtn.dataset.expiryFilter);
            return;
        }

        const btn = e.target.closest('button[data-filter]');
        if (!btn) return;
        const wasOn = btn.getAttribute('aria-pressed') === 'true';
        cards.querySelectorAll('button[data-filter]').forEach(b => b.setAttribute('aria-pressed', 'false'));
        if (wasOn) { applyFilter(''); return; }
        btn.setAttribute('aria-pressed', 'true');
        applyFilter(btn.dataset.filter);
    });

    // inventory.js says "a row's status changed" (typing, steppers, Cancel, a restored field).
    // Counted once after the burst, so Cancel (which refreshes every row) recounts once.
    let recountQueued = false;
    document.addEventListener('inventory:rows-changed', () => {
        if (recountQueued) return;
        recountQueued = true;
        setTimeout(() => { recountQueued = false; recount(); }, 0);
    });

    const body = document.querySelector('.inventory-table tbody');
    if (body) {
        body.addEventListener('input', () => setTimeout(recount, 0));
        body.addEventListener('click', () => setTimeout(recount, 0));
    }
    const summary = document.querySelector('.low-stock-summary');
    if (summary) new MutationObserver(recount).observe(summary, { childList: true, subtree: true, characterData: true, attributes: true });
    recount();
})();
