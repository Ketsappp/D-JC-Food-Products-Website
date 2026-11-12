/**
 * Menu page: "Add Product" and "Edit Product" (admin only).
 *
 * One form handles both:
 *   - Add:  add_menu_product.php
 *   - Edit: update_menu_product.php (opened from a product card)
 *
 * Fields: product name, image, category, price and the ingredients (recipe)
 * used to make one serving.
 *
 * Sizes: a product can be sold in 2 or more sizes (small / medium / large).
 * With sizes on, every size has its own price AND its own quantity of every
 * ingredient (a large pizza uses more cheese than a small one).
 */
(function () {
    'use strict';

    const openButton = document.getElementById('addMenuProductBtn');
    const modal = document.getElementById('addMenuModal');

    // Staff users never get the button or the modal.
    if (!openButton || !modal) return;

    const SIZES = [
        { key: 'small', label: 'Small' },
        { key: 'medium', label: 'Medium' },
        { key: 'large', label: 'Large' }
    ];

    const form = document.getElementById('addMenuForm');
    const titleElement = document.getElementById('addMenuTitle');
    const idInput = document.getElementById('addMenuId');
    const nameInput = document.getElementById('addMenuName');
    const imageInput = document.getElementById('addMenuImage');
    const imagePreview = document.getElementById('addMenuImagePreview');
    const categorySelect = document.getElementById('addMenuCategory');
    const singlePrice = document.getElementById('addMenuSinglePrice');
    const priceInput = document.getElementById('addMenuPrice');
    const hasSizesInput = document.getElementById('addMenuHasSizes');
    const sizesPanel = document.getElementById('addMenuSizesPanel');
    const ingredientList = document.getElementById('addMenuIngredientsList');
    const addIngredientButton = document.getElementById('addMenuIngredientBtn');
    const message = document.getElementById('addMenuMessage');
    const cancelButton = document.getElementById('cancelAddMenu');
    const saveButton = document.getElementById('saveAddMenu');

    let ingredients = [];
    let isEditing = false;
    let isSaving = false;
    let previewUrl = null;

    function setMessage(text) {
        message.textContent = text || '';
    }

    function saveLabel() {
        return isEditing ? 'Save Changes' : 'Save Product';
    }

    // ---------- sizes ----------

    function sizesOn() {
        return hasSizesInput.checked;
    }

    function sizeCheckbox(key) {
        return form.querySelector('input[name="sizes[]"][value="' + key + '"]');
    }

    function sizePriceInput(key) {
        return form.elements['price_' + key];
    }

    function chosenSizes() {
        return SIZES
            .map(function (size) { return size.key; })
            .filter(function (key) { return sizeCheckbox(key).checked; });
    }

    function rowBaseInput(row) {
        return row.querySelector('.qty-with-unit input');
    }

    function rowSizeInputs(row) {
        return Array.from(row.querySelectorAll('.size-qty input'));
    }

    // Show only the quantity fields that apply: one field without sizes,
    // one per ticked size with sizes. Hidden fields are disabled so they
    // are neither validated nor sent.
    function updateRowQuantityFields(row) {
        const on = sizesOn();
        const chosen = chosenSizes();

        const baseWrap = row.querySelector('.qty-with-unit');
        const baseInput = rowBaseInput(row);

        baseWrap.hidden = on;
        baseInput.disabled = on;
        baseInput.required = !on;

        const sizeFields = row.querySelector('.qty-per-size');
        sizeFields.hidden = !on;

        row.querySelectorAll('.size-qty').forEach(function (field) {
            const visible = on && chosen.includes(field.dataset.size);
            const input = field.querySelector('input');

            field.hidden = !visible;
            input.disabled = !visible;
            input.required = visible;
        });
    }

    // Food categories (like pizza) are sold in one size only: no "different sizes" switch.
    const sizesToggleBox = hasSizesInput.closest('.add-menu-sizes-toggle');

    function categoryIsFood() {
        const option = categorySelect.options[categorySelect.selectedIndex];

        return !!option && option.dataset.food === '1';
    }

    // Apply the Sizes switch and the ticked sizes to the whole form.
    function applySizeMode() {
        const food = categoryIsFood();

        if (sizesToggleBox) sizesToggleBox.hidden = food;

        if (food && hasSizesInput.checked) {
            // Back to one size: keep a typed price and the quantities instead of losing them.
            if (priceInput.value === '') {
                const firstPrice = SIZES
                    .map(function (size) { return sizePriceInput(size.key).value; })
                    .find(function (value) { return value !== ''; });

                if (firstPrice) priceInput.value = firstPrice;
            }

            ingredientList
                .querySelectorAll('.edit-menu-ingredient-row')
                .forEach(function (row) { carryQuantities(row, false); });

            hasSizesInput.checked = false;
        }

        const on = sizesOn();

        sizesPanel.hidden = !on;
        singlePrice.hidden = on;
        priceInput.disabled = on;
        modal.classList.toggle('sizes-on', on);

        SIZES.forEach(function (size) {
            const checkbox = sizeCheckbox(size.key);
            const price = sizePriceInput(size.key);

            // Without sizes nothing about sizes is sent (the ticks are kept,
            // in case the switch is turned back on).
            checkbox.disabled = !on;

            price.disabled = !on || !checkbox.checked;
            price.required = on && checkbox.checked;
        });

        ingredientList
            .querySelectorAll('.edit-menu-ingredient-row')
            .forEach(updateRowQuantityFields);
    }

    // Switching modes keeps what was typed: a single quantity becomes the
    // starting quantity of every size, and the other way round.
    function carryQuantities(row, toSizes) {
        const baseInput = rowBaseInput(row);
        const sizeInputs = rowSizeInputs(row);

        if (toSizes) {
            sizeInputs.forEach(function (input) {
                if (input.value === '' && baseInput.value !== '') {
                    input.value = baseInput.value;
                }
            });
        } else if (baseInput.value === '') {
            const first = sizeInputs.find(function (input) {
                return input.value !== '';
            });

            if (first) baseInput.value = first.value;
        }
    }

    // Choosing another category can switch the sizes option on or off.
    categorySelect.addEventListener('change', applySizeMode);

    hasSizesInput.addEventListener('change', function () {
        const on = sizesOn();

        // Start with Small and Medium ticked so the admin only has to adjust.
        if (on && chosenSizes().length === 0) {
            sizeCheckbox('small').checked = true;
            sizeCheckbox('medium').checked = true;
        }

        // Keep the typed price: the single price becomes each size's start.
        if (on) {
            SIZES.forEach(function (size) {
                const price = sizePriceInput(size.key);

                if (sizeCheckbox(size.key).checked && price.value === '' && priceInput.value !== '') {
                    price.value = priceInput.value;
                }
            });
        }

        ingredientList
            .querySelectorAll('.edit-menu-ingredient-row')
            .forEach(function (row) { carryQuantities(row, on); });

        setMessage('');
        applySizeMode();
    });

    // Ticking a size: its ingredient quantities start from another size's.
    form.addEventListener('change', function (event) {
        if (!event.target.matches('input[name="sizes[]"]')) return;

        ingredientList
            .querySelectorAll('.edit-menu-ingredient-row')
            .forEach(function (row) {
                const inputs = rowSizeInputs(row);
                const filled = inputs.find(function (input) {
                    return input.value !== '';
                });

                if (!filled) return;

                inputs.forEach(function (input) {
                    if (input.value === '') input.value = filled.value;
                });
            });

        setMessage('');
        applySizeMode();
    });

    // ---------- image preview ----------

    function clearPreviewUrl() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
    }

    function showPlaceholder(text) {
        const placeholder = document.createElement('span');
        placeholder.textContent = text;
        imagePreview.replaceChildren(placeholder);
    }

    function showImage(source, altText) {
        const image = document.createElement('img');
        image.src = source;
        image.alt = altText;

        // A missing file falls back to the placeholder.
        image.addEventListener('error', function () {
            showPlaceholder('No image available');
        });

        imagePreview.replaceChildren(image);
    }

    function resetPreview() {
        clearPreviewUrl();
        showPlaceholder('No image selected');
    }

    imageInput.addEventListener('change', function () {
        const file = imageInput.files[0];

        if (!file || !file.type.startsWith('image/')) {
            clearPreviewUrl();

            // Nothing chosen: fall back to the product's current picture.
            if (isEditing && modal.dataset.currentImage) {
                showImage(modal.dataset.currentImage, 'Current product image');
            } else {
                showPlaceholder('No image selected');
            }

            return;
        }

        clearPreviewUrl();
        previewUrl = URL.createObjectURL(file);
        showImage(previewUrl, 'Selected product image');
    });

    // ---------- ingredients ----------

    // Both endpoints describe an ingredient slightly differently.
    function normalizeIngredient(item) {
        return {
            id: Number(item.id),
            name: item.product_name !== undefined ? item.product_name : item.name,
            unit: item.unit || '',
            // Recipes are measured in grams / milliliters (pieces stay pieces).
            recipeUnit: item.base_unit || item.unit || '',
            stock: item.quantity !== undefined ? item.quantity : item.stock
        };
    }

    async function fetchJson(url) {
        const response = await fetch(url, { cache: 'no-store' });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to load the ingredients.');
        }

        return data;
    }

    // Returns the product's saved recipe (empty when adding).
    async function loadIngredients(productId) {
        if (productId) {
            const data = await fetchJson(
                'get_menu_recipe.php?product_id=' + encodeURIComponent(productId)
            );

            ingredients = (data.ingredients || []).map(normalizeIngredient);

            return data.recipe || [];
        }

        const data = await fetchJson('get_inventory_ingredients.php');

        ingredients = (data.ingredients || []).map(normalizeIngredient);

        return [];
    }

    // An ingredient can only be used once per product, so hide the ones
    // that are already chosen in another row.
    function refreshIngredientOptions() {
        const selects = Array.from(ingredientList.querySelectorAll('select'));
        const chosen = selects.map(function (select) { return select.value; });

        selects.forEach(function (select) {
            Array.from(select.options).forEach(function (option) {
                option.disabled =
                    option.value !== '' &&
                    option.value !== select.value &&
                    chosen.includes(option.value);
            });
        });
    }

    function makeQuantityInput(name) {
        const input = document.createElement('input');

        input.type = 'number';
        input.name = name;
        input.min = '0.001';
        input.step = '0.001';

        return input;
    }

    function addIngredientRow(saved) {
        const row = document.createElement('div');
        row.className = 'edit-menu-ingredient-row';

        // Ingredient select
        const selectField = document.createElement('div');
        const selectLabel = document.createElement('label');
        selectLabel.textContent = 'Inventory Ingredient';

        const select = document.createElement('select');
        select.name = 'ingredient_inventory_id[]';
        select.required = true;

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select ingredient';
        select.appendChild(placeholder);

        ingredients.forEach(function (ingredient) {
            const option = document.createElement('option');
            option.value = String(ingredient.id);
            option.textContent =
                ingredient.name +
                ' (Stock: ' + ingredient.stock + ' ' + ingredient.unit + ')';
            option.dataset.unit = ingredient.recipeUnit;
            select.appendChild(option);
        });

        selectField.append(selectLabel, select);

        // Quantity per serving: one field, or one per size.
        const quantityField = document.createElement('div');
        quantityField.className = 'qty-field';

        const quantityLabel = document.createElement('label');
        quantityLabel.textContent = 'Qty per serving';

        const quantityWrap = document.createElement('div');
        quantityWrap.className = 'qty-with-unit';

        const quantityInput = makeQuantityInput('ingredient_quantity[]');
        quantityInput.setAttribute('aria-label', 'Quantity per serving');

        const unitHint = document.createElement('span');
        unitHint.className = 'unit-hint';

        quantityWrap.append(quantityInput, unitHint);

        const sizeFields = document.createElement('div');
        sizeFields.className = 'qty-per-size';

        SIZES.forEach(function (size) {
            const field = document.createElement('div');
            field.className = 'size-qty';
            field.dataset.size = size.key;

            const name = document.createElement('span');
            name.className = 'size-qty-name';
            name.textContent = size.label;

            const wrap = document.createElement('div');
            wrap.className = 'qty-with-unit';

            const input = makeQuantityInput('ingredient_quantity_' + size.key + '[]');
            input.setAttribute('aria-label', size.label + ' quantity per serving');

            const hint = document.createElement('span');
            hint.className = 'unit-hint';

            wrap.append(input, hint);
            field.append(name, wrap);
            sizeFields.appendChild(field);
        });

        quantityField.append(quantityLabel, quantityWrap, sizeFields);

        function updateUnitHints() {
            const selected = select.options[select.selectedIndex];
            const unit = (selected && selected.dataset.unit) || '';

            row.querySelectorAll('.unit-hint').forEach(function (hint) {
                hint.textContent = unit;
            });
        }

        select.addEventListener('change', function () {
            updateUnitHints();
            refreshIngredientOptions();
        });

        // Remove button
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'edit-menu-remove-ingredient';
        removeButton.textContent = 'Remove';

        removeButton.addEventListener('click', function () {
            row.remove();
            refreshIngredientOptions();
            updateEmptyHint();
        });

        row.append(selectField, quantityField, removeButton);
        ingredientList.appendChild(row);

        // Fill in a saved ingredient (edit mode).
        if (saved) {
            select.value = String(saved.inventory_product_id);
            quantityInput.value = String(saved.quantity_required);

            // A size without its own saved quantity starts from the base one.
            SIZES.forEach(function (size) {
                const value = saved['quantity_' + size.key];
                const input = row.querySelector('input[name="ingredient_quantity_' + size.key + '[]"]');

                input.value = String(value !== null && value !== undefined ? value : saved.quantity_required);
            });

            updateUnitHints();
        }

        updateRowQuantityFields(row);
        updateEmptyHint();
        refreshIngredientOptions();

        return row;
    }

    function updateEmptyHint() {
        const existing = ingredientList.querySelector('.add-menu-empty');
        const hasRows = ingredientList.querySelector('.edit-menu-ingredient-row');

        if (hasRows && existing) existing.remove();

        if (!hasRows && !existing) {
            const hint = document.createElement('p');
            hint.className = 'add-menu-empty';
            hint.textContent = 'No ingredients added yet.';
            ingredientList.appendChild(hint);
        }
    }

    addIngredientButton.addEventListener('click', function () {
        if (ingredients.length === 0) {
            setMessage('There are no ingredients in the inventory yet. Add them in Inventory first.');
            return;
        }

        setMessage('');
        addIngredientRow().querySelector('select').focus();
    });

    // ---------- open / close ----------

    // Prefill the sizes of an existing product: 2 or more priced sizes
    // means the product is sold in sizes.
    function fillSizesFrom(product) {
        const prices = { small: product.small, medium: product.med, large: product.lrg };

        const priced = SIZES.filter(function (size) {
            return Number(prices[size.key]) > 0;
        });

        if (priced.length < 2) return;

        hasSizesInput.checked = true;

        priced.forEach(function (size) {
            sizeCheckbox(size.key).checked = true;
            sizePriceInput(size.key).value = String(prices[size.key]);
        });
    }

    // product: null to add, or a menu item (from the product card) to edit.
    async function openModal(product) {
        isEditing = !!product;

        form.reset();
        clearPreviewUrl();
        ingredientList.replaceChildren();
        updateEmptyHint();
        setMessage('');

        idInput.value = isEditing ? String(product.id) : '';
        titleElement.textContent = isEditing ? 'Edit Menu Product' : 'Add Menu Product';

        saveButton.disabled = false;
        saveButton.textContent = saveLabel();

        if (isEditing) {
            nameInput.value = product.name || '';
            categorySelect.value = product.category || '';
            priceInput.value = String(product.price ?? '');
            fillSizesFrom(product);

            // Show the current picture; choosing a file replaces it.
            modal.dataset.currentImage = product.img || '';

            if (product.img) {
                showImage(product.img, 'Current product image');
            } else {
                showPlaceholder('No image selected');
            }
        } else {
            modal.dataset.currentImage = '';
            resetPreview();

            // Start on the category being viewed (so adding a product to a new category is one step).
            const viewing = sessionStorage.getItem('lastMenuCategory');

            if (viewing && Array.from(categorySelect.options).some(function (option) { return option.value === viewing; })) {
                categorySelect.value = viewing;
            }
        }

        applySizeMode();

        modal.classList.add('show');
        nameInput.focus();

        try {
            const recipe = await loadIngredients(isEditing ? product.id : null);

            if (ingredients.length === 0) {
                setMessage('There are no ingredients in the inventory yet. Add them in Inventory first.');
            } else if (recipe.length > 0) {
                recipe.forEach(addIngredientRow);
            } else {
                // Start with one empty row so the section is ready to fill in.
                addIngredientRow();
            }
        } catch (error) {
            setMessage(error.message);
        }
    }

    function closeModal() {
        if (isSaving) return;

        modal.classList.remove('show');
        clearPreviewUrl();
    }

    openButton.addEventListener('click', function () {
        openModal(null);
    });

    // "Edit Product" on a menu card (see order.js).
    document.addEventListener('menu-product-edit', function (event) {
        openModal(event.detail);
    });

    // The window closes only with its Cancel (or X) button. Clicking outside it or pressing
    // Escape does nothing, so a half-edited product is never lost by accident.
    cancelButton.addEventListener('click', closeModal);

    // ---------- save ----------

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (isSaving) return;

        setMessage('');

        // A product sold in sizes needs at least two of them.
        if (sizesOn() && chosenSizes().length < 2) {
            setMessage('Choose at least two sizes, or turn sizes off.');
            return;
        }

        if (!form.reportValidity()) return;

        const rows = ingredientList.querySelectorAll('.edit-menu-ingredient-row');

        if (rows.length === 0) {
            setMessage('Please add at least one ingredient.');
            return;
        }

        isSaving = true;
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';

        try {
            const response = await fetch(
                isEditing ? 'update_menu_product.php' : 'add_menu_product.php',
                { method: 'POST', body: new FormData(form) }
            );

            let result;

            try {
                result = await response.json();
            } catch (parseError) {
                throw new Error('The server returned an unexpected response.');
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save the menu product.');
            }

            // Reopen the menu on the category that holds the product.
            sessionStorage.setItem('lastMenuCategory', categorySelect.value);
            window.location.reload();

        } catch (error) {
            setMessage(error.message);

            isSaving = false;
            saveButton.disabled = false;
            saveButton.textContent = saveLabel();
        }
    });
})();
