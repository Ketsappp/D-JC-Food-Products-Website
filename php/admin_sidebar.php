<?php
/**
 * Shared admin sidebar. Replaces the copy that was pasted into every admin page.
 *
 * Usage (inside <body>, in place of the old <aside class="sidebar">…</aside>):
 *     <?php $adminActive = 'inventory'; include __DIR__ . '/admin_sidebar.php'; ?>
 *
 * $adminActive: sales | inventory | menu | log | hours | staff | customers
 * Admins see every page. Staff see only: Menu (the POS), Inventory (view only) and
 * Sales today (today's records only). Used by the POS page (order.php) too.
 * Element ids (#logoutBtn, #loggedInName, #loggedInRole) and the existing class
 * names are unchanged, so logout.js, sidebar.js and admin_notify.js keep working.
 */
$__isAdmin = (($_SESSION['role'] ?? '') === 'admin');
// Admin and staff both see the low-stock badge on Inventory.
if (!isset($lowStockCount) && isset($conn)) {
    require_once __DIR__ . '/admin_notification_counts.php';
}
$lowStockCount = (int)($lowStockCount ?? 0);
$adminActive   = $adminActive ?? '';

$__name     = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
$__role     = ucfirst((string)($_SESSION['role'] ?? 'User'));
$__parts    = preg_split('/\s+/', trim($__name)) ?: [];
$__initials = strtoupper(mb_substr($__parts[0] ?? 'U', 0, 1) . (count($__parts) > 1 ? mb_substr(end($__parts), 0, 1) : ''));

$__icons = [
    'sales'     => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'inventory' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'menu'      => '<path d="M5 8h12v6a5 5 0 0 1-5 5h-2a5 5 0 0 1-5-5V8z"/><path d="M17 10h1.5a2.5 2.5 0 0 1 0 5H17"/><path d="M8 3v2M12 3v2"/>',
    'log'       => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h6"/>',
    'staff'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6 6 0 0 1 3.5 6"/>',
    'hours'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'customers' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l5-5-5-5"/><path d="M15 12H4"/>',
];
$__nav = [
    'Store'  => [
        ['sales',     'sales_report.php', 'Sales report'],
        ['inventory', 'inventory.php',    'Inventory'],
        ['menu',      'order.php',        'Menu'],
        ['log',       'stock_log.php',    'Stock log'],
        ['hours',     'store_hours_admin.php', 'Store hours'],
    ],
    'People' => [
        ['staff',     'staff.php',        'Staff'],
        ['customers', 'customers.php',    'Customers'],
    ],
];
// Staff get three pages: Menu (POS), Inventory (view only) and Sales today.
// Staff, Customers and the Stock log stay admin-only.
if (!$__isAdmin) {
    $__byKey = [];
    foreach ($__nav['Store'] as $__item) {
        $__byKey[$__item[0]] = $__item;
    }
    $__byKey['sales'][2] = 'Sales today';
    $__nav = ['Store' => [$__byKey['menu'], $__byKey['inventory'], $__byKey['sales']]];
}
$__home = $__isAdmin ? 'sales_report.php' : 'order.php';
$__svg = fn(string $k) => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $__icons[$k] . '</svg>';
?>
<?php /* Phone layout (<= 768px): sticky top bar + hamburger; the sidebar below becomes a slide-in drawer. Hidden on desktop by css/admin-drawer.css. */ ?>
<header class="mobile-topbar">
    <button type="button" class="drawer-toggle" id="drawerToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <a class="mobile-topbar-brand" href="../php/<?= $__home ?>">
        <span class="brand-mark" aria-hidden="true">D&amp;J</span>
        <span class="mobile-topbar-title">D&amp;JC</span>
    </a>
</header>
<div class="drawer-scrim" id="drawerScrim" aria-hidden="true"></div>

<aside class="sidebar" id="adminSidebar">
    <button type="button" class="drawer-close" id="drawerClose" aria-label="Close menu">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <div class="sidebar-top">
        <a class="brand" href="../php/<?= $__home ?>">
            <span class="brand-mark" aria-hidden="true">D&amp;J</span>
            <span>
                <span class="brand-name">D&amp;JC</span>
                <span class="brand-sub">Food Products · <?= $__isAdmin ? 'Admin' : 'Staff' ?></span>
            </span>
        </a>

        <nav class="nav-menu" aria-label="Admin">
            <?php foreach ($__nav as $__title => $__items): ?>
                <div class="nav-group">
                    <p class="nav-group-title"><?= htmlspecialchars($__title) ?></p>
                    <?php foreach ($__items as [$__key, $__file, $__label]): ?>
                        <a href="../php/<?= $__file ?>"
                           class="nav-item<?= $adminActive === $__key ? ' active' : '' ?>"
                           <?= $adminActive === $__key ? 'aria-current="page"' : '' ?>>
                            <span class="nav-icon"><?= $__svg($__key) ?></span>
                            <span class="nav-label"><?= $__label ?></span>
                            <?php if ($__key === 'inventory' && $lowStockCount > 0): ?>
                                <span class="notification-badge" title="Low-stock items"><?= $lowStockCount ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="sidebar-bottom">
        <div class="profile">
            <span class="profile-initials" aria-hidden="true"><?= htmlspecialchars($__initials, ENT_QUOTES, 'UTF-8') ?></span>
            <div>
                <p class="profile-name" id="loggedInName"><?= htmlspecialchars($__name, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="profile-role" id="loggedInRole"><?= htmlspecialchars($__role, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
        <button type="button" id="logoutBtn" class="nav-item logout-item">
            <span class="nav-icon"><?= $__svg('logout') ?></span><span class="nav-label">Log out</span>
        </button>
    </div>
</aside>
