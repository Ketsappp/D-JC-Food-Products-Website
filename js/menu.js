document.addEventListener('DOMContentLoaded', function () {

    const alertBox = document.getElementById('alertBox');

    function showAlert(message, type) {
        alertBox.textContent = message;
        alertBox.className = 'alert-box ' + type;
        alertBox.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // --- Categories ---
    document.querySelectorAll('.category-header').forEach(function (header) {
        header.addEventListener('click', function () {
            const section = header.closest('.menu-category');
            const grid = section.querySelector('.product-grid');
            const chevron = header.querySelector('.chevron');

            grid.classList.toggle('hidden');
            section.classList.toggle('collapsed');
            chevron.innerHTML = grid.classList.contains('hidden') ? '&#9660;' : '&#9650;';
        });
    });

    // --- Add Item modal ---
    const addItemBtn      = document.getElementById('addItemBtn');
    const itemModal       = document.getElementById('itemModal');
    const cancelItemModal = document.getElementById('cancelItemModal');
    const itemForm        = document.getElementById('itemForm');
    const itemImage       = document.getElementById('itemImage');
    const itemImagePreview = document.getElementById('itemImagePreview');

    addItemBtn.addEventListener('click', function () {
        itemForm.reset();
        itemImagePreview.innerHTML = '<span class="plus">+</span>';
        itemModal.classList.remove('hidden');
    });

    cancelItemModal.addEventListener('click', function (e) {
        e.preventDefault();
        itemModal.classList.add('hidden');
    });

    itemModal.addEventListener('click', function (e) {
        if (e.target === itemModal) itemModal.classList.add('hidden');
    });

    itemImage.addEventListener('change', function () {
        const file = itemImage.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            itemImagePreview.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="max-height:100%;max-width:100%;object-fit:contain;">';
        };
        reader.readAsDataURL(file);
    });

    itemForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const name        = document.getElementById('itemName').value.trim();
        const price       = document.getElementById('itemPrice').value.trim();
        const categoryName = document.getElementById('itemCategory').value;

        const section = document.querySelector('.menu-category[data-category="' + categoryName + '"]');

        if (section) {
            const grid = section.querySelector('.product-grid');
            const card = document.createElement('article');
            card.className = 'product-card';
            card.innerHTML =
                '<div class="product-img">&#127868;</div>' +
                '<p class="product-name">' + name + '</p>' +
                '<p class="product-price">' + price + '</p>';
            grid.appendChild(card);

            // Make sure the category is expanded so the new item is visible.
            if (grid.classList.contains('hidden')) {
                section.querySelector('.category-header').click();
            }
        }

        itemModal.classList.add('hidden');
        showAlert('Item saved successfully', 'success');
    });

    // --- Add New Category modal ---
    const addCategoryBtn      = document.getElementById('addCategoryBtn');
    const categoryModal       = document.getElementById('categoryModal');
    const cancelCategoryModal = document.getElementById('cancelCategoryModal');
    const categoryForm        = document.getElementById('categoryForm');
    const categoryList        = document.getElementById('categoryList');

    addCategoryBtn.addEventListener('click', function () {
        categoryForm.reset();
        categoryModal.classList.remove('hidden');
    });

    cancelCategoryModal.addEventListener('click', function (e) {
        e.preventDefault();
        categoryModal.classList.add('hidden');
    });

    categoryModal.addEventListener('click', function (e) {
        if (e.target === categoryModal) categoryModal.classList.add('hidden');
    });

    categoryForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const name = document.getElementById('categoryName').value.trim();
        if (!name) return;

        const section = document.createElement('section');
        section.className = 'menu-category';
        section.dataset.category = name;
        section.innerHTML =
            '<button type="button" class="category-header"><span>' + name + '</span><span class="chevron">&#9650;</span></button>' +
            '<div class="product-grid"></div>';
        categoryList.appendChild(section);

        section.querySelector('.category-header').addEventListener('click', function () {
            const grid = section.querySelector('.product-grid');
            const chevron = section.querySelector('.chevron');
            grid.classList.toggle('hidden');
            section.classList.toggle('collapsed');
            chevron.innerHTML = grid.classList.contains('hidden') ? '&#9660;' : '&#9650;';
        });

        // Also add it as an option in the Add Item category dropdown.
        const option = document.createElement('option');
        option.textContent = name;
        document.getElementById('itemCategory').appendChild(option);

        categoryModal.classList.add('hidden');
        showAlert('Category added', 'successfully');
    });
});
