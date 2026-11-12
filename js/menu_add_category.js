/**
 * Menu page: "Add Category" (admin only).
 *
 * Saves a new menu category with add_menu_category.php, then reloads the Menu
 * on that (still empty) category so products can be added to it right away.
 * The category buttons and the Add / Edit Product list are drawn from the database,
 * so the new category shows up in both.
 */
(function () {
    'use strict';

    const openButton = document.getElementById('addMenuCategoryBtn');
    const modal = document.getElementById('addCategoryModal');

    // Staff never get the button or the dialog.
    if (!openButton || !modal) return;

    const form = document.getElementById('addCategoryForm');
    const nameInput = document.getElementById('addCategoryName');
    const foodInput = document.getElementById('addCategoryFood');
    const message = document.getElementById('addCategoryMessage');
    const cancelButton = document.getElementById('cancelAddCategory');
    const saveButton = document.getElementById('saveAddCategory');

    let isSaving = false;

    function setMessage(text) {
        message.textContent = text || '';
    }

    function open() {
        form.reset();
        setMessage('');
        saveButton.disabled = false;
        isSaving = false;

        modal.classList.add('show');
        nameInput.focus();
    }

    function close() {
        modal.classList.remove('show');
        openButton.focus();
    }

    openButton.addEventListener('click', open);
    cancelButton.addEventListener('click', close);

    modal.addEventListener('mousedown', function (event) {
        if (event.target === modal) close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) close();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (isSaving) return;

        const name = nameInput.value.replace(/\s+/g, ' ').trim();

        if (name.length < 2) {
            setMessage('Enter a category name (at least 2 characters).');
            nameInput.focus();
            return;
        }

        // Same name (capitals ignored) as one that is already there?
        const same = Array.from(document.querySelectorAll('.menu-category-btn')).find(function (button) {
            return button.textContent.trim().toLowerCase() === name.toLowerCase();
        });

        if (same) {
            setMessage('The category "' + same.textContent.trim() + '" already exists.');
            nameInput.focus();
            return;
        }

        isSaving = true;
        saveButton.disabled = true;
        setMessage('');

        try {
            const body = new FormData();
            body.append('name', name);
            body.append('is_food', foodInput.checked ? '1' : '0');

            const response = await fetch('add_menu_category.php', { method: 'POST', body: body });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to add the category.');
            }

            // Open the Menu on the new category.
            sessionStorage.setItem('lastMenuCategory', data.category.name);
            window.location.reload();
        } catch (error) {
            setMessage(error.message || 'Unable to add the category.');
            isSaving = false;
            saveButton.disabled = false;
        }
    });
})();
