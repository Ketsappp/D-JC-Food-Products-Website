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
// The category buttons come from the database (admins add more with "Add Category"),
// so the first button is the default and a remembered category must still exist.
function menuCategoryNames() {
    return Array.from(document.querySelectorAll('.menu-category-btn'))
        .map(button => button.textContent.trim());
}

function startCategory() {
    const names = menuCategoryNames();
    const saved = sessionStorage.getItem('lastMenuCategory');

    if (saved && names.includes(saved)) return saved;

    return names[0] || 'Mango Series';
}

let currentCategory = startCategory();

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

// Load menu products from get_menu.php
async function loadMenuProducts() {
    const productGrid = document.getElementById('productGrid');

    if (productGrid) {
        productGrid.innerHTML = '<p>Loading menu products...</p>';
    }

    try {
        const response = await fetch(
            window.isAdmin ? 'get_menu.php?include_inactive=1' : 'get_menu.php',
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

        currentCategory = startCategory();
        
        renderProducts(currentCategory);

        const titleEl = document.getElementById('sectionTitle');
        if (titleEl) {
            titleEl.textContent = currentCategory;
        }

        document.querySelectorAll('.menu-category-btn').forEach(button => {
            const categoryName = button.textContent.trim();

            button.classList.toggle(
                'active',
                categoryName === currentCategory
            );
        });

    } catch (error) {
        console.error('Error loading menu products:', error);

        if (productGrid) {
            productGrid.innerHTML =
                '<p>Unable to load menu products. Please try again.</p>';
        }
    }
}


 // Update the active orders notification badge.
async function updateOrdersNotificationBadge() {
    const badge = document.getElementById('ordersNotificationBadge');

    if (!badge) return;

    try {
        const response = await fetch('get_active_order_count.php', {
            cache: 'no-store'
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error('Unable to retrieve active order count.');
        }

        const count = Number(result.count) || 0;

        badge.textContent = count;
        badge.hidden = count === 0;

    } catch (error) {
        console.error('Error updating orders notification:', error);
    }
}

// Order statuses: "value" is what is stored in the database, "label" is how
// the status is shown, and "buttonLabel" is the text of the card button.
const ORDER_STATUSES = [
    { value: 'Pending', label: 'Pending', buttonLabel: 'Pending' },
    { value: 'Working', label: 'Preparing', buttonLabel: 'Preparing' },
    { value: 'Ready for Taking', label: 'Ready to Serve', buttonLabel: 'Ready to Serve' },
    { value: 'Done', label: 'Completed', buttonLabel: 'Complete' }
];

// Values that can be used as a filter ('' shows every order that is not completed).
const ORDER_FILTERS = ['Pending', 'Working', 'Ready for Taking', 'Done'];

// How often the orders list refreshes by itself (milliseconds).
const ORDERS_REFRESH_MS = 10000;

let allOrders = [];
let ordersSignature = '';
let ordersBusy = false;

let activeOrderFilter = sessionStorage.getItem('activeOrderFilter') || '';

if (!ORDER_FILTERS.includes(activeOrderFilter)) {
    activeOrderFilter = '';
}

function orderStatusLabel(value) {
    const status = ORDER_STATUSES.find(item => item.value === value);

    return status ? status.label : value;
}

// Highlight the current tab / filter and hide the category list on Orders.
function syncOrdersNav() {
    const ordersSection = document.getElementById('ordersSection');
    const inOrders = !!ordersSection && !ordersSection.hidden;

    const menuNavBtn = document.getElementById('menuNavBtn');
    const ordersNavBtn = document.getElementById('ordersNavBtn');
    const appLayout = document.getElementById('appLayout');

    if (menuNavBtn) {
        menuNavBtn.classList.toggle('active', !inOrders);
    }

    if (ordersNavBtn) {
        ordersNavBtn.classList.toggle('active', inOrders);
    }

    document.querySelectorAll('.orders-filter-btn').forEach(button => {
        const isActive = button.dataset.filter === activeOrderFilter;

        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', String(isActive));
    });

    // The menu categories only make sense on the Menu tab.
    if (appLayout) {
        appLayout.classList.toggle('orders-view', inOrders);
    }
}

function setOrderFilter(filter) {
    activeOrderFilter = ORDER_FILTERS.includes(filter) ? filter : '';
    sessionStorage.setItem('activeOrderFilter', activeOrderFilter);

    const title = document.getElementById('ordersTitle');

    if (title) {
        if (!activeOrderFilter) {
            title.textContent = 'Orders';
        } else if (activeOrderFilter === 'Done') {
            title.textContent = 'Completed Orders (Today)';
        } else {
            title.textContent = orderStatusLabel(activeOrderFilter) + ' Orders';
        }
    }
}

// Orders waiting to be completed; "All" shows these.
function isActiveOrder(order) {
    return (order.status || 'Pending') !== 'Done';
}

// Show how many orders are in each stage on the filter labels.
function updateOrderCounts() {
    const counts = { '': allOrders.filter(isActiveOrder).length };

    ORDER_FILTERS.forEach(value => {
        counts[value] = allOrders.filter(
            order => (order.status || 'Pending') === value
        ).length;
    });

    document.querySelectorAll('.orders-filter-btn').forEach(button => {
        const count = button.querySelector('.filter-count');

        if (count) {
            count.textContent = counts[button.dataset.filter] ?? 0;
        }
    });
}

function orderCardHtml(order) {
    const currentStatus = order.status || 'Pending';

    // Steps must be followed in order: only the NEXT step can be chosen.
    // Earlier steps are locked, and later steps stay locked until their turn.
    const currentIndex = ORDER_STATUSES.findIndex(item => item.value === currentStatus);

    return `
        <div class="order-card">
            <div class="order-card-header">
                <h3>Order #${escapeHTML(order.id)}</h3>
                <span class="order-status">
                    ${escapeHTML(orderStatusLabel(currentStatus))}
                </span>
            </div>

            <div class="order-status-controls">
                ${ORDER_STATUSES.map((status, index) => {
                    const locked = currentIndex >= 0
                        && index !== currentIndex
                        && index !== currentIndex + 1;
                    const lockedTitle = index < currentIndex
                        ? 'This order has already moved past this step'
                        : 'Complete the previous step first';
                    return `
                    <button
                        type="button"
                        class="order-status-btn ${currentStatus === status.value ? 'active' : ''}${locked ? ' is-locked' : ''}"
                        data-order-id="${Number(order.id)}"
                        data-status="${status.value}"
                        ${locked ? `disabled title="${lockedTitle}"` : ''}>
                        ${status.buttonLabel}
                    </button>
                `;}).join('')}
            </div>

            <div class="order-card-details">
                <p><strong>Customer:</strong>
                    ${escapeHTML(order.customer_name || 'N/A')}
                </p>

                <p><strong>Items:</strong>
                    ${escapeHTML(order.items || 'No items')}
                </p>

                <p><strong>Payment:</strong>
                    ${escapeHTML(order.payment_method || 'N/A')}
                </p>

                <p><strong>Date:</strong>
                    ${escapeHTML(order.created_at || 'N/A')}
                </p>
            </div>

            <div class="order-card-footer">
                <strong class="order-total">
                    ₱${Number(order.total_amount || 0).toFixed(2)}
                </strong>
                <span class="order-reference">
                    Ref: ${escapeHTML(order.order_number || order.id)}
                </span>
            </div>
        </div>
    `;
}

// Draw the orders that match the selected filter.
function renderOrders() {
    const message = document.getElementById('ordersMessage');
    const ordersGrid = document.getElementById('ordersGrid');

    if (!message || !ordersGrid) return;

    updateOrderCounts();

    const visibleOrders = activeOrderFilter
        ? allOrders.filter(order => (order.status || 'Pending') === activeOrderFilter)
        : allOrders.filter(isActiveOrder);

    if (visibleOrders.length === 0) {
        ordersGrid.innerHTML = '';

        if (activeOrderFilter === 'Done') {
            message.textContent = 'No completed orders today.';
        } else if (activeOrderFilter) {
            message.textContent =
                'No ' + orderStatusLabel(activeOrderFilter).toLowerCase() + ' orders.';
        } else {
            message.textContent = 'No orders found.';
        }

        return;
    }

    message.textContent = '';
    ordersGrid.innerHTML = visibleOrders.map(orderCardHtml).join('');
}

async function fetchOrders() {
    const response = await fetch('get_orders.php', {
        cache: 'no-store'
    });

    const result = await response.json();

    if (!response.ok || !result.success) {
        throw new Error(result.message || 'Unable to load orders.');
    }

    return result.data || [];
}

// Load the orders and show a "Loading" message (used when the tab opens).
async function loadOrders() {
    const message = document.getElementById('ordersMessage');
    const ordersGrid = document.getElementById('ordersGrid');

    message.textContent = 'Loading orders...';
    ordersGrid.innerHTML = '';

    try {
        allOrders = await fetchOrders();
        ordersSignature = JSON.stringify(allOrders);
        renderOrders();

    } catch (error) {
        console.error('Error loading orders:', error);
        message.textContent = 'Unable to load orders. Please try again.';
    }
}

// Refresh quietly in the background. The list is only redrawn when
// something changed, so cards do not flicker.
async function refreshOrdersQuietly() {
    const ordersSection = document.getElementById('ordersSection');

    if (!ordersSection || ordersSection.hidden) return;
    if (document.visibilityState !== 'visible') return;
    if (ordersBusy) return;

    try {
        const latest = await fetchOrders();
        const signature = JSON.stringify(latest);

        // A status change may have started while we were waiting.
        if (ordersBusy || signature === ordersSignature) return;

        allOrders = latest;
        ordersSignature = signature;
        renderOrders();
        updateOrdersNotificationBadge();

    } catch (error) {
        // A failed background refresh is ignored; the next one will retry.
        console.warn('Background order refresh failed:', error);
    }
}

setInterval(refreshOrdersQuietly, ORDERS_REFRESH_MS);

// Refresh as soon as the page becomes visible again.
document.addEventListener('visibilitychange', refreshOrdersQuietly);

// 754 -> "12:34"
function formatPayClock(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const minutes = Math.floor(seconds / 60);

    return String(minutes).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
}

// The clock, badge and button of one order that is waiting for payment.
// Two stages:
//   1. normal payment time      -> "Time left to pay"
//   2. late-payment window      -> red "Late payment window"; the button says
//                                  "Confirm late payment"
// When the late-payment window ends the server cancels the order for good.
// Returns the seconds left until the order is cancelled.
function applyGcashClock(card) {
    const now = Date.now();
    const payLeft = (Number(card.dataset.payExpires) - now) / 1000;
    const cancelLeft = (Number(card.dataset.cancelExpires) - now) / 1000;
    const isLate = payLeft <= 0;

    const line = card.querySelector('.gcash-timer-line');
    const badge = card.querySelector('.order-status');
    const button = card.querySelector('.confirm-gcash-btn');

    if (line) {
        line.classList.toggle('is-late', isLate);

        line.innerHTML = isLate
            ? 'Payment time is over. Late payment window: <strong class="gcash-timer is-urgent">' +
                formatPayClock(cancelLeft) + '</strong> left, then the order is cancelled.'
            : 'Time left to pay: <strong class="gcash-timer' + (payLeft <= 120 ? ' is-urgent' : '') + '">' +
                formatPayClock(payLeft) + '</strong>';
    }

    card.classList.toggle('gcash-late', isLate);

    if (badge) {
        badge.textContent = isLate ? 'Overdue' : (badge.dataset.status || 'Pending');
    }

    if (button) {
        button.classList.toggle('is-late', isLate);
        button.dataset.label = isLate ? 'Confirm late payment' : 'Confirm payment received';

        // Do not overwrite "Confirming..." while a request is running.
        if (!button.disabled) {
            button.textContent = button.dataset.label;
        }
    }

    return cancelLeft;
}

// Tick every second. When an order's late-payment window ends, reload the
// list: the server cancels the order and it disappears from here.
let gcashClockReloading = false;
let gcashLastClockReload = 0;

setInterval(function () {
    const list = document.getElementById('gcashOrdersList');

    // Nothing to do while the GCash window is closed.
    if (!list || list.offsetParent === null) return;

    let anyCancelled = false;

    list.querySelectorAll('.order-card[data-pay-expires]').forEach(function (card) {
        if (applyGcashClock(card) <= 0) anyCancelled = true;
    });

    // (At most once every 5 seconds, so a slow server is never hammered.)
    if (anyCancelled && !gcashClockReloading && Date.now() - gcashLastClockReload > 5000) {
        gcashClockReloading = true;
        gcashLastClockReload = Date.now();

        loadGcashOrders().finally(function () {
            gcashClockReloading = false;
        });
    }
}, 1000);

let gcashOrdersById = {};

/* Load unpaid GCash orders into the modal */
async function loadGcashOrders() {
    const message = document.getElementById('gcashOrdersMessage');
    const list = document.getElementById('gcashOrdersList');

    if (!message || !list) return;

    try {
        const response = await fetch('get_gcash_orders.php', {
            cache: 'no-store'
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Unable to load GCash orders.'
            );
        }

        if (window.refreshAdminNotifications) window.refreshAdminNotifications();

        if (!result.data || result.data.length === 0) {
            message.textContent = 'No unpaid GCash orders.';
            list.innerHTML = '';
            return;
        }

        message.textContent = '';

        gcashOrdersById = {};

        const loadedAt = Date.now();

        result.data.forEach(order => {
            // When the normal payment time and the late-payment window end (browser clock).
            order.payExpires = loadedAt + (Number(order.pay_seconds_left) || 0) * 1000;
            order.cancelExpires = loadedAt + (Number(order.cancel_seconds_left) || 0) * 1000;
            gcashOrdersById[order.id] = order;
        });

        list.innerHTML = result.data.map(order => {
            return `
            <div class="order-card" data-pay-expires="${order.payExpires}" data-cancel-expires="${order.cancelExpires}">
                <div class="order-card-header">
                    <h3>Order #${escapeHTML(order.id)}</h3>
                    <span class="order-status" data-status="${escapeHTML(order.status || 'Pending')}">
                        ${escapeHTML(order.status || 'Pending')}
                    </span>
                </div>

                <p class="gcash-timer-line"></p>

                <div class="order-card-details">
                    <p><strong>Customer:</strong>
                        ${escapeHTML(order.customer_name || 'N/A')}
                    </p>

                    <p><strong>Items:</strong>
                        ${escapeHTML(order.items || 'No items')}
                    </p>

                    <p><strong>Payment:</strong>
                        ${escapeHTML(order.payment_method || 'GCash')}
                    </p>

                    <p><strong>Payment status:</strong>
                        ${escapeHTML(order.payment_status || 'Pending')}
                    </p>

                    <p><strong>Receipt:</strong>
                        ${order.has_receipt
                            ? '<span class="gcash-receipt-badge is-sent">Receipt submitted</span>'
                            : '<span class="gcash-receipt-badge">Not submitted</span>'}
                    </p>

                    <p><strong>Date:</strong>
                        ${escapeHTML(order.created_at || 'N/A')}
                    </p>
                </div>

                <div class="order-card-footer">
                    <strong class="order-total">
                        ₱${Number(order.total_amount || 0).toFixed(2)}
                    </strong>

                    <span class="order-reference">
                        Ref: ${escapeHTML(order.order_number || order.id)}
                    </span>
                </div>

                <div class="gcash-card-actions">
                <button type="button" class="gcash-detail-btn" data-gcash-detail="${Number(order.id)}">
                    View details${order.has_receipt ? '' : ' (no receipt yet)'}
                </button>
                </div>

                <button type="button" class="confirm-gcash-btn"
                        data-confirm-gcash="${Number(order.id)}"
                        data-label="Confirm payment received">
                    Confirm payment received
                </button>
            </div>
        `;
        }).join('');

        // Fill in each clock right away (it then ticks every second).
        list.querySelectorAll('.order-card[data-pay-expires]').forEach(applyGcashClock);

    } catch (error) {
        console.error('Error loading GCash orders:', error);
        message.textContent = 'Unable to load GCash orders. Please try again.';
    }
}


    function renderProducts(category) {
    currentCategory = category;

    const productGrid = document.getElementById('productGrid');
    if (!productGrid) return;

    const items = menuData[category] || [];

    if (items.length === 0) {
        productGrid.innerHTML = window.isAdmin
            ? '<p class="menu-empty-note">No products in this category yet. Use <strong>Add Product</strong> to add one.</p>'
            : '<p>No available products in this category yet.</p>';
        return;
    }

    productGrid.innerHTML = items.map((item, index) => {
        const isAvailable = item.isOrderable === true;

        // A product sold in 2 or more sizes shows its lowest price as "From".
        const sizePrices = [item.small, item.med, item.lrg].filter(price => price > 0);
        const hasSizes = sizePrices.length >= 2;
        const displayPrice = hasSizes ? Math.min(...sizePrices) : item.price;

        const statusText = !item.isActive
            ? 'Inactive'
            : (isAvailable ? 'Available' : 'Unavailable');

        return `
            <div class="product-card ${!isAvailable ? 'unavailable-product' : ''} ${!item.isActive ? 'inactive-product' : ''}"
                 data-product-index="${index}">

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

            ${window.isAdmin ? `
                <span class="menu-status-pill ${item.isActive ? 'is-active' : 'is-inactive'}">
                    ${item.isActive ? 'Active' : 'Inactive'}
                </span>

                <div class="menu-admin-actions">
                    <button type="button" class="view-product-btn"
                        data-action="view" data-id="${item.id}">
                        View Details
                    </button>

                    <button type="button" class="edit-product-btn"
                        data-action="edit" data-id="${item.id}">
                        Edit Product
                    </button>

                    ${item.isActive ? `
                        <button type="button" class="deactivate-btn"
                            data-action="deactivate" data-id="${item.id}">
                            Deactivate
                        </button>
                    ` : `
                        <button type="button" class="activate-btn"
                            data-action="activate" data-id="${item.id}">
                            Activate
                        </button>
                    `}
                </div>
            ` : ''}


                <p class="product-price">
                    ${hasSizes ? 'From ' : ''}₱${displayPrice.toFixed(2)}
                </p>

                <p class="product-stock-status ${isAvailable ? 'available-text' : 'unavailable-text'}">
                    ${statusText}
                </p>
            </div>
        `;
    }).join('');

    productGrid.querySelectorAll('.product-card').forEach((card, index) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('.menu-admin-actions')) return;

            // Admin: the Menu is a catalog, nothing is ordered here. Clicking a product
            // shows its details instead (Edit / Deactivate are on the card).
            if (window.isAdmin) {
                const viewButton = card.querySelector('[data-action="view"]');

                if (viewButton) viewButton.click();
                return;
            }

            openModal(items[index]);
        });
    });
}

// Category Switch
function switchCategory(selectedBtn, categoryTitle) {
    document.querySelectorAll('.menu-category-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-pressed', 'false');
    });
    selectedBtn.classList.add('active');
    selectedBtn.setAttribute('aria-pressed', 'true');

    // Remember the selected category.
    sessionStorage.setItem('lastMenuCategory', categoryTitle);

    const titleEl = document.getElementById('sectionTitle');
    if (titleEl) {
        titleEl.textContent = categoryTitle;
    }
    renderProducts(categoryTitle);
}

// Modal Handlers
function openModal(item) {
    if (!item) return;

    // The admin's Menu is a catalog only: there is no order to add to.
    if (window.isAdmin) return;

// Inactive products cannot be ordered (only admins can see them).
if (item.isActive === false) {
    alert('This product is inactive. Activate it to make it available for ordering.');
    return;
}

// Prevent ordering products with missing or insufficient ingredients.
if (item.isOrderable !== true) {
    alert(
        'This product is currently unavailable because one or more required ingredients are out of stock or not configured.'
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
        alert(`An order is limited to ${MAX_CART_QTY} items. Remove something to add more.`);
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

    closeModal();
    updateCartUI();
    expandOrderPanel();
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
          <button type="button" class="cart-remove-btn" onclick="removeFromCart(${index})" aria-label="Remove ${escapeHTML(item.name)} from the order">
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

    const limitHint = document.getElementById('cartLimitHint');
    if (limitHint) {
        limitHint.textContent = `${totalItemsCount} of ${MAX_CART_QTY} items`;
        limitHint.classList.toggle('full', totalItemsCount >= MAX_CART_QTY);
    }

    const checkoutButton = document.getElementById('checkoutBtn');
    if (checkoutButton) {
        const blocked = cart.some(cartLineProblem);
        checkoutButton.classList.toggle('stock-blocked', blocked);
        checkoutButton.title = blocked ? 'Some items in the order are more than the stock can make right now.' : '';
    }

    if (!skipStockCheck) scheduleStockCheck();
}

// Most items (all products together) a single order can hold. The server enforces the same limit.
const MAX_CART_QTY = 50;

function cartTotal() {
    return cart.reduce((sum, item) => sum + item.qty, 0);
}

// Set a line's quantity: at least 1, and the whole order never goes over MAX_CART_QTY.
function setQty(index, value) {
    if (!cart[index]) return;

    const room = MAX_CART_QTY - (cartTotal() - cart[index].qty);
    let qty = Math.floor(Number(value));

    if (!Number.isFinite(qty) || qty < 1) qty = 1;

    if (qty > room) {
        qty = Math.max(1, room);
        alert(`An order is limited to ${MAX_CART_QTY} items in total.`);
    }

    cart[index].qty = qty;
    updateCartUI();
}

// Take a whole line out of the order, whatever its quantity.
function removeFromCart(index) {
    if (!cart[index]) return;

    cart.splice(index, 1);
    updateCartUI();
}

function changeQty(index, delta) {
    if (delta > 0 && cartTotal() >= MAX_CART_QTY) {
        alert(`An order is limited to ${MAX_CART_QTY} items in total.`);
        return;
    }

    cart[index].qty += delta;
    if (cart[index].qty <= 0) cart.splice(index, 1);
    updateCartUI();
}

// STOCK INDICATOR: asks the server how many of each order line the inventory can supply.
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
        return `<span class="cart-stock cart-stock-bad">Only ${item.stockMax} available. Ordered ${item.qty}.
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

        // The order changed while we were asking; a newer check is on its way.
        if (requestId !== stockRequestId || !result.ok || !Array.isArray(result.lines)) return;
        if (result.lines.length !== cart.length) return;

        cart.forEach((item, i) => { item.stockMax = result.lines[i].max; });

        skipStockCheck = true;
        updateCartUI();
        skipStockCheck = false;
    } catch (error) {
        skipStockCheck = false; // the indicator is a hint; the server checks again when the order is saved
    }
}


 // CHECKOUT & PAYMENT FLOW

let selectedPaymentMethod = null;

// Step 1: Display order details and total
function handleCheckout() {
    if (cart.length === 0) {
        alert('Please add items to your order first.');
        return;
    }

    const problemLine = cart.find(cartLineProblem);
    if (problemLine) {
        alert(problemLine.stockMax > 0
            ? `Only ${problemLine.stockMax} of ${problemLine.name} can be made right now. Please lower the quantity.`
            : `${problemLine.name} cannot be made right now. Please remove it from the order.`);
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

document.getElementById('confirmOrderNo').textContent =
    'Order No. will be assigned when the order is saved';

    document.getElementById('confirmTotalAmount').textContent =
        `₱ ${currentOrderTotal.toFixed(2)}`;

    // Reset payment selection whenever checkout opens.
    selectedPaymentMethod = null;

    document.getElementById('cashPaymentSection').hidden = true;
    document.getElementById('gcashPaymentSection').hidden = true;

    document.getElementById('cashPaymentOption').classList.remove('active');
    document.getElementById('gcashPaymentOption').classList.remove('active');

    document.getElementById('cashTotalDisplay').textContent =
        `₱ ${currentOrderTotal.toFixed(2)}`;

    document.getElementById('cashReceivedInput').value = '';
    document.getElementById('cashChangeDisplay').textContent = '₱ 0.00';
    document.getElementById('confirmCashBtn').disabled = true;

    document.getElementById('confirmationModal').classList.add('show');
}

// Close the combined modal
function closeConfirmationModal() {
    document.getElementById('confirmationModal').classList.remove('show');
}

// Kept for compatibility with any existing calls.
function proceedToPayment() {
    // Payment selection is now inside the confirmation modal.
    document.getElementById('confirmationModal').classList.add('show');
}

function closePaymentModal() {
    closeConfirmationModal();
}

// Step 2: Select Cash or GCash within the same modal
function selectPayment(method) {
    if (method !== 'Cash' && method !== 'GCash') return;

    selectedPaymentMethod = method;

    const cashSection = document.getElementById('cashPaymentSection');
    const gcashSection = document.getElementById('gcashPaymentSection');

    cashSection.hidden = method !== 'Cash';
    gcashSection.hidden = method !== 'GCash';

    document.getElementById('cashPaymentOption')
        .classList.toggle('active', method === 'Cash');

    document.getElementById('gcashPaymentOption')
        .classList.toggle('active', method === 'GCash');

    if (method === 'Cash') {
        document.getElementById('cashTotalDisplay').textContent =
            `₱ ${currentOrderTotal.toFixed(2)}`;

        calculateChange();
    }
}

// Kept for compatibility; payment switching now happens in the same modal.
function switchPaymentOption(currentModalId) {
    selectPayment('Cash');
}

// Final "are you sure?" before the order is saved (same as the customer side).
let pendingPaymentMethod = null;

function askPlaceOrder(method) {
    if (method !== selectedPaymentMethod) {
        alert('Please select your payment method first.');
        return;
    }

    if (cart.length === 0 || currentOrderTotal <= 0) {
        alert('Your order is empty. Please add items first.');
        return;
    }

    if (method === 'Cash') {
        const tendered = Number(document.getElementById('cashReceivedInput').value) || 0;

        if (tendered < currentOrderTotal) {
            alert('The amount tendered is not enough to cover the total.');
            return;
        }
    }

    pendingPaymentMethod = method;

    const count = cart.reduce((sum, item) => sum + item.qty, 0);
    document.getElementById('sureText').textContent =
        `${count} item${count !== 1 ? 's' : ''}, total ₱ ${currentOrderTotal.toFixed(2)}, paid by ${method}. ` +
        'Once you continue, the order is saved.';

    document.getElementById('placeOrderSure').classList.add('show');
    document.getElementById('sureYesBtn').focus();
}

function cancelPlaceOrder() {
    pendingPaymentMethod = null;
    document.getElementById('placeOrderSure').classList.remove('show');
}

function confirmPlaceOrder() {
    const method = pendingPaymentMethod;
    cancelPlaceOrder();

    if (method) completeTransaction(method);
}

// Step 3: Calculate cash change
function calculateChange() {
    const inputEl = document.getElementById('cashReceivedInput');
    const confirmBtn = document.getElementById('confirmCashBtn');
    const changeDisplay = document.getElementById('cashChangeDisplay');

    const tendered = Number(inputEl.value) || 0;
    const change = tendered - currentOrderTotal;

    if (
        selectedPaymentMethod === 'Cash' &&
        inputEl.value !== '' &&
        tendered >= currentOrderTotal &&
        currentOrderTotal > 0
    ) {
        changeDisplay.textContent = `₱ ${change.toFixed(2)}`;
        confirmBtn.disabled = false;
    } else {
        changeDisplay.textContent = '₱ 0.00';
        confirmBtn.disabled = true;
    }
}

// Step 4: Save the transaction
async function completeTransaction(method) {
    if (method !== selectedPaymentMethod) {
        alert('Please select your payment method first.');
        return;
    }

    if (cart.length === 0 || currentOrderTotal <= 0) {
        alert('Your order is empty. Please add items first.');
        return;
    }

    let tendered = 0;
    let change = 0;

    if (method === 'Cash') {
        tendered = Number(
            document.getElementById('cashReceivedInput').value
        ) || 0;

        if (tendered < currentOrderTotal) {
            alert('The amount tendered is not enough to cover the total.');
            return;
        }

        change = tendered - currentOrderTotal;
    }

    const customerName =
        document.getElementById('customerNameInput').value.trim()
        || 'Walk-in Customer';

    const orderPayload = {
        customer_name: customerName,
        payment_method: method,
        total_amount: currentOrderTotal,
        items: cart
    };

    const cashBtn = document.getElementById('confirmCashBtn');
    const gcashBtn = document.getElementById('confirmGcashBtn');

    // Prevent accidental double submission.
    cashBtn.disabled = true;
    gcashBtn.disabled = true;

    cashBtn.textContent = 'Processing...';
    gcashBtn.textContent = 'Processing...';

    try {
        const response = await fetch('../php/save_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(orderPayload)
        });

        const result = await response.json();

        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Unable to save the order.');
        }
        console.log('Saved order response:', result);
        console.log('Order ID returned:', result.order_id);
        

        if (method === 'Cash') {
            if (!result.order_id) {
                throw new Error(
                    'The order was saved, but the server did not return its order ID.'
                );
            }

            alert(
                `Order #${result.order_number} complete!\n` +
                `Customer: ${customerName}\n` +
                `Total: ₱${currentOrderTotal.toFixed(2)}\n` +
                `Tendered: ₱${tendered.toFixed(2)}\n` +
                `Change: ₱${change.toFixed(2)}`
            );

            // Open the receipt only after the server confirms the order was saved.
            window.location.href =
                `../php/receipt.php?order_id=${encodeURIComponent(result.order_id)}`;

            return;
        } else {
            // TEMPORARY manual mode (PayMongo not set up): the order is saved
            // as "awaiting payment" and confirmed from the GCash Orders list.
            if (result.manual_gcash) {
                alert(
                    `Order #${result.order_number} saved.\n` +
                    'Once the GCash payment is received, open "GCash Orders" ' +
                    'and press "Confirm payment".'
                );

                document.getElementById('confirmationModal').classList.remove('show');
                document.getElementById('customerNameInput').value = '';
                cart = [];
                currentOrderTotal = 0;
                selectedPaymentMethod = null;
                updateCartUI();
                return;
            }

            if (!result.checkout_url) {
                throw new Error(
                    'PayMongo checkout URL was not returned by the server.'
                );
            }

            // The order is saved (it waits in the GCash Orders list), so the order screen is
            // emptied before leaving for PayMongo. If the staff member comes Back without
            // paying, cancelAbandonedCheckout() cancels that order.
            document.getElementById('confirmationModal').classList.remove('show');
            document.getElementById('customerNameInput').value = '';
            cart = [];
            currentOrderTotal = 0;
            selectedPaymentMethod = null;
            updateCartUI();

            try { window.sessionStorage.setItem('djcPayOrder', String(result.order_id)); } catch (e) { /* storage blocked */ }

            window.location.href = result.checkout_url;
            return;
        }


        // Close the combined modal and reset the order.
        document.getElementById('confirmationModal').classList.remove('show');

        document.getElementById('customerNameInput').value = '';

        document.getElementById('cashPaymentSection').hidden = true;
        document.getElementById('gcashPaymentSection').hidden = true;

        cart = [];
        currentOrderTotal = 0;
        selectedPaymentMethod = null;

        updateCartUI();

    } catch (error) {
        console.error('Error submitting order:', error);
        alert('Unable to complete the transaction: ' + error.message);
    } finally {
        cashBtn.textContent = 'Complete Cash Payment';
        gcashBtn.textContent = 'Confirm GCash Payment';

        // Re-enable the correct button only.
        cashBtn.disabled = true;
        gcashBtn.disabled = false;

        if (selectedPaymentMethod === 'Cash') {
            calculateChange();
        }
    }
}

// Small message at the bottom of the screen.
function showStaffNotice(message) {
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
    clearTimeout(showStaffNotice.timer);
    showStaffNotice.timer = setTimeout(() => notice.classList.remove('show'), 5000);
}

// Back from the PayMongo checkout without paying: cancel that order.
// (Paying or the checkout's Cancel button end on gcash_return.php, which clears the
// reminder, so a paid order is never cancelled here. Only a Back/Forward visit counts.)
// Returns a promise so the page can wait for it before it reloads itself.
function cancelAbandonedCheckout(fromCache) {
    let orderId = null;

    try {
        orderId = window.sessionStorage.getItem('djcPayOrder');
        window.sessionStorage.removeItem('djcPayOrder');
    } catch (e) { return Promise.resolve(); }

    if (!orderId) return Promise.resolve();

    const nav = window.performance && performance.getEntriesByType
        ? performance.getEntriesByType('navigation')[0]
        : null;

    if (!fromCache && !(nav && nav.type === 'back_forward')) return Promise.resolve();

    return fetch('../php/cancel_pending_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: Number(orderId) })
    })
        .then(response => response.json())
        .then(result => {
            if (result && result.cancelled) {
                // The page may reload right after this: show the message once it is back.
                try { window.sessionStorage.setItem('djcPayCancelNotice', '1'); } catch (e) { /* storage blocked */ }
                showStaffNotice('The GCash order was cancelled because you left the payment page.');
            }
        })
        .catch(() => { /* the order simply stays in the GCash Orders list until its time runs out */ });
}

// The browser can restore this page from memory when Back is pressed (order.php then reloads it).
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.djcPendingCancel = cancelAbandonedCheckout(true);
    }
});

    // Initialization
    document.addEventListener('DOMContentLoaded', () => {
    cancelAbandonedCheckout(false);

    try {
        if (window.sessionStorage.getItem('djcPayCancelNotice')) {
            window.sessionStorage.removeItem('djcPayCancelNotice');
            showStaffNotice('The GCash order was cancelled because you left the payment page.');
        }
    } catch (e) { /* storage blocked */ }

    loadMenuProducts();
    updateOrdersNotificationBadge();
    setInterval(updateOrdersNotificationBadge, 5000);

    // Refresh only the GCash modal while it is open.
    setInterval(() => {
        const gcashModal = document.getElementById('gcashOrdersModal');

        if (gcashModal && !gcashModal.hidden) {
            loadGcashOrders();
        }
    }, 5000);
    
    // Menu and Orders navigation
    const menuNavBtn = document.getElementById('menuNavBtn');
    const ordersNavBtn = document.getElementById('ordersNavBtn');
    const menuSection = document.getElementById('menuSection');
    const ordersSection = document.getElementById('ordersSection');


    function showMenuSection() {
        menuSection.hidden = false;
        ordersSection.hidden = true;

        sessionStorage.setItem('activeOrderSection', 'menu');
        syncOrdersNav();
    }


    async function showOrdersSection() {
        menuSection.hidden = true;
        ordersSection.hidden = false;

        sessionStorage.setItem('activeOrderSection', 'orders');
        setOrderFilter(activeOrderFilter);
        syncOrdersNav();

        await loadOrders();
    }


    if (menuNavBtn && ordersNavBtn && menuSection && ordersSection) {
        menuNavBtn.addEventListener('click', (event) => {
            event.preventDefault();
            showMenuSection();
        });

        // "Orders" opens the list with every order that is not completed.
        ordersNavBtn.addEventListener('click', (event) => {
            event.preventDefault();
            setOrderFilter('');
            showOrdersSection();
        });

        // The labels under the heading filter the list by status.
        document.querySelectorAll('.orders-filter-btn').forEach(button => {
            button.addEventListener('click', () => {
                setOrderFilter(button.dataset.filter);
                syncOrdersNav();
                renderOrders();
            });
        });
    }

    const savedSection = sessionStorage.getItem('activeOrderSection');

    if (savedSection === 'orders') {
        showOrdersSection();
    } else {
        showMenuSection();
    }

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

let selectedMenuProduct = null;
// Deactivate-product confirmation modal.
// (These were used by the handlers below but never declared, which stopped
// the rest of this script from running, including the order status buttons.)
const deactivateMenuModal = document.getElementById('deactivateMenuModal');
const cancelDeactivateMenuButton = document.getElementById('cancelDeactivateMenu');
const confirmDeactivateMenuButton = document.getElementById('confirmDeactivateMenu');

// Activate / deactivate a menu product (admin only).
async function setMenuProductStatus(productId, status) {
    const formData = new FormData();

    formData.append('product_id', productId);
    formData.append('product_type', 'menu');
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

function closeDeactivateMenuModal() {
    if (!deactivateMenuModal) return;

    deactivateMenuModal.classList.remove('show');
    deactivateMenuModal.classList.add('hidden');
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.menu-admin-actions button');
    if (!button || !window.isAdmin) return;

    event.stopPropagation();

    const productId = Number(button.dataset.id);
    const item = (menuData[currentCategory] || [])
        .find(product => product.id === productId);

    if (!item) return;

    selectedMenuProduct = item;

    if (button.dataset.action === 'view') {
        document.getElementById('menuDetailsContent').innerHTML = `
            <div class="menu-details-image">
                ${
                    item.img
                        ? `<img loading="lazy" decoding="async" src="${escapeHTML(item.img)}"
                                alt="${escapeHTML(item.name)}"
                                onerror="this.style.display='none'">`
                        : '<p class="menu-details-no-image">No product image available.</p>'
                }
            </div>

            <p><strong>Name:</strong> ${escapeHTML(item.name)}</p>
            <p><strong>Category:</strong> ${escapeHTML(item.category)}</p>
            <p><strong>Availability:</strong> ${escapeHTML(item.availability)}</p>
            <p><strong>Selling Price:</strong> ₱${item.price.toFixed(2)}</p>
            <p><strong>Small:</strong> ₱${item.small.toFixed(2)}</p>
            <p><strong>Medium:</strong> ₱${item.med.toFixed(2)}</p>
            <p><strong>Large:</strong> ₱${item.lrg.toFixed(2)}</p>
            <p><strong>Description:</strong> ${escapeHTML(item.description || 'None')}</p>
        `;

        document.getElementById('menuDetailsModal').classList.add('show');
        return;
    }

    if (button.dataset.action === 'edit') {
        // The shared Add / Edit Product form (menu_add_product.js) opens it.
        document.dispatchEvent(
            new CustomEvent('menu-product-edit', { detail: item })
        );
        return;
    }

    if (button.dataset.action === 'deactivate') {
        document.getElementById('deactivateMenuName').textContent = item.name;
        document.getElementById('deactivateMenuMessage').textContent = '';

        const modal = document.getElementById('deactivateMenuModal');
        modal.classList.remove('hidden');
        modal.classList.add('show');

        confirmDeactivateMenuButton.disabled = false;
        confirmDeactivateMenuButton.textContent = 'Deactivate';
        return;
    }

    // Activate: no confirmation needed.
    if (button.dataset.action === 'activate') {
        button.disabled = true;

        try {
            await setMenuProductStatus(item.id, 'active');

            sessionStorage.setItem('lastMenuCategory', currentCategory);
            window.location.reload();
        } catch (error) {
            alert(error.message);
            button.disabled = false;
        }
    }
});

// Cancel without archiving.
if (cancelDeactivateMenuButton) {
    cancelDeactivateMenuButton.addEventListener('click', closeDeactivateMenuModal);
}

// Deactivate the selected menu product.
if (confirmDeactivateMenuButton) {
    confirmDeactivateMenuButton.addEventListener('click', async () => {
        if (!window.isAdmin || !selectedMenuProduct) return;

        const message = document.getElementById('deactivateMenuMessage');

        confirmDeactivateMenuButton.disabled = true;
        confirmDeactivateMenuButton.textContent = 'Deactivating...';

        if (message) {
            message.textContent = '';
        }

        try {
            await setMenuProductStatus(selectedMenuProduct.id, 'inactive');

            // Remember the current menu category before refreshing.
            sessionStorage.setItem('lastMenuCategory', currentCategory);

            window.location.reload();

        } catch (error) {
            if (message) {
                message.textContent = error.message;
            }

            confirmDeactivateMenuButton.disabled = false;
            confirmDeactivateMenuButton.textContent = 'Try Again';
        }
    });
}

// Close the modal when clicking outside it.
if (deactivateMenuModal) {
    deactivateMenuModal.addEventListener('click', (event) => {
        if (event.target === deactivateMenuModal &&
            confirmDeactivateMenuButton &&
            !confirmDeactivateMenuButton.disabled) {
            closeDeactivateMenuModal();
        }
    });
}

let pendingDoneButton = null;

const confirmOrderDoneModal = document.getElementById('confirmOrderDoneModal');
const confirmOrderDoneButton = document.getElementById('confirmOrderDone');
const cancelOrderDoneButton = document.getElementById('cancelOrderDone');
const confirmOrderDoneMessage = document.getElementById('confirmOrderDoneMessage');

document.addEventListener('click', async function (event) {
    const button = event.target.closest('.order-status-btn');

    if (!button) {
        return;
    }

    const orderId = button.dataset.orderId;
    const status = button.dataset.status;
    const orderCard = button.closest('.order-card');

    // Nothing to do when this status is already selected.
    if (button.classList.contains('active')) {
        return;
    }

    // Ask for confirmation before marking an order as Done.
    if (status === 'Done') {
        pendingDoneButton = button;

        if (confirmOrderDoneMessage) {
            confirmOrderDoneMessage.textContent = '';
        }

        if (confirmOrderDoneModal) {
            confirmOrderDoneModal.classList.add('show');
        }

        return;
    }

    await updateOrderStatus(button, orderId, status, orderCard);
});

async function updateOrderStatus(button, orderId, status) {
    button.disabled = true;
    ordersBusy = true;

    try {
        const response = await fetch('update_order_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                order_id: orderId,
                status: status
            })
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to update order status.');
        }

        // Keep the list in step with the database, then redraw it so the
        // order moves out of a filter it no longer belongs to.
        const order = allOrders.find(item => Number(item.id) === Number(orderId));

        if (order) {
            order.status = status;
        }

        ordersSignature = JSON.stringify(allOrders);

        renderOrders();
        updateOrdersNotificationBadge();

    } catch (error) {
        console.error('Error updating order status:', error);
        alert(error.message || 'Unable to update order status.');
        button.disabled = false;
    } finally {
        ordersBusy = false;
    }
}

// Cancel marking the order as Done.
if (cancelOrderDoneButton) {
    cancelOrderDoneButton.addEventListener('click', function () {
        if (confirmOrderDoneModal) {
            confirmOrderDoneModal.classList.remove('show');
        }

        pendingDoneButton = null;
    });
}

// Confirm marking the order as Done.
if (confirmOrderDoneButton) {
    confirmOrderDoneButton.addEventListener('click', async function () {
        if (!pendingDoneButton) {
            return;
        }

        const button = pendingDoneButton;
        const orderId = button.dataset.orderId;
        const orderCard = button.closest('.order-card');

        confirmOrderDoneButton.disabled = true;

        try {
            await updateOrderStatus(button, orderId, 'Done', orderCard);

            if (confirmOrderDoneModal) {
                confirmOrderDoneModal.classList.remove('show');
            }

            pendingDoneButton = null;

        } finally {
            confirmOrderDoneButton.disabled = false;
        }
    });
}

/* GCash Orders Modal Controls */
const openGcashButton = document.getElementById('openGcashOrders');
const gcashModal = document.getElementById('gcashOrdersModal');
const closeGcashButton = document.getElementById('closeGcashOrders');
const gcashOverlay = document.getElementById('closeGcashOverlay');

if (openGcashButton && gcashModal && closeGcashButton && gcashOverlay) {
    function openGcashModal() {
        gcashModal.hidden = false;
        document.body.style.overflow = 'hidden';
        closeGcashButton.focus();
    }

    function closeGcashModal() {
        gcashModal.hidden = true;
        document.body.style.overflow = '';
        openGcashButton.focus();
    }

    openGcashButton.addEventListener('click', async function () {
        openGcashModal();
        await loadGcashOrders();
    });

    closeGcashButton.addEventListener('click', closeGcashModal);
    gcashOverlay.addEventListener('click', closeGcashModal);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !gcashModal.hidden) {
            closeGcashModal();
        }
    });
} else {
    console.error('GCash modal elements are missing from order.php.');
}
});

// TEMPORARY manual GCash confirmation (until PayMongo is set up).
document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-confirm-gcash]');
    if (!button) return;

    // Past the normal payment time = a late payment (still allowed for a few more minutes).
    const gcashOrder = gcashOrdersById[Number(button.dataset.confirmGcash)];
    const isLate = !!gcashOrder && Date.now() >= gcashOrder.payExpires;

    const question = isLate
        ? 'The normal payment time is over. Only continue if the customer really paid. The order is still inside its late-payment window, so you can confirm it now: the ingredients will be deducted and the order will start. Continue?'
        : 'Confirm that you received this GCash payment? The ingredients will be deducted and the order will start.';

    if (!confirm(question)) {
        return;
    }

    button.disabled = true;
    button.textContent = 'Confirming...';

    try {
        const response = await fetch('confirm_gcash_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: Number(button.dataset.confirmGcash) })
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to confirm the payment.');
        }

        if (isLate) {
            alert(result.message);
        }

        if (typeof closeGcashDetail === 'function') closeGcashDetail();
        await loadGcashOrders();
        if (typeof loadOrders === 'function') loadOrders();
        if (typeof updateOrdersNotificationBadge === 'function') updateOrdersNotificationBadge();
        if (window.refreshAdminNotifications) window.refreshAdminNotifications();
    } catch (error) {
        alert(error.message);
        button.disabled = false;
        button.textContent = button.dataset.label || 'Confirm payment received';

        // The order may have just been cancelled (late-payment window over): refresh the list.
        if (typeof closeGcashDetail === 'function') closeGcashDetail();
        loadGcashOrders();
    }
});


/* ---------- GCash order details (receipt picture, account name, reference number) ---------- */
function closeGcashDetail() {
    const modal = document.getElementById('gcashDetailModal');
    if (modal) modal.hidden = true;
}

function openGcashDetail(orderId) {
    const order = gcashOrdersById[orderId];
    const modal = document.getElementById('gcashDetailModal');
    const body = document.getElementById('gcashDetailBody');

    if (!order || !modal || !body) return;

    const isLate = Date.now() >= order.payExpires;
    const stamp = order.gcash_receipt_at ? escapeHTML(order.gcash_receipt_at) : '';

    document.getElementById('gcashDetailTitle').textContent = 'Order #' + order.id + ' \u2013 GCash payment';

    const receipt = order.has_receipt
        ? `<a class="gcash-receipt-link" href="gcash_receipt_image.php?order_id=${Number(order.id)}&v=${encodeURIComponent(order.gcash_receipt_at || '')}" target="_blank" rel="noopener" title="Open the full picture">
               <img src="gcash_receipt_image.php?order_id=${Number(order.id)}&v=${encodeURIComponent(order.gcash_receipt_at || '')}" alt="Receipt picture uploaded by the customer">
           </a>
           <p class="gcash-receipt-hint">Tap the picture to open it full size.</p>`
        : `<div class="gcash-receipt-empty">The customer has not uploaded a receipt yet.</div>`;

    body.innerHTML = `
        ${receipt}

        <dl class="gcash-detail-list">
            <div><dt>Name on GCash account</dt><dd>${order.gcash_sender_name ? escapeHTML(order.gcash_sender_name) : '<em>Not provided</em>'}</dd></div>
            <div><dt>GCash reference number</dt><dd class="gcash-ref">${order.gcash_reference ? escapeHTML(order.gcash_reference) : '<em>Not provided</em>'}</dd></div>
            <div><dt>Amount to receive</dt><dd><strong>\u20B1${Number(order.total_amount || 0).toFixed(2)}</strong></dd></div>
            <div><dt>Customer</dt><dd>${escapeHTML(order.customer_name || 'N/A')}</dd></div>
            <div><dt>Items</dt><dd>${escapeHTML(order.items || 'No items')}</dd></div>
            ${stamp ? `<div><dt>Receipt sent</dt><dd>${stamp}</dd></div>` : ''}
        </dl>

        ${isLate ? '<p class="gcash-voided-note">The normal payment time is over. This is a late payment: only confirm it if the customer really did pay. The order is cancelled automatically when the late-payment window ends.</p>' : ''}

        <div class="gcash-detail-actions">
            <button type="button" class="gcash-detail-close" onclick="closeGcashDetail()">Close</button>
            <button type="button" class="confirm-gcash-btn ${isLate ? 'is-late' : ''}"
                    data-confirm-gcash="${Number(order.id)}"
                    data-label="${isLate ? 'Confirm late payment' : 'Confirm payment received'}">
                ${isLate ? 'Confirm late payment' : 'Confirm payment received'}
            </button>
        </div>
    `;

    modal.hidden = false;
}

document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-gcash-detail]');

    if (button) {
        openGcashDetail(Number(button.dataset.gcashDetail));
        return;
    }

    if (event.target.id === 'closeGcashDetail' || event.target.id === 'closeGcashDetailOverlay') {
        closeGcashDetail();
    }
});

document.addEventListener('keydown', function (event) {
    const modal = document.getElementById('gcashDetailModal');

    // Escape closes the details first (not the whole GCash Orders window).
    if (event.key === 'Escape' && modal && !modal.hidden) {
        event.stopImmediatePropagation();
        closeGcashDetail();
    }
}, true);

/* ---------- Admin: upload / change the GCash QR code ---------- */
(function () {
    const input = document.getElementById('gcashQrFile');
    if (!input) return;

    const status = document.getElementById('gcashQrStatus');
    const thumb = document.getElementById('gcashQrThumb');

    thumb.addEventListener('error', () => {
        status.textContent = 'No QR code yet. Upload your GCash QR so customers can pay.';
        thumb.hidden = true;
    });

    thumb.addEventListener('load', () => { thumb.hidden = false; });

    input.addEventListener('change', async () => {
        const file = input.files[0];
        if (!file) return;

        const body = new FormData();
        body.append('qr', file);
        status.textContent = 'Uploading...';

        try {
            const response = await fetch('upload_gcash_qr.php', { method: 'POST', body });
            const result = await response.json();

            if (!response.ok || !result.success) throw new Error(result.message || 'Upload failed.');

            thumb.src = 'gcash_qr.php?v=' + Date.now();
            status.textContent = 'QR code updated. Customers now see this one.';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            input.value = '';
        }
    });
})();
