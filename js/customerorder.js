// Customer ordering page: same menu / order screen as the staff page,
// without orders, status labels or admin tools. GCash payment only.
// State
let cart = [];
let currentOrderTotal = 0;
let currentSelection = {
    productId: null,
    name: '',
    img: '',
    sizeLabel: '',
    basePrice: 0,
    orderType: 'Dine In',
    isFood: false,
    sinkerName: 'No Sinkers',
    sinkerPrice: 0,
    extraSinkerName: null,
    extraSinkerPrice: 0
};

 // Database: products will be loaded from menu_inventory
let menuData = {};
let currentCategory = '';

// Escape text before inserting database values into HTML
function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[char]);
}

// Colour family of a category (cat-pizza, cat-shake ...). Same rules as category_theme() in landing_page.php.
function categoryTheme(category, name) {
    const text = String(category || '').toLowerCase() + ' ' + String(name || '').toLowerCase();
    if (text.includes('pizza')) return 'pizza';
    if (text.includes('shake')) return 'shake';
    if (text.includes('soda') || text.includes('fruit')) return 'soda';
    if (text.includes('mango')) return 'mango';
    if (/coffee|latte|macchiato|mocha/.test(text)) return 'coffee';
    if (text.includes('tea')) return 'milktea';
    return 'default';
}

// One friendly line for products that have no description yet.
function categoryBlurb(theme) {
    return ({
        pizza: 'Hot, cheesy and baked to order.',
        shake: 'Thick, creamy and blended fresh.',
        soda: 'Fizzy, fruity and ice cold.',
        mango: 'Sweet mango goodness, made to order.',
        coffee: 'Rich, smooth and freshly made.',
        milktea: 'Freshly brewed milk tea, your way.'
    })[theme] || 'Freshly made to order.';
}

// The cart is remembered for this tab (see window.djcCart in customer_bar.js).
// It is only saved once the menu has loaded and the saved cart has been put back,
// so an early save can never wipe it.
let cartPersistReady = false;

// Load menu products from get_menu.php
async function loadMenuProducts() {
    const productGrid = document.getElementById('productGrid');

    if (productGrid) {
        productGrid.setAttribute('aria-busy', 'true');
        productGrid.innerHTML = Array.from({ length: 8 }, () =>
            '<div class="product-card is-skeleton" aria-hidden="true"><div class="product-image-wrap"></div><div class="sk-line"></div><div class="sk-line short"></div></div>'
        ).join('');
    }

    try {
        const response = await fetch(
            'get_menu.php',
            { cache: 'no-store' }
        );

        if (!response.ok) {
            throw new Error('Failed to retrieve menu products.');
        }

        const products = await response.json();

        if (!Array.isArray(products)) {
            throw new Error(products.error || 'Invalid menu data received.');
        }

        menuData = {};

        products.forEach(product => {
            const category = product.category;

            if (!category) return;

            if (!menuData[category]) {
                menuData[category] = [];
            }

        menuData[category].push({
            id: Number(product.id),
            name: product.product_name,
            category: product.category,
            img: product.image
                ? '../uploads/products/' + encodeURIComponent(product.image)
                : '',
            quantity: Number(product.quantity) || 0,
            unit: product.unit || 'pcs',
            unitCost: Number(product.unit_cost) || 0,
            price: Number(product.selling_price) || 0,
            small: Number(product.small_price) || 0,
            med: Number(product.medium_price) || 0,
            lrg: Number(product.large_price) || 0,
            description: product.description || '',
            isFood: Number(product.is_food) === 1,

            // Availability is determined by the server's recipe stock check.
            availability: product.availability || 'Unavailable',

            // Active / inactive (admins can see inactive products).
            isActive: product.is_active === undefined || Number(product.is_active) === 1,

            // Which sizes have enough ingredients right now, e.g. {small: true, large: false}.
            sizeStock: product.size_stock || {},

            // Handle true, 1, and "1" returned by PHP.
            isOrderable:
            product.is_orderable === true ||
            Number(product.is_orderable) === 1 ||
            String(product.is_orderable).toLowerCase() === 'true'
        });
        });

        buildCategoryNav();

        // A category in the link (?category=...) wins, then the last one viewed.
        const categories = Object.keys(menuData);
        let wanted = new URLSearchParams(window.location.search).get('category');
        const saved = sessionStorage.getItem('lastMenuCategory');

        currentCategory = [wanted, saved, currentCategory].find(
            name => name && categories.includes(name)
        ) || categories[0] || '';

        showCategory(currentCategory);

        // "Order Again": put the earlier order back in the cart.
        // Otherwise put back the cart this tab had before (prices and stock are re-checked).
        if (reorderOrderId) {
            await applyReorder(reorderOrderId);
        } else {
            restoreSavedCart();
        }

        cartPersistReady = true;
        updateCartUI();

        // The Home page cards and the featured "Order Now" button link here with ?add=<product id>:
        // the product's customize window opens straight away.
        openProductFromLink();

    } catch (error) {
        console.error('Error loading menu products:', error);

        if (productGrid) {
            productGrid.innerHTML =
                '<p>Unable to load menu products. Please try again.</p>';
        }
    }
}



// "Order Again": the link is customerorder.php?reorder=<order id>. The earlier
// order goes back into the cart, rebuilt with TODAY's prices and availability.
const reorderOrderId = Number(
    new URLSearchParams(window.location.search).get('reorder')
) || 0;

// The order types and drinks offered in the product window. They are read
// from the buttons themselves so the prices are defined in one place only.
function readOptionButtons(selector) {
    const options = {};

    document.querySelectorAll(selector).forEach(button => {
        const name = button.dataset.name;

        // a sold-out sinker (see sinker_stock.js) is treated as no longer offered
        if (name && !button.classList.contains('sold-out')) {
            options[name.toLowerCase()] = {
                name: name,
                price: Number(button.dataset.price) || 0
            };
        }
    });

    return options;
}

// "Dine In + Tapioca Pearls + Extra Nata Jelly" -> the same choices the customer
// would make in the product window. Anything no longer offered is dropped.
function rebuildAddOns(savedAddOns, item) {
    const orderTypes = readOptionButtons('#orderTypeOptions .option-card');
    const sinkers = readOptionButtons('#sinkerOptions .option-card');
    const extras = readOptionButtons('#extraSinkerOptions .option-card');
    const isFood = !!(item && item.isFood);

    let orderType = '';
    let sinker = null;
    let extra = null;

    String(savedAddOns || '').split(' + ').forEach(part => {
        const text = part.trim().toLowerCase();

        if (!text) return;

        if (/^extra /.test(text)) {
            extra = extras[text.slice(6).trim()] || extra;
        } else if (orderTypes[text]) {
            orderType = orderTypes[text].name;
        } else if (sinkers[text]) {
            sinker = sinkers[text];
        }
    });

    const parts = [orderType || 'Dine In'];
    let extraPrice = 0;

    // Pizza has no sinkers; "Add a Sinker" needs a real sinker first.
    if (!isFood && sinker && sinker.name !== 'No Sinkers') {
        parts.push(sinker.name);

        if (extra) {
            parts.push('Extra ' + extra.name);
            extraPrice = extra.price;
        }
    }

    return { meta: parts.join(' + '), extraPrice: extraPrice };
}

// Find a product in the current menu.
function findMenuProduct(productId) {
    for (const category of Object.keys(menuData)) {
        const found = menuData[category].find(item => item.id === productId);

        if (found) return found;
    }

    return null;
}

// Turn one saved order line into a cart line, or say why it cannot be added.
function buildReorderLine(saved) {
    const label = saved.name.split(' (')[0].trim() || saved.name;
    const item = saved.product_id ? findMenuProduct(Number(saved.product_id)) : null;

    if (!item) {
        return { skipped: label + ' (no longer on the menu)' };
    }

    if (item.isOrderable !== true) {
        return { skipped: item.name + ' (sold out right now)' };
    }

    // The sizes the product is sold in today.
    const sizes = [
        { label: 'Small', price: item.small },
        { label: 'Medium', price: item.med },
        { label: 'Large', price: item.lrg }
    ].filter(size => size.price > 0);

    if (sizes.length === 0 && item.price > 0) {
        sizes.push({ label: 'Regular', price: item.price });
    }

    const wanted = String(saved.size_label || '').toLowerCase();

    // Orders saved before sizes were recorded have no size: use the only one.
    const size = sizes.find(option => option.label.toLowerCase() === wanted)
        || (wanted === '' && sizes.length === 1 ? sizes[0] : null);

    if (!size) {
        return { skipped: item.name + ' (' + (saved.size_label || 'that size') + ' is no longer offered)' };
    }

    if (item.sizeStock[size.label.toLowerCase()] === false) {
        return { skipped: item.name + ' (' + size.label + ' is sold out right now)' };
    }

    const addOns = rebuildAddOns(saved.add_ons, item);

    return {
        line: {
            productId: item.id,
            name: item.name,
            img: item.img,
            sizeLabel: size.label,
            sinkerMeta: addOns.meta,
            unitPrice: Number(size.price) + addOns.extraPrice,
            qty: Math.max(1, Number(saved.qty) || 1)
        },
        category: item.category
    };
}

// Fetch the earlier order and put what can still be ordered in the cart.
async function applyReorder(orderId) {
    let result;

    try {
        const response = await fetch(
            'reorder_data.php?order=' + encodeURIComponent(orderId),
            { credentials: 'same-origin', cache: 'no-store' }
        );

        result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'We could not load that order.');
        }
    } catch (error) {
        showReorderBanner({
            type: 'error',
            message: error.message || 'We could not load that order.',
            loginLink: !window.isLoggedIn || /log in/i.test(error.message || '')
        });
        cleanReorderUrl();
        return;
    }

    const skipped = [];
    let itemCount = 0;
    let firstCategory = '';

    (result.items || []).forEach(saved => {
        const built = buildReorderLine(saved);

        if (built.skipped) {
            skipped.push(built.skipped);
            return;
        }

        // Same grouping as the product window: one line per product + size + add-ons.
        const line = built.line;

        // The whole order holds at most MAX_CART_QTY items.
        const room = MAX_CART_QTY - cartTotal();

        if (room <= 0) {
            skipped.push(line.name + ' (your order is limited to ' + MAX_CART_QTY + ' items)');
            return;
        }

        if (line.qty > room) {
            line.qty = room;
        }

        // Count the quantity now: a merged line's quantity changes afterwards.
        itemCount += line.qty;

        const existing = cart.find(entry =>
            entry.productId === line.productId &&
            entry.sizeLabel === line.sizeLabel &&
            entry.sinkerMeta === line.sinkerMeta
        );

        if (existing) {
            existing.qty += line.qty;
        } else {
            cart.push(line);
        }

        firstCategory = firstCategory || built.category;
    });

    if (itemCount > 0) {
        updateCartUI();
        expandOrderPanel();

        // Show the menu category of the first product that was added.
        if (firstCategory && firstCategory !== currentCategory) {
            currentCategory = firstCategory;
            sessionStorage.setItem('lastMenuCategory', firstCategory);
            showCategory(firstCategory);
        }
    }

    showReorderBanner({
        type: itemCount === 0 ? 'error' : (skipped.length ? 'warning' : 'success'),
        orderNumber: result.order_number,
        itemCount: itemCount,
        skipped: skipped
    });

    cleanReorderUrl();
}

// Remove ?reorder=... from the address so a refresh does not repeat it.
function cleanReorderUrl() {
    const url = new URL(window.location.href);

    url.searchParams.delete('reorder');
    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
}

// Put back the cart saved by this tab. Each line is rebuilt with TODAY's price and
// stock (same helper "Order Again" uses), so nothing stale can be ordered.
function restoreSavedCart() {
    if (!window.djcCart || cart.length) return;

    const saved = window.djcCart.read();
    if (!saved.length) return;

    const lines = [];
    let dropped = 0;

    saved.forEach(entry => {
        const built = buildReorderLine({
            name: String(entry.name || ''),
            product_id: entry.productId,
            size_label: entry.sizeLabel,
            add_ons: entry.sinkerMeta,
            qty: entry.qty
        });

        if (built.line) {
            lines.push(built.line);
        } else {
            dropped++;
        }
    });

    cart = lines;

    if (dropped > 0) {
        showCartNotice(dropped === 1
            ? 'One item in your cart is no longer available and was removed.'
            : dropped + ' items in your cart are no longer available and were removed.');
    }
}

// ?add=<id> (from the Home page cards): switch to that product's category and open it.
function openProductFromLink() {
    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get('add')) || 0;

    if (!id) return;

    params.delete('add');
    const rest = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : ''));

    const item = findMenuProduct(id);
    if (!item) return;

    if (item.category !== currentCategory) {
        sessionStorage.setItem('lastMenuCategory', item.category);
        showCategory(item.category);
    }

    openModal(item);
}

// Build the category list in the sidebar from the products that exist.
function buildCategoryNav() {
    const nav = document.getElementById('categoryList');
    if (!nav) return;

    const preferred = ['Mango Series', 'Coffee Series', 'Milkshake Special', 'Pizza Series'];
    const names = Object.keys(menuData).sort((a, b) => {
        const ia = preferred.indexOf(a), ib = preferred.indexOf(b);
        return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib) || a.localeCompare(b);
    });

    nav.innerHTML = names.map(name => `
        <button type="button" class="menu-category-btn cat-${categoryTheme(name)}" data-category="${escapeHTML(name)}">${escapeHTML(name)}</button>`).join('');

    nav.querySelectorAll('.menu-category-btn').forEach(button => {
        button.addEventListener('click', () => {
            switchCategory(button, button.dataset.category);
        });
    });
}

function showCategory(name) {
    document.querySelectorAll('.menu-category-btn').forEach(button => {
        button.classList.toggle('active', button.dataset.category === name);
    });

    const titleEl = document.getElementById('sectionTitle');
    if (titleEl) titleEl.textContent = name || 'Menu';

    renderProducts(name);
}

    function renderProducts(category) {
    currentCategory = category;

    const productGrid = document.getElementById('productGrid');
    if (!productGrid) return;

    const items = menuData[category] || [];

    if (items.length === 0) {
        productGrid.removeAttribute('aria-busy');
        productGrid.innerHTML =
            '<p>No available products in this category yet.</p>';
        return;
    }

    productGrid.innerHTML = items.map((item, index) => {
        const isAvailable = item.isOrderable === true;

        // A product sold in 2 or more sizes shows its lowest price as "From".
        const sizePrices = [item.small, item.med, item.lrg].filter(price => price > 0);
        const hasSizes = sizePrices.length >= 2;
        const displayPrice = hasSizes ? Math.min(...sizePrices) : item.price;

        const theme = categoryTheme(item.category, item.name);
        const description = String(item.description || '').trim() || categoryBlurb(theme);
        const sizeLetters = [item.small > 0 ? 'S' : '', item.med > 0 ? 'M' : '', item.lrg > 0 ? 'L' : ''].filter(Boolean);
        const sizeHint = sizeLetters.length >= 2 ? sizeLetters.join(' \u00b7 ') : '';

        return `
            <div class="product-card cat-${theme} ${!isAvailable ? 'unavailable-product' : ''}"
                 data-product-index="${index}" data-product-id="${item.id}">

                <div class="product-image-wrap">
                    ${
                        item.img
                            ? `<img loading="lazy" decoding="async" src="${escapeHTML(item.img)}"
                                    alt="${escapeHTML(item.name)}"
                                    class="product-img"
                                    onerror="this.style.display='none'">`
                            : ''
                    }
                </div>

                <h3 class="product-name">
                    ${escapeHTML(item.name)}
                </h3>

                <p class="product-desc">${escapeHTML(description)}</p>

                <div class="product-foot">
                    <div class="product-pricebox">
                        <p class="product-price">
                            ${hasSizes ? 'From ' : ''}\u20b1${displayPrice.toFixed(2)}
                        </p>
                        ${sizeHint ? `<span class="product-sizes" title="Available sizes">${sizeHint}</span>` : ''}
                    </div>
                    ${isAvailable ? '<span class="product-add" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>' : ''}
                </div>

                ${isAvailable ? '' : '<p class="product-stock-status unavailable-text">Sold out</p>'}
            </div>
        `;
    }).join('');

    productGrid.removeAttribute('aria-busy');

    productGrid.querySelectorAll('.product-card').forEach((card, index) => {
        card.addEventListener('click', (event) => {
            openModal(items[index]);
        });
    });
}

// Category Switch
function switchCategory(selectedBtn, categoryTitle) {
    document.querySelectorAll('.menu-category-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    selectedBtn.classList.add('active');

    // Remember the selected category.
    sessionStorage.setItem('lastMenuCategory', categoryTitle);
    showCategory(categoryTitle);
}

// Modal Handlers
function openModal(item) {
    if (!item) return;


// Prevent ordering products with missing or insufficient ingredients.
if (item.isOrderable !== true) {
    alert(
        'Sorry, this item is sold out right now. Please pick something else.'
    );
    return;
}

    currentSelection = {
        productId: item.id,
        name: item.name,
        img: item.img,
        sizeLabel: '',
        basePrice: 0,
        sinkerName: 'No Sinkers',
        sinkerPrice: 0,
        extraSinkerName: null,
        extraSinkerPrice: 0
    };

    const sizeOptions = document.getElementById('sizeOptions');
    sizeOptions.innerHTML = '';

    const sizes = [
        { label: 'Small', price: item.small },
        { label: 'Medium', price: item.med },
        { label: 'Large', price: item.lrg }
    ].filter(size => size.price > 0);

    if (sizes.length === 0 && item.price > 0) {
        sizes.push({
            label: 'Regular',
            price: item.price
        });
    }

    if (sizes.length === 0) {
        alert('This product has no valid selling price.');
        return;
    }

    // A size is available only while its ingredients are in stock.
    sizes.forEach(size => {
        size.available = item.sizeStock[size.label.toLowerCase()] !== false;
    });

    // Start on the first size that can actually be made.
    const firstAvailable = Math.max(0, sizes.findIndex(size => size.available));

    sizes.forEach((size, index) => {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'size-btn' + (index === firstAvailable ? ' active' : '');
        button.textContent =
            `${size.label} - ₱${size.price.toFixed(2)}` +
            (size.available ? '' : ' (Unavailable)');
        button.disabled = !size.available;

        button.addEventListener('click', () => {
            selectSize(button, size.label, size.price);
        });

        sizeOptions.appendChild(button);
    });

    currentSelection.sizeLabel = sizes[firstAvailable].label;
    currentSelection.basePrice = sizes[firstAvailable].price;

    // Pizza (food categories) only asks for the order type; every other product
    // can also be customised with a size, a sinker and an extra sinker.
    const isFood = item.isFood === true;
    currentSelection.isFood = isFood;
    currentSelection.orderType = 'Dine In';

    document.querySelectorAll('#orderTypeOptions .option-card')
        .forEach((button, index) => {
            button.classList.toggle('active', index === 0);
        });

    document.querySelectorAll('#sinkerOptions .option-card')
        .forEach((button, index) => {
            button.classList.toggle('active', index === 0);
        });

    currentSelection.sinkerName = 'No Sinkers';
    currentSelection.sinkerPrice = 0;
    currentSelection.extraSinkerName = null;
    currentSelection.extraSinkerPrice = 0;

    updateExtraSinkersAvailability(false);

    // A pizza sold in one size has nothing to pick; several sizes still need the picker.
    document.getElementById('sizeSection').hidden = isFood && sizes.length === 1;
    document.getElementById('sinkerSection').hidden = isFood;
    document.getElementById('extraSinkerSection').hidden = isFood;

    document.getElementById('customizeModal').classList.add('show');
}
    function selectSize(button, label, price) {
    document.querySelectorAll('#sizeOptions .size-btn')
        .forEach(btn => btn.classList.remove('active'));

    button.classList.add('active');

    currentSelection.sizeLabel = label;
    currentSelection.basePrice = Number(price) || 0;
}

function selectOrderType(button, name) {
    document.querySelectorAll('#orderTypeOptions .option-card')
        .forEach(btn => btn.classList.remove('active'));

    button.classList.add('active');
    currentSelection.orderType = name;
}

function selectSinker(button, name, price) {
    document.querySelectorAll('#sinkerOptions .option-card')
        .forEach(btn => btn.classList.remove('active'));

    button.classList.add('active');

    currentSelection.sinkerName = name;
    currentSelection.sinkerPrice = Number(price) || 0;

    // "Add a Sinker" is only possible after a sinker was chosen; it resets
    // whenever the chosen sinker changes.
    currentSelection.extraSinkerName = null;
    currentSelection.extraSinkerPrice = 0;

    document.querySelectorAll('#extraSinkerOptions .option-card')
        .forEach(btn => btn.classList.remove('active'));

    updateExtraSinkersAvailability(name !== 'No Sinkers');
}

function updateExtraSinkersAvailability(enabled) {
    document.querySelectorAll('#extraSinkerOptions .option-card')
        .forEach(button => {
            button.classList.toggle('disabled', !enabled);
            button.disabled = !enabled;

            if (!enabled) {
                button.classList.remove('active');
            }
        });
}

function toggleExtraSinker(button, name, price) {
    if (button.disabled) return;

    const isSelected = button.classList.contains('active');

    // Allow one extra sinker at a time.
    document.querySelectorAll('#extraSinkerOptions .option-card')
        .forEach(btn => btn.classList.remove('active'));

    if (isSelected) {
        currentSelection.extraSinkerName = null;
        currentSelection.extraSinkerPrice = 0;
        return;
    }

    button.classList.add('active');

    currentSelection.extraSinkerName = name;
    currentSelection.extraSinkerPrice = Number(price) || 0;
}

function closeModal() {
    document.getElementById('customizeModal').classList.remove('show');
}

function confirmOrder() {
    if (!currentSelection.productId || !currentSelection.sizeLabel) {
        alert('Please select a valid product and size.');
        return;
    }

    const itemTotal =
        Number(currentSelection.basePrice) +
        Number(currentSelection.extraSinkerPrice || 0);

    // "Dine In + Tapioca Pearls + Extra Nata Jelly". Pizza only has the order type.
    const metaParts = [currentSelection.orderType || 'Dine In'];

    if (!currentSelection.isFood) {
        if (currentSelection.sinkerName && currentSelection.sinkerName !== 'No Sinkers') {
            metaParts.push(currentSelection.sinkerName);

            if (currentSelection.extraSinkerName) {
                metaParts.push(`Extra ${currentSelection.extraSinkerName}`);
            }
        }
    }

    const sinkerMeta = metaParts.join(' + ');

    const existingIndex = cart.findIndex(item =>
        item.productId === currentSelection.productId &&
        item.sizeLabel === currentSelection.sizeLabel &&
        item.sinkerMeta === sinkerMeta
    );

    if (cartTotal() >= MAX_CART_QTY) {
        closeModal();
        showCartNotice(`Your order is limited to ${MAX_CART_QTY} items. Remove something to add more.`);
        return;
    }

    if (existingIndex > -1) {
        cart[existingIndex].qty += 1;
    } else {
        cart.push({
            productId: currentSelection.productId,
            name: currentSelection.name,
            img: currentSelection.img,
            sizeLabel: currentSelection.sizeLabel,
            sinkerMeta: sinkerMeta,
            unitPrice: itemTotal,
            qty: 1
        });
    }

    flyToCart(currentSelection.productId);
    closeModal();
    updateCartUI();
    expandOrderPanel();
}

// A copy of the product photo flies to the cart, so it is clear the item was added.
function flyToCart(productId) {
    try {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const card = document.querySelector('.product-card[data-product-id="' + productId + '"]');
        const photo = card && card.querySelector('.product-img');
        const bar = document.getElementById('mobileCartBar');
        const target = (bar && !bar.hidden && getComputedStyle(bar).display !== 'none')
            ? bar
            : document.getElementById('cbarCart');

        if (!card || !target) return;

        const from = (photo && photo.offsetWidth ? photo : card).getBoundingClientRect();
        const to = target.getBoundingClientRect();
        if (!from.width || !to.width) return;

        const ghost = photo && photo.offsetWidth ? photo.cloneNode(false) : document.createElement('div');
        ghost.removeAttribute('id');
        ghost.setAttribute('aria-hidden', 'true');
        ghost.removeAttribute('loading');
        Object.assign(ghost.style, {
            position: 'fixed', left: from.left + 'px', top: from.top + 'px',
            width: from.width + 'px', height: from.height + 'px', margin: '0',
            zIndex: '1500', pointerEvents: 'none', objectFit: 'contain',
            borderRadius: '14px', background: photo ? 'none' : '#c98a4b'
        });
        document.body.appendChild(ghost);

        const dx = (to.left + to.width / 2) - (from.left + from.width / 2);
        const dy = (to.top + to.height / 2) - (from.top + from.height / 2);

        const move = ghost.animate([
            { transform: 'translate(0, 0) scale(1)', opacity: 1 },
            { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(.1)', opacity: .35 }
        ], { duration: 650, easing: 'cubic-bezier(.55, 0, .7, .2)' });

        move.onfinish = move.oncancel = () => {
            ghost.remove();
            if (window.djcCart) window.djcCart.bump();
        };
    } catch (error) { /* the animation is only decoration */ }
}

// Cart Management
function expandOrderPanel() {
    const appLayout = document.getElementById('appLayout');
    const toggleBtn = document.getElementById('togglePanelBtn');

    if (appLayout && appLayout.classList.contains('collapsed')) {
        appLayout.classList.remove('collapsed');
        if (toggleBtn) toggleBtn.innerHTML = '&rsaquo;';
    }
}

function updateCartUI() {
    const cartList = document.getElementById('cartItemsList');
    const collapsedThumbnails = document.getElementById('collapsedThumbnails');

    cartList.innerHTML = '';
    collapsedThumbnails.innerHTML = '';

    let totalItemsCount = 0;
    let totalPriceAmount = 0;

    cart.forEach((item, index) => {
        const itemTotal = item.unitPrice * item.qty;
        totalItemsCount += item.qty;
        totalPriceAmount += itemTotal;

        cartList.innerHTML += `
      <div class="cart-item">
        ${item.img ? `
            <img loading="lazy" decoding="async"
                src="${escapeHTML(item.img)}"
                alt="${escapeHTML(item.name)}"
                class="cart-item-img" >` : ''}
        <div class="cart-item-details">
        <span class="cart-item-title">
            ${escapeHTML(item.name)} </span>
        <span class="cart-item-meta">
            ${escapeHTML(item.sizeLabel)}
            ${item.sinkerMeta
                ? ' • ' + escapeHTML(item.sinkerMeta) : ''} </span>
          <span class="cart-item-price">₱ ${itemTotal.toFixed(2)}</span>
          ${stockIndicatorHTML(item, index)}
        </div>
        <div class="cart-item-actions">
          <div class="cart-qty-control">
            <button type="button" class="qty-btn" onclick="changeQty(${index}, -1)" aria-label="Decrease quantity of ${escapeHTML(item.name)}">-</button>
            <input type="number" class="qty-input" value="${item.qty}" min="1" max="${MAX_CART_QTY - (cartTotal() - item.qty)}" step="1" inputmode="numeric"
                aria-label="Quantity of ${escapeHTML(item.name)}"
                onchange="setQty(${index}, this.value)"
                onkeydown="if (event.key === 'Enter') { this.blur(); }"
                onfocus="this.select()">
            <button type="button" class="qty-btn" onclick="changeQty(${index}, 1)" aria-label="Increase quantity of ${escapeHTML(item.name)}">+</button>
          </div>
          <button type="button" class="cart-remove-btn" onclick="removeFromCart(${index})" aria-label="Remove ${escapeHTML(item.name)} from cart">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>
            Remove
          </button>
        </div>
      </div>
    `;


    collapsedThumbnails.innerHTML += `
    <div class="collapsed-thumb-item"
        title="${escapeHTML(item.name)}" onclick="expandOrderPanel()">
        ${item.img ? `
        <img loading="lazy" decoding="async"
            src="${escapeHTML(item.img)}"
            alt="${escapeHTML(item.name)}"
            class="collapsed-thumb-img"
        >  ` : ''}
        <span class="collapsed-thumb-qty">${item.qty}</span>
    </div>
    `;

    });

    document.getElementById('itemCount').textContent = `${totalItemsCount} Item${totalItemsCount !== 1 ? 's' : ''}`;
    document.getElementById('totalPrice').textContent = `₱ ${totalPriceAmount.toFixed(2)}`;
    document.getElementById('collapsedBadge').textContent = totalItemsCount;

    // Remember the cart for this tab and update the header's cart count.
    if (cartPersistReady && window.djcCart) {
        window.djcCart.write(cart.map(line => ({
            productId: line.productId,
            name: line.name,
            sizeLabel: line.sizeLabel,
            sinkerMeta: line.sinkerMeta,
            qty: line.qty
        })));
    }

    // Phones: the "View cart" bar at the bottom.
    const cartBar = document.getElementById('mobileCartBar');
    if (cartBar) {
        cartBar.hidden = totalItemsCount === 0;
        document.getElementById('mcbCount').textContent = totalItemsCount;
        document.getElementById('mcbTotal').textContent = `\u20b1 ${totalPriceAmount.toFixed(2)}`;
        if (totalItemsCount === 0) closeCartSheet();
    }

    const limitHint = document.getElementById('cartLimitHint');
    if (limitHint) {
        limitHint.textContent = `${totalItemsCount} of ${MAX_CART_QTY} items`;
        limitHint.classList.toggle('full', totalItemsCount >= MAX_CART_QTY);
    }

    const checkoutButton = document.getElementById('checkoutBtn');
    if (checkoutButton) {
        const blocked = cart.some(cartLineProblem);
        checkoutButton.classList.toggle('stock-blocked', blocked);
        checkoutButton.title = blocked ? 'Some items in your cart are more than we can make right now.' : '';
    }

    if (!skipStockCheck) scheduleStockCheck();
}

// STOCK INDICATOR: asks the server how many of each cart line the inventory can supply.
// item.stockMax: undefined = not checked yet, null = cannot be made, number = most we can make.
let skipStockCheck = false;
let stockTimer = null;
let stockRequestId = 0;

function cartLineProblem(item) {
    if (item.stockMax === null) return true;
    return typeof item.stockMax === 'number' && item.qty > item.stockMax;
}

function stockIndicatorHTML(item, index) {
    if (item.stockMax === undefined) return '';

    if (item.stockMax === null || item.stockMax <= 0) {
        return `<span class="cart-stock cart-stock-bad">Not enough stock for this item right now.</span>`;
    }

    if (item.qty > item.stockMax) {
        return `<span class="cart-stock cart-stock-bad">Only ${item.stockMax} available. You ordered ${item.qty}.
            <button type="button" class="cart-stock-fix" onclick="setQty(${index}, ${item.stockMax})">Set to ${item.stockMax}</button></span>`;
    }

    if (item.stockMax - item.qty <= 5) {
        return `<span class="cart-stock cart-stock-warn">Only ${item.stockMax} available in total.</span>`;
    }

    return `<span class="cart-stock cart-stock-ok">&#10003; Enough stock</span>`;
}

function scheduleStockCheck() {
    clearTimeout(stockTimer);

    if (cart.length === 0) return;

    stockTimer = setTimeout(checkCartStock, 350);
}

async function checkCartStock() {
    const requestId = ++stockRequestId;
    const sent = cart.map(item => ({
        productId: item.productId, sizeLabel: item.sizeLabel, qty: item.qty, sinkerMeta: item.sinkerMeta || ''
    }));

    try {
        const response = await fetch('check_cart_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: sent })
        });
        const result = await response.json();

        // The cart changed while we were asking; a newer check is on its way.
        if (requestId !== stockRequestId || !result.ok || !Array.isArray(result.lines)) return;
        if (result.lines.length !== cart.length) return;

        cart.forEach((item, i) => { item.stockMax = result.lines[i].max; });

        skipStockCheck = true;
        updateCartUI();
        skipStockCheck = false;
    } catch (error) {
        skipStockCheck = false; // the indicator is a hint; the server checks again at checkout
    }
}

// Most items (all products together) a single order can hold.
const MAX_CART_QTY = 50;

function cartTotal() {
    return cart.reduce((sum, item) => sum + item.qty, 0);
}

// A short message that fades away (no alert box).
function showCartNotice(message) {
    let notice = document.getElementById('cartNotice');

    if (!notice) {
        notice = document.createElement('div');
        notice.id = 'cartNotice';
        notice.className = 'cart-notice';
        notice.setAttribute('role', 'status');
        document.body.appendChild(notice);
    }

    notice.textContent = message;
    notice.classList.add('show');
    clearTimeout(showCartNotice.timer);
    showCartNotice.timer = setTimeout(() => notice.classList.remove('show'), 3500);
}

// Set a line's quantity: at least 1, and the whole cart never goes over MAX_CART_QTY.
function applyQty(index, wanted) {
    const room = MAX_CART_QTY - (cartTotal() - cart[index].qty);
    const next = Math.min(room, Math.max(1, wanted));

    if (wanted > room) {
        showCartNotice(`Your order is limited to ${MAX_CART_QTY} items in total.`);
    }

    cart[index].qty = next;
    updateCartUI();
}

function changeQty(index, delta) {
    if (!cart[index]) return;

    // The "-" button never removes the item; use "Remove" for that.
    applyQty(index, cart[index].qty + delta);
}

// The number in the cart can be typed in. Anything that is not a whole number
// of 1 or more goes back to what it was.
function setQty(index, value) {
    if (!cart[index]) return;

    const typed = Math.floor(Number(value));

    if (Number.isFinite(typed) && typed >= 1) {
        applyQty(index, typed);
        return;
    }

    updateCartUI();
}

function removeFromCart(index) {
    if (!cart[index]) return;

    cart.splice(index, 1);
    updateCartUI();
}

// CHECKOUT & PAYMENT FLOW (GCash only)

// Step 1: Show the order summary. Customers must be logged in to order.
function handleCheckout() {
    // Outside store hours the menu can be browsed, but no order can be placed.
    // (The server checks this too; this just gives the answer straight away.)
    if (window.storeIsOpen === false) {
        alert(window.storeClosedMessage || "We're closed right now, so we can't take new orders.");
        return;
    }

    if (cart.length === 0) {
        alert('Please add items to your order first.');
        return;
    }

    const problemLine = cart.find(cartLineProblem);
    if (problemLine) {
        alert(problemLine.stockMax > 0
            ? `Only ${problemLine.stockMax} of ${problemLine.name} can be made right now. Please lower the quantity in your cart.`
            : `${problemLine.name} cannot be made right now. Please remove it from your cart.`);
        return;
    }

    if (!window.isLoggedIn) {
        window.location.href = 'landing_page.php?auth=login&next=customerorder.php';
        return;
    }

    const confirmList = document.getElementById('confirmationItemsList');
    confirmList.innerHTML = '';

    currentOrderTotal = cart.reduce(
        (sum, item) => sum + (item.unitPrice * item.qty), 0
    );

    cart.forEach(item => {
        const itemTotal = item.unitPrice * item.qty;

        confirmList.innerHTML += `
            <div class="confirm-item-row">
                <span>${item.qty}</span>
                <div class="confirm-item-details">
                    <span>${escapeHTML(item.name)}</span>
                    ${item.sizeLabel
                        ? `<span class="confirm-item-sub">${escapeHTML(item.sizeLabel)}</span>`
                        : ''}
                    ${item.sinkerMeta
                        ? `<span class="confirm-item-sub">Add: ${escapeHTML(item.sinkerMeta)}</span>`
                        : ''}
                </div>
                <span class="text-right">₱ ${itemTotal.toFixed(2)}</span>
            </div>
        `;
    });

    document.getElementById('confirmTotalAmount').textContent =
        `₱ ${currentOrderTotal.toFixed(2)}`;

    document.getElementById('confirmationModal').classList.add('show');
}

function closeConfirmationModal() {
    document.getElementById('confirmationModal').classList.remove('show');
}

// Final "are you sure?" so the customer can still change their mind.
function askPlaceOrder() {
    document.getElementById('placeOrderSure').classList.add('show');
}

function cancelPlaceOrder() {
    document.getElementById('placeOrderSure').classList.remove('show');
}

function confirmPlaceOrder() {
    cancelPlaceOrder();
    completeTransaction();
}

// Step 2: Save the order and go to the GCash checkout.
// The order is only confirmed after the payment is verified by the server.
async function completeTransaction() {
    if (cart.length === 0 || currentOrderTotal <= 0) {
        alert('Your order is empty. Please add items first.');
        return;
    }

    const button = document.getElementById('confirmGcashBtn');
    button.disabled = true;
    button.textContent = 'Processing...';

    try {
        const response = await fetch('save_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                payment_method: 'GCash',
                total_amount: currentOrderTotal,
                items: cart
            })
        });

        const result = await response.json();

        if (!response.ok || result.status !== 'success') {
            const failure = new Error(result.message || 'Unable to save the order.');

            // Set by save_order.php when the store is outside its opening hours.
            failure.storeClosed = result.store_closed === true;

            throw failure;
        }

        // Manual GCash: PayMongo is not available, so the customer scans the store's
        // GCash QR code and uploads the receipt (js/gcash_manual.js).
        if (result.manual_gcash) {
            closeConfirmationModal();
            cart = [];
            updateCartUI();

            const goToOrders = () => { window.location.href = 'my_orders.php'; };

            window.GcashManualPay.open({
                orderId: result.order_id,
                orderNumber: result.order_number,
                total: result.total_amount,
                secondsLeft: (result.payment_minutes || 15) * 60,
                cancelSecondsLeft: ((result.payment_minutes || 15) + (result.late_payment_minutes || 5)) * 60,
                intro: true,
                // Closed without sending the receipt: the order is saved, and it
                // can be finished from My Orders until the time runs out.
                onClose: goToOrders,
                onFinish: goToOrders
            });
            return;
        }

        if (!result.checkout_url) {
            throw new Error('The GCash checkout link was not returned. Please try again.');
        }

        // The order is saved now (it waits in My Orders), so the cart is emptied before
        // leaving for PayMongo. Otherwise pressing Back would show the same items in the
        // cart again and the customer could order them a second time.
        cart = [];
        updateCartUI();
        closeConfirmationModal();

        // Also wipe the saved copy directly, so it is gone even if the screen update above
        // did not get to save it.
        if (window.djcCart) window.djcCart.write([]);
        try { window.sessionStorage.removeItem('djcCart'); } catch (e) { /* storage blocked */ }

        // Remember this order: if the customer comes Back from PayMongo without paying,
        // the menu page cancels it (see cancelAbandonedCheckout).
        try { window.sessionStorage.setItem('djcPayOrder', String(result.order_id)); } catch (e) { /* storage blocked */ }

        window.location.href = result.checkout_url;

    } catch (error) {
        console.error('Error submitting order:', error);
        alert('Unable to complete the order: ' + error.message);
        button.disabled = false;
        button.textContent = 'Pay with GCash';

        // The store closed while the customer was checking out: leave the
        // payment window and update the banner and Order button right away.
        if (error.storeClosed) {
            closeConfirmationModal();
        }

        if (window.refreshStoreStatus) {
            window.refreshStoreStatus();
        }
    }
}

// The customer pressed Back on the PayMongo checkout without paying: cancel that order.
// (Paying or the checkout's Cancel button end on gcash_return.php, which clears the
// reminder, so a paid order is never cancelled here. Only a Back/Forward visit counts.)
function cancelAbandonedCheckout(fromCache) {
    let orderId = null;

    try {
        orderId = window.sessionStorage.getItem('djcPayOrder');
        window.sessionStorage.removeItem('djcPayOrder');
    } catch (e) { return; }

    if (!orderId) return;

    const nav = window.performance && performance.getEntriesByType
        ? performance.getEntriesByType('navigation')[0]
        : null;

    if (!fromCache && !(nav && nav.type === 'back_forward')) return;

    fetch('cancel_pending_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: Number(orderId) })
    })
        .then(response => response.json())
        .then(result => {
            if (result && result.cancelled) {
                showCartNotice('Your order was cancelled because you left the payment page. You have not been charged.');
            }
        })
        .catch(() => { /* the order simply stays in My Orders until its time runs out */ });
}

// Coming back from PayMongo with the Back button: the browser may show this page exactly as
// it was left ("Processing..." button, open payment window). Put it back in order.
window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;

    const button = document.getElementById('confirmGcashBtn');

    if (button) {
        button.disabled = false;
        button.textContent = 'Pay with GCash';
    }

    closeConfirmationModal();
    cancelPlaceOrder();
    cancelAbandonedCheckout(true);

    // The saved cart was emptied when the order was placed: show an empty cart.
    if (window.djcCart && !window.djcCart.read().length && cart.length) {
        cart = [];
        updateCartUI();
    }
});

// ---- Phone layout: the cart is a sheet that slides up from the bottom ----
function isPhoneLayout() {
    return window.matchMedia('(max-width: 768px)').matches;
}

function openCartSheet() {
    const layout = document.getElementById('appLayout');
    const backdrop = document.getElementById('sheetBackdrop');
    const bar = document.getElementById('mobileCartBar');
    if (!layout) return;

    layout.classList.add('cart-open');
    document.body.classList.add('sheet-lock');
    if (backdrop) backdrop.hidden = false;
    if (bar) bar.setAttribute('aria-expanded', 'true');

    const close = document.getElementById('sheetClose');
    if (close) close.focus({ preventScroll: true });
}

function closeCartSheet(returnFocus) {
    const layout = document.getElementById('appLayout');
    const backdrop = document.getElementById('sheetBackdrop');
    const bar = document.getElementById('mobileCartBar');
    if (!layout || !layout.classList.contains('cart-open')) return;

    layout.classList.remove('cart-open');
    document.body.classList.remove('sheet-lock');
    if (backdrop) backdrop.hidden = true;
    if (bar) {
        bar.setAttribute('aria-expanded', 'false');
        if (returnFocus && !bar.hidden) bar.focus({ preventScroll: true });
    }
}

// Initialization
document.addEventListener('DOMContentLoaded', () => {
    cancelAbandonedCheckout(false);

    loadMenuProducts();

    const toggleBtn = document.getElementById('togglePanelBtn');
    const collapsedCartView = document.getElementById('collapsedCartView');
    const appLayout = document.getElementById('appLayout');
    const checkoutBtn = document.getElementById('checkoutBtn');

    if (toggleBtn && appLayout) {
        toggleBtn.addEventListener('click', () => {
            appLayout.classList.toggle('collapsed');
            toggleBtn.innerHTML = appLayout.classList.contains('collapsed')
                ? '&lsaquo;'
                : '&rsaquo;';
        });
    }

    if (collapsedCartView) {
        collapsedCartView.addEventListener('click', () => expandOrderPanel());
    }

    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', handleCheckout);
    }

    // Header cart icon, the phone "View cart" bar and the sheet that holds the cart on phones.
    const headerCart = document.getElementById('cbarCart');
    if (headerCart) {
        headerCart.addEventListener('click', (event) => {
            event.preventDefault();
            if (isPhoneLayout()) {
                openCartSheet();
            } else {
                expandOrderPanel();
                const panel = document.getElementById('orderSidebar');
                if (panel) panel.classList.remove('flash-cart');
                if (panel) { void panel.offsetWidth; panel.classList.add('flash-cart'); }
            }
        });
    }

    const mobileBar = document.getElementById('mobileCartBar');
    if (mobileBar) mobileBar.addEventListener('click', openCartSheet);

    const sheetClose = document.getElementById('sheetClose');
    if (sheetClose) sheetClose.addEventListener('click', () => closeCartSheet(true));

    const sheetBackdrop = document.getElementById('sheetBackdrop');
    if (sheetBackdrop) sheetBackdrop.addEventListener('click', () => closeCartSheet(true));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && appLayout && appLayout.classList.contains('cart-open')
            && !document.querySelector('.modal-overlay.show')) {
            closeCartSheet(true);
        }
    });

    window.addEventListener('resize', () => { if (!isPhoneLayout()) closeCartSheet(); });
});

// Banner above the products after "Order Again".
function showReorderBanner(info) {
    const section = document.getElementById('menuSection');
    const old = document.getElementById('reorderBanner');

    if (!section) return;
    if (old) old.remove();

    const banner = document.createElement('div');
    banner.id = 'reorderBanner';
    banner.className = 'reorder-banner is-' + info.type;
    banner.setAttribute('role', 'status');

    const text = document.createElement('div');
    text.className = 'reorder-names';

    const title = document.createElement('strong');

    if (info.type === 'error' && info.message) {
        title.textContent = info.message;
        text.appendChild(title);

        if (info.loginLink) {
            const link = document.createElement('a');
            link.href = 'landing_page.php?auth=login&next=my_orders.php';
            link.textContent = ' Log in';
            text.appendChild(link);
        }
    } else if (info.itemCount === 0) {
        title.textContent = 'Nothing from Order #' + info.orderNumber + ' could be added to your cart.';
        text.appendChild(title);
    } else {
        title.textContent = 'Order again: ';
        text.appendChild(title);
        text.appendChild(document.createTextNode(
            info.itemCount + ' item' + (info.itemCount !== 1 ? 's' : '') +
            ' from Order #' + info.orderNumber +
            ' ' + (info.itemCount !== 1 ? 'are' : 'is') +
            ' in your cart. Review it, then check out.'
        ));
    }

    if (info.skipped && info.skipped.length) {
        const skipped = document.createElement('div');
        skipped.className = 'reorder-skipped';
        skipped.textContent = 'Not added: ' + info.skipped.join('; ') + '.';
        text.appendChild(skipped);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '&times;';
    close.addEventListener('click', () => banner.remove());

    banner.append(text, close);
    section.insertBefore(banner, document.getElementById('productGrid'));
}
