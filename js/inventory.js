document.addEventListener('DOMContentLoaded', function () {

    const alertBox = document.getElementById('alertBox');

    // Reload after a save, but come back to the same table page (e.g. editing on page 2 stays on page 2).
    function reloadKeepingPage() {
        try {
            if (window.__inventoryPage > 1) sessionStorage.setItem('djcInventoryPage', String(window.__inventoryPage));
            else sessionStorage.removeItem('djcInventoryPage');
        } catch (e) { /* storage unavailable: the page simply opens on page 1 */ }
        window.location.reload();
    }

    // Show a success / error message at the top of the page.
    function showAlert(message, type) {
        if (!alertBox) return;

        alertBox.textContent = message;
        alertBox.className = 'alert-box ' + type;
        alertBox.classList.remove('hidden');

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }
    
    // DUPLICATE PRODUCT WARNING MODAL

    const duplicateProductModal =
        document.getElementById('duplicateProductModal');

    const closeDuplicateProductModal =
        document.getElementById('closeDuplicateProductModal');

    if (duplicateProductModal && closeDuplicateProductModal) {

        // Close using the OK button.
        closeDuplicateProductModal.addEventListener('click', function () {
            duplicateProductModal.classList.add('hidden');
        });

        // Close when clicking outside the modal.
        duplicateProductModal.addEventListener('click', function (event) {
            if (event.target === duplicateProductModal) {
                duplicateProductModal.classList.add('hidden');
            }
        });

    }



    // ADD / EDIT INVENTORY ITEM

    const addInventoryBtn = document.getElementById('addInventoryBtn');
    const productModal = document.getElementById('productModal');
    const cancelProductModal = document.getElementById('cancelProductModal');
    const productForm = document.getElementById('productForm');
    const productModalTitle = document.getElementById('productModalTitle');
    const productCategory = document.getElementById('productCategory');
    const productUnit = document.getElementById('productUnit');

    // Items saved before the form was simplified may use older names.
    const LEGACY_CATEGORIES = {
        'Ingredient': 'Ingredients',
        'Material': 'Materials',
        'Matrials': 'Materials'
    };

    // Ingredients: Packs, Liters or Pcs.  Sinkers: kg.  Materials: Pcs.
    const UNIT_OPTIONS = {
        Ingredients: ['Packs', 'Liters', 'Pcs'],
        Sinkers: ['kg'],
        Materials: ['Pcs']
    };

    // Older records used other spellings.
    const LEGACY_UNITS = {
        pack: 'Packs', packs: 'Packs',
        l: 'Liters', liter: 'Liters', liters: 'Liters',
        kg: 'kg', g: 'kg', gram: 'kg', grams: 'kg',
        pcs: 'Pcs', pc: 'Pcs', piece: 'Pcs', pieces: 'Pcs'
    };

    const productQuantity = document.getElementById('productQuantity');

    // Pcs are whole numbers; Packs / Liters / kg can have up to 3 decimals.
    function applyQuantityRule() {
        const counted = productUnit.value === 'Pcs';

        productQuantity.step = counted ? '1' : '0.001';
        productQuantity.inputMode = counted ? 'numeric' : 'decimal';
    }

    // Fill the unit list for the chosen category.
    function populateUnits(category, selected) {
        const options = UNIT_OPTIONS[category] || [];

        productUnit.replaceChildren();

        const first = new Option(options.length ? 'Select unit' : 'Select category first', '');
        first.disabled = true;
        productUnit.add(first);

        options.forEach(function (unit) {
            productUnit.add(new Option(unit, unit));
        });

        productUnit.disabled = options.length === 0;
        productUnit.value = options.includes(selected) ? selected : '';
        applyQuantityRule();
    }

    // The edit form is only on the page for admins (staff get a view-only Inventory).
    if (productCategory && productUnit) {
        productCategory.addEventListener('change', function () {
            populateUnits(productCategory.value, productUnit.value);
        });

        productUnit.addEventListener('change', applyQuantityRule);
    }

    // Open the form empty (add) or filled in (edit).
    function openProductForm(item) {
        productForm.reset();
        populateUnits('', '');

        document.getElementById('productId').value = item ? item.id : '';
        productModalTitle.textContent = item ? 'Edit Inventory' : 'Add Product';

        // "Quantity is added to existing stock" only applies when adding, not editing.
        const mergeHint = document.getElementById('addMergeHint');

        if (mergeHint) mergeHint.hidden = !!item;

        if (item) {
            document.getElementById('productName').value = item.name || '';

            // Older categories that are not Ingredients or Materials stay
            // unselected so the admin picks one of the two.
            const category = LEGACY_CATEGORIES[item.category] || item.category;

            productCategory.value = category;

            if (productCategory.value !== category) {
                productCategory.value = '';
            }

            // Only the allowed units are offered. An older unit is mapped to its
            // new name when there is one (pack -> Packs); otherwise the admin
            // picks one of the allowed units.
            const rawUnit = item.unit || '';
            const unit = LEGACY_UNITS[rawUnit.toLowerCase()] || rawUnit;

            populateUnits(productCategory.value, unit);

            const quantity = Number(item.quantity);

            productQuantity.value = String(Number.isNaN(quantity) ? '' : quantity);

            document.getElementById('dateProcured').value =
                (item.dateProcured || '').slice(0, 10);

            document.getElementById('expirationDate').value =
                (item.expirationDate || '').slice(0, 10);
        }

        // The date this item was saved with. If it is an older date (a previous year) it may stay
        // as it is; a new or changed date has to be this year and not in the future.
        document.getElementById('dateProcured')
            .setAttribute('data-original', item ? (item.dateProcured || '').slice(0, 10) : '');

        productModal.classList.remove('hidden');
        document.getElementById('productName').focus();
    }

    if (addInventoryBtn) {
        addInventoryBtn.addEventListener('click', function () {
            openProductForm(null);
        });
    }

    // Cancel the form.
    if (cancelProductModal) {
        cancelProductModal.addEventListener('click', function (e) {
            e.preventDefault();
            productModal.classList.add('hidden');
        });
    }

    // Close the form when clicking its backdrop.
    if (productModal) {
        productModal.addEventListener('click', function (e) {
            if (e.target === productModal) {
                productModal.classList.add('hidden');
            }
        });
    }

// PRODUCT FORM

if (productForm) {

    productForm.addEventListener('submit', function (e) {

        e.preventDefault();

        const formData = new FormData(productForm);

        const productId = document.getElementById('productId').value;
        const productName = document
            .getElementById('productName')
            .value
            .trim();

        // An item that already exists is not created again: inventory_save.php adds the
        // quantity to its current stock and says so in the response.
        // SAVE PRODUCT

        // One click = one save (a double click must not add the quantity twice).
        const saveButton = productForm.querySelector('button[type="submit"]');

        if (saveButton) {
            if (saveButton.disabled) return;
            saveButton.disabled = true;
        }

        fetch('inventory_save.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const data = await response.text();
            const merged = response.headers.get('X-Inventory-Merged') === '1';

        // Handle duplicate product response
        if (response.status === 409) {

            const duplicateMessage =
                document.getElementById('duplicateProductMessage');

            if (duplicateProductModal && duplicateMessage) {

                duplicateMessage.textContent = data;
                duplicateProductModal.classList.remove('hidden');

            } else {

                // Fallback if the modal is missing from inventory.php.
                showAlert(data, 'error');

            }

            return;
        }

            // Handle successful save
            if (response.ok) {
                showAlert(data, 'success');

                productForm.reset();
                productModal.classList.add('hidden');

                // The "added to existing stock" message stays long enough to read.
                setTimeout(function () {
                    reloadKeepingPage();
                }, merged ? 3500 : 500);

            } else {
                showAlert(data, 'error');
            }
            })
            .catch(error => {
                console.error(error);

                showAlert(
                    'Something went wrong while saving the product.',
                    'error'
                );
            })
            .finally(() => {
                if (saveButton) saveButton.disabled = false;
            });

        });

    }

    // ACTIVATE / DEACTIVATE AN INVENTORY ITEM
    // An inactive item is kept, but it cannot be used as an ingredient.

    const deactivateModal = document.getElementById('deactivateProductModal');
    const deactivateName = document.getElementById('deactivateProductName');
    const deactivateUsedBy = document.getElementById('deactivateUsedBy');
    const deactivateMessage = document.getElementById('deactivateProductMessage');
    const cancelDeactivate = document.getElementById('cancelDeactivateProduct');
    const confirmDeactivate = document.getElementById('confirmDeactivateProduct');

    let statusTarget = null;

    async function sendItemStatus(id, status) {
        const formData = new FormData();

        formData.append('product_id', id);
        formData.append('product_type', 'inventory');
        formData.append('status', status);

        const response = await fetch('set_product_status.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to change the status.');
        }
    }

    function closeDeactivateModal() {
        if (!deactivateModal) return;

        deactivateModal.classList.add('hidden');
        statusTarget = null;

        confirmDeactivate.disabled = false;
        confirmDeactivate.textContent = 'Deactivate';
        deactivateMessage.textContent = '';
    }

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.status-toggle-btn');

        if (!button) return;

        // Deactivate: ask first.
        if (button.dataset.action === 'deactivate') {
            statusTarget = button;

            deactivateName.textContent = button.dataset.name || 'this item';
            deactivateMessage.textContent = '';

            const usedBy = Number(button.dataset.usedBy) || 0;

            deactivateUsedBy.classList.toggle('hidden', usedBy === 0);
            deactivateUsedBy.textContent = usedBy === 0 ? '' :
                usedBy + ' menu product' + (usedBy === 1 ? ' uses' : 's use') +
                ' this ingredient and will show as Unavailable.';

            confirmDeactivate.disabled = false;
            confirmDeactivate.textContent = 'Deactivate';
            deactivateModal.classList.remove('hidden');
            return;
        }

        // Activate: no confirmation needed.
        button.disabled = true;

        try {
            await sendItemStatus(button.dataset.id, 'active');
            reloadKeepingPage();
        } catch (error) {
            showAlert(error.message, 'error');
            button.disabled = false;
        }
    });

    if (deactivateModal) {
        cancelDeactivate.addEventListener('click', closeDeactivateModal);

        deactivateModal.addEventListener('click', function (e) {
            if (e.target === deactivateModal && !confirmDeactivate.disabled) {
                closeDeactivateModal();
            }
        });

        confirmDeactivate.addEventListener('click', async function () {
            if (!statusTarget) return;

            confirmDeactivate.disabled = true;
            confirmDeactivate.textContent = 'Deactivating...';
            deactivateMessage.textContent = '';

            try {
                await sendItemStatus(statusTarget.dataset.id, 'inactive');
                reloadKeepingPage();
            } catch (error) {
                deactivateMessage.textContent = error.message;
                confirmDeactivate.disabled = false;
                confirmDeactivate.textContent = 'Try Again';
            }
        });
    }


    // INVENTORY TABLE
    // Quantity steppers, filters, pagination and "Save & Report Stock".

    // Every row carries its own low-stock limit (data-low-limit, from inventory_low_limit() in
    // units.php: one limit per unit, editable on this page). This is only a fallback.
    const LOW_STOCK_LIMIT = 10;
    const PAGE_SIZE = 12;

    const inventoryBody = document.getElementById('inventoryTableBody');

    if (inventoryBody) {

        const inventoryRows = Array.from(
            inventoryBody.querySelectorAll('.inventory-row')
        );

        const filtersBtn = document.getElementById('filtersBtn');
        const filtersPanel = document.getElementById('filtersPanel');
        const filterSearch = document.getElementById('filterSearch');
        const filterCategory = document.getElementById('filterCategory');
        const filterAvailability = document.getElementById('filterAvailability');
        const filterStatus = document.getElementById('filterStatus');
        const filterExpiry = document.getElementById('filterExpiry');
        const resetFiltersBtn = document.getElementById('resetFiltersBtn');
        const prevPageBtn = document.getElementById('prevPageBtn');
        const nextPageBtn = document.getElementById('nextPageBtn');
        const pageIndicator = document.getElementById('pageIndicator');
        const emptyMessage = document.getElementById('inventoryEmptyMessage');
        const inventoryTable = document.getElementById('inventoryTable');
        const editInventoryBtn = document.getElementById('editInventoryBtn');
        const cancelEditBtn = document.getElementById('cancelEditBtn');

        let currentPage = 1;
        // Coming back from a save: open the page the admin was on.
        try {
            const savedPage = parseInt(sessionStorage.getItem('djcInventoryPage') || '', 10);
            sessionStorage.removeItem('djcInventoryPage');
            if (savedPage > 1) currentPage = savedPage;
        } catch (e) { /* ignore */ }

        // ---- helpers ----

        // Low stock = at or below the limit of the row's unit (the row carries its own limit).
        function getAvailability(quantity, limit) {
            // A limit of 0 is valid ("no low-stock warning"), so only a missing number falls back.
            const lowLimit = Number.isFinite(limit) ? limit : LOW_STOCK_LIMIT;

            if (quantity <= 0) {
                return { key: 'no-stock', label: 'No Stocks' };
            }

            if (quantity <= lowLimit) {
                return { key: 'low-stock', label: 'Low Stocks' };
            }

            return { key: 'in-stock', label: 'In Stock' };
        }

        // Returns a number >= 0, or null when the field is not valid.
        // Pieces must be whole; Packs / Liters may have up to 3 decimals.
        function readQuantity(input) {
            const value = input.value.trim();
            const counted = input.closest('.inventory-row').dataset.unitFactor === '0';
            const pattern = counted ? /^\d+$/ : /^\d+(\.\d{1,3})?$/;

            return pattern.test(value) ? Number(value) : null;
        }

        // "4,999 g" next to 9.998 Packs (nothing for pieces).
        function updateEquivalent(row, quantity) {
            const note = row.querySelector('.qty-equiv');
            const factor = Number(row.dataset.unitFactor);

            if (!note) return;

            note.textContent = (quantity === null || !factor || factor === 1)
                ? ''
                : (Math.round(quantity * factor * 10) / 10).toLocaleString('en-US') + ' ' + row.dataset.unitBase;
        }

        function hasUnsavedChanges() {
            return inventoryRows.some(function (row) {
                return row.classList.contains('is-dirty');
            });
        }

        // Update one row's highlight and availability label from its quantity.
        function refreshRow(row) {
            const input = row.querySelector('.qty-input');
            const quantity = readQuantity(input);
            const original = Number(row.dataset.originalQty);
            const label = row.querySelector('.availability');

            row.classList.toggle('is-invalid', quantity === null);
            updateEquivalent(row, quantity);

            if (quantity === null) {
                row.classList.remove('is-dirty');
                return;
            }

            row.classList.toggle('is-dirty', quantity !== original);

            const status = getAvailability(quantity, Number(row.dataset.lowLimit));

            label.textContent = status.label;
            label.className = 'availability ' + status.key;
            row.classList.toggle(
                'row-alert',
                status.key !== 'in-stock' && row.dataset.active !== '0'
            );

            // Tell the summary cards (admin_summary.js) a row's status may have changed, so
            // they always match the table - also when it changes from Cancel or a restored field.
            document.dispatchEvent(new CustomEvent('inventory:rows-changed'));
        }

        // ---- edit mode ----
        // Quantities are plain text until "Edit Inventory" is pressed.

        let isEditing = false;

        function setEditing(on) {
            isEditing = on;
            inventoryTable.classList.toggle('is-editing', on);

            inventoryRows.forEach(function (row) {
                const input = row.querySelector('.qty-input');

                input.readOnly = !on || row.dataset.active === '0';
                input.tabIndex = on ? 0 : -1;
            });

            // Staff have no Edit / Cancel buttons (view-only inventory).
            if (editInventoryBtn && cancelEditBtn) {
                editInventoryBtn.textContent = on ? 'Save & Report Stock' : 'Edit Inventory';
                editInventoryBtn.classList.toggle('btn-save-stock', on);
                editInventoryBtn.classList.toggle('btn-secondary', !on);
                cancelEditBtn.classList.toggle('hidden', !on);
            }
        }

        // Leave edit mode and drop every change that was not saved.
        function cancelEditing() {
            inventoryRows.forEach(function (row) {
                row.querySelector('.qty-input').value = row.dataset.originalQty;
                refreshRow(row);
            });

            setEditing(false);

            // The saved statuses are back, so an active filter (e.g. "Out of stock") must match them.
            renderTable();
        }

        // ---- filters + pagination ----

        function rowMatchesFilters(row) {
            const search = filterSearch.value.trim().toLowerCase();
            const category = filterCategory.value;
            const availability = filterAvailability.value;
            const status = filterStatus.value;
            const expiry = filterExpiry ? filterExpiry.value : '';

            if (expiry && row.dataset.expiry !== expiry) {
                return false;
            }

            if (search && !row.dataset.name.includes(search)) {
                return false;
            }

            if (category && row.dataset.category !== category) {
                return false;
            }

            if (status !== '' && row.dataset.active !== status) {
                return false;
            }

            if (
                availability &&
                !row.querySelector('.availability').classList.contains(availability)
            ) {
                return false;
            }

            return true;
        }

        function renderTable() {
            const matches = inventoryRows.filter(rowMatchesFilters);
            const totalPages = Math.max(1, Math.ceil(matches.length / PAGE_SIZE));

            currentPage = Math.min(Math.max(currentPage, 1), totalPages);
            window.__inventoryPage = currentPage;

            const first = (currentPage - 1) * PAGE_SIZE;
            const visible = new Set(matches.slice(first, first + PAGE_SIZE));

            inventoryRows.forEach(function (row) {
                row.hidden = !visible.has(row);
            });

            if (emptyMessage) {
                emptyMessage.classList.toggle('hidden', matches.length > 0);

                emptyMessage.textContent = inventoryRows.length === 0
                    ? 'No inventory products found.'
                    : 'No products match your filters.';
            }

            pageIndicator.textContent = matches.length === 0
                ? ''
                : 'Page ' + currentPage + ' of ' + totalPages;

            prevPageBtn.disabled = currentPage <= 1;
            nextPageBtn.disabled = currentPage >= totalPages;
        }

        function resetToFirstPage() {
            currentPage = 1;
            renderTable();
        }

        if (filtersBtn && filtersPanel) {
            filtersBtn.addEventListener('click', function () {
                const isOpen = filtersPanel.classList.toggle('hidden') === false;

                filtersBtn.setAttribute('aria-expanded', String(isOpen));

                if (isOpen) {
                    filterSearch.focus();
                }
            });
        }

        filterSearch.addEventListener('input', resetToFirstPage);
        filterCategory.addEventListener('change', resetToFirstPage);
        filterAvailability.addEventListener('change', resetToFirstPage);
        filterStatus.addEventListener('change', resetToFirstPage);
        if (filterExpiry) filterExpiry.addEventListener('change', resetToFirstPage);

        resetFiltersBtn.addEventListener('click', function () {
            filterSearch.value = '';
            filterCategory.value = '';
            filterAvailability.value = '';
            filterStatus.value = '';
            if (filterExpiry) filterExpiry.value = '';
            document.querySelectorAll('[data-expiry-filter]').forEach(b => b.setAttribute('aria-pressed', 'false'));
            document.querySelectorAll('button[data-filter]').forEach(b => b.setAttribute('aria-pressed', 'false'));
            resetToFirstPage();
        });

        prevPageBtn.addEventListener('click', function () {
            currentPage -= 1;
            renderTable();
        });

        nextPageBtn.addEventListener('click', function () {
            currentPage += 1;
            renderTable();
        });

        // ---- quantity editing ----

        inventoryBody.addEventListener('click', function (event) {
            const stepper = event.target.closest('.stepper');

            if (!stepper || !isEditing) return;

            const row = stepper.closest('.inventory-row');
            const input = row.querySelector('.qty-input');
            const current = readQuantity(input) ?? 0;

            input.value = Math.round(Math.max(0, current + Number(stepper.dataset.step)) * 1000) / 1000;
            refreshRow(row);
        });

        inventoryBody.addEventListener('input', function (event) {
            if (!event.target.classList.contains('qty-input')) return;

            refreshRow(event.target.closest('.inventory-row'));
        });

        // Restore the saved value when the field is left empty or invalid.
        inventoryBody.addEventListener('focusout', function (event) {
            if (!event.target.classList.contains('qty-input')) return;

            const row = event.target.closest('.inventory-row');

            if (readQuantity(event.target) === null) {
                event.target.value = row.dataset.originalQty;
                refreshRow(row);
            }
        });

        // Warn before leaving the page with quantities that were not saved.
        window.addEventListener('beforeunload', function (event) {
            if (!hasUnsavedChanges()) return;

            event.preventDefault();
            event.returnValue = '';
        });

        // ---- low-stock indicators (page heading + sidebar badge) ----

        function updateLowStockIndicators(total) {
            const summary = document.querySelector('.low-stock-summary');

            if (summary) {
                summary.classList.toggle('in-stock-summary', total === 0);
                summary.textContent = '';

                if (total > 0) {
                    const dot = document.createElement('span');
                    dot.className = 'low-stock-indicator';
                    summary.appendChild(dot);
                    summary.appendChild(document.createTextNode(
                        ' ' + total + ' low-stock item' + (total !== 1 ? 's' : '') +
                        (total === 1 ? ' needs' : ' need') + ' attention.'
                    ));
                } else {
                    summary.textContent = 'All inventory items have sufficient stock.';
                }
            }

            const activeNav = document.querySelector('.nav-menu .nav-item.active');

            if (!activeNav) return;

            let badge = activeNav.querySelector('.notification-badge');

            if (total === 0) {
                if (badge) badge.remove();
                return;
            }

            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'notification-badge';
                activeNav.appendChild(badge);
            }

            badge.textContent = total;
        }

        // ---- Save & Report Stock ----

        function countStock() {
            let low = 0;
            let out = 0;

            inventoryRows.forEach(function (row) {
                // Inactive items are not part of the stock report.
                if (row.dataset.active === '0') return;

                const quantity = Number(row.querySelector('.qty-input').value);

                if (quantity <= 0) {
                    out += 1;
                } else if (quantity <= (Number.isFinite(Number(row.dataset.lowLimit)) && row.dataset.lowLimit !== '' ? Number(row.dataset.lowLimit) : LOW_STOCK_LIMIT)) {
                    low += 1;
                }
            });

            return { low: low, out: out };
        }

        function stockReport(low, out) {
            return low + ' low stock, ' + out + ' out of stock.';
        }

        if (editInventoryBtn) {
            cancelEditBtn.addEventListener('click', cancelEditing);

            editInventoryBtn.addEventListener('click', async function () {

                // First press: make the quantities editable.
                if (!isEditing) {
                    setEditing(true);
                    return;
                }

                const invalidRow = inventoryRows.find(function (row) {
                    return row.classList.contains('is-invalid');
                });

                if (invalidRow) {
                    // Show the page that holds the invalid row, then focus it.
                    currentPage = Math.floor(
                        inventoryRows.filter(rowMatchesFilters).indexOf(invalidRow) / PAGE_SIZE
                    ) + 1;
                    renderTable();

                    invalidRow.querySelector('.qty-input').focus();
                    showAlert(
                        'Quantities must be 0 or more: whole numbers for pieces, up to 3 decimals for Packs and Liters.',
                        'error'
                    );
                    return;
                }

                const changedRows = inventoryRows.filter(function (row) {
                    return row.classList.contains('is-dirty');
                });

                // Nothing to save: just report the current stock levels.
                if (changedRows.length === 0) {
                    setEditing(false);

                    const counts = countStock();

                    showAlert(
                        'No quantity changes to save. Stock report: ' +
                        stockReport(counts.low, counts.out),
                        'success'
                    );
                    return;
                }

                const items = changedRows.map(function (row) {
                    return {
                        id: Number(row.dataset.id),
                        quantity: Number(row.querySelector('.qty-input').value)
                    };
                });

                editInventoryBtn.disabled = true;
                editInventoryBtn.textContent = 'Saving...';

                try {
                    const response = await fetch('inventory_update_stock.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ items: items })
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(
                            result.message || 'Unable to save the stock changes.'
                        );
                    }

                    // The saved values become the new baseline.
                    changedRows.forEach(function (row) {
                        const quantity = row.querySelector('.qty-input').value;

                        row.dataset.originalQty = quantity;

                        // Keep Edit / View Details in step with the saved value.
                        row.querySelectorAll('.edit-product-btn, .view-product-btn')
                            .forEach(function (button) {
                                button.dataset.quantity = quantity;
                            });

                        refreshRow(row);
                    });

                    setEditing(false);
                    updateLowStockIndicators(result.low_stock + result.out_of_stock);

                    showAlert(
                        'Stock saved (' + result.updated + ' item' +
                        (result.updated !== 1 ? 's' : '') + ' updated). ' +
                        'Stock report: ' +
                        stockReport(result.low_stock, result.out_of_stock),
                        'success'
                    );

                } catch (error) {
                    showAlert(error.message, 'error');

                } finally {
                    editInventoryBtn.disabled = false;
                    editInventoryBtn.textContent = isEditing
                        ? 'Save & Report Stock'
                        : 'Edit Inventory';
                }
            });
        }

        setEditing(false);
        renderTable();
    }


    // DOWNLOAD BUTTON

    const downloadBtn = document.getElementById('downloadBtn');

    if (downloadBtn) {

        downloadBtn.addEventListener('click', function () {

            showAlert(
                'Download will be connected later.',
                'success'
            );

        });

    }


    // REPORT BUTTON

    const reportBtn = document.getElementById('reportBtn');

    if (reportBtn) {

        reportBtn.addEventListener('click', function () {

            showAlert(
                'Report will be connected later.',
                'success'
            );

        });

    }


    // EDIT INVENTORY ITEM BUTTON (row action)

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.edit-product-btn');

        if (!button) return;

        event.preventDefault();

        openProductForm({
            id: button.dataset.id,
            name: button.dataset.name,
            category: button.dataset.category,
            unit: button.dataset.unit,
            quantity: button.dataset.quantity,
            dateProcured: button.dataset.dateProcured,
            expirationDate: button.dataset.expirationDate
        });
    });


    // VIEW INVENTORY DETAILS

    const productDetailsModal = document.getElementById('productDetailsModal');
    const closeProductDetails = document.getElementById('closeProductDetails');

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.view-product-btn');

        if (!button || !productDetailsModal) return;

        const data = button.dataset;

        document.getElementById('detailsProductName').textContent = data.name || '';
        document.getElementById('detailsCategory').textContent = data.category || '';
        document.getElementById('detailsQuantity').textContent = data.quantity || '0';
        document.getElementById('detailsUnit').textContent = data.unit || '';
        document.getElementById('detailsDateProcured').textContent =
            data.dateProcured || 'Not specified';
        document.getElementById('detailsExpirationDate').textContent =
            data.expirationDate || 'Not specified';

        productDetailsModal.classList.remove('hidden');
    });

    if (closeProductDetails) {
        closeProductDetails.addEventListener('click', function () {
            productDetailsModal.classList.add('hidden');
        });
    }

    // Close when clicking outside the modal
    if (productDetailsModal) {
        productDetailsModal.addEventListener('click', function (e) {
            if (e.target === productDetailsModal) {
                productDetailsModal.classList.add('hidden');
            }
        });
    }
});
