<?php
/**
 * Top bar for every customer page (Home, Menu, My Orders, Profile).
 *
 *   logo  |  Home  Menu  My Orders  |  [cart]  [bell]  [profile]
 *
 * The cart icon only shows when a customer is logged in. The bell shows orders that are ready for pick-up; the profile icon opens a
 * small menu (profile, orders, log out). Visitors who are not logged in get
 * Log in / Sign up instead of the two icons.
 *
 * Set $active to "home", "menu", "orders" or "profile" before including.
 * The page's <head> must load css/customer_bar.css; js/customer_bar.js is
 * loaded here.
 */
require_once __DIR__ . "/store_hours.php";

$active = $active ?? "";
$custName = (string) ($_SESSION["full_name"] ?? $_SESSION["username"] ?? "");
$custFirst = explode(" ", trim($custName))[0];
$isCustomer = (($_SESSION["role"] ?? "") === "customer");
$headerStore = store_status();
$cbParts = preg_split('/\s+/', trim($custName)) ?: [];
$cbInitials = strtoupper(mb_substr($cbParts[0] ?? "", 0, 1) . (count($cbParts) > 1 ? mb_substr(end($cbParts), 0, 1) : ""));
$cbEsc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, "UTF-8");

// Real logo: put a file named logo.svg / logo.png / logo.webp in the image folder and it replaces the "D&JC" circle.
$cbLogo = null;
foreach (["logo.svg", "logo.webp", "logo.png"] as $cbFile) {
    if (is_file(__DIR__ . "/../image/" . $cbFile)) { $cbLogo = $cbFile; break; }
}
?>
<header class="cbar" id="customerBar">
    <div class="cbar-inner">
        <a href="landing_page.php" class="cbar-brand" aria-label="D&amp;JC Food Products home">
            <?php if ($cbLogo): ?>
                <img class="cbar-logo" src="../image/<?= $cbEsc($cbLogo) ?>" alt="">
            <?php else: ?>
                <span class="cbar-mark">D&amp;JC</span>
            <?php endif; ?>
            <span class="cbar-brand-text">Food Products</span>
        </a>

        <nav class="cbar-nav" aria-label="Main">
            <a href="landing_page.php" class="cbar-link<?= $active === "home" ? " active" : "" ?>"<?= $active === "home" ? ' aria-current="page"' : "" ?>>Home</a>
            <a class="cbar-link<?= $active === "menu" ? " active" : "" ?>"<?= $active === "menu" ? ' aria-current="page"' : "" ?><?= store_link_attrs($headerStore, "customerorder.php", "Menu", ["keep" => true]) ?>>Menu</a>
            <?php if ($isCustomer): ?>
                <a href="my_orders.php" class="cbar-link<?= $active === "orders" ? " active" : "" ?>"<?= $active === "orders" ? ' aria-current="page"' : "" ?>>My Orders</a>
            <?php endif; ?>
        </nav>

        <div class="cbar-actions">
            <?php if ($active !== "menu"): ?>
                <a class="cbar-btn cbar-btn-solid cbar-order"<?= store_link_attrs($headerStore, "customerorder.php", "Order Now", ["keep" => true]) ?>>Order Now</a>
            <?php endif; ?>
            <?php if ($isCustomer): ?>
                <?php /* The cart is only for logged-in customers. */ ?>
                <a class="cbar-icon-btn cbar-cart" id="cbarCart"<?= store_link_attrs($headerStore, "customerorder.php", "", ["keep" => true]) ?> aria-label="Your cart">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2.5 3.5H5l2.4 11.2a1 1 0 0 0 .8.8h9.2a1 1 0 0 0 1-.8L20.5 8H6"/></svg>
                    <span class="cbar-badge cbar-cart-badge" data-cart-badge hidden>0</span>
                </a>
                <div class="cbar-menu" id="cbarNotify">
                    <button type="button" class="cbar-icon-btn" id="cbarBell" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="cbarBellPanel">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16z"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                        <span class="cbar-badge" data-orders-badge hidden>0</span>
                    </button>
                    <div class="cbar-panel cbar-panel-wide" id="cbarBellPanel" role="region" aria-label="Notifications" hidden>
                        <p class="cbar-panel-title">Notifications</p>
                        <ul class="cbar-notes" id="cbarNotes">
                            <li class="cbar-empty">No new notifications.</li>
                        </ul>
                        <button type="button" class="cbar-panel-btn" id="cbarMarkRead" hidden>Mark all as read</button>
                    </div>
                </div>

                <div class="cbar-menu" id="cbarProfileMenu">
                    <button type="button" class="cbar-icon-btn" id="cbarProfileBtn" aria-label="Account menu for <?= $cbEsc($custName) ?>" aria-haspopup="true" aria-expanded="false" aria-controls="cbarProfilePanel">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    </button>
                    <div class="cbar-panel" id="cbarProfilePanel" hidden>
                        <div class="cbar-who">
                            <span class="cbar-initials" aria-hidden="true"><?= $cbEsc($cbInitials) ?></span>
                            <span>
                                <strong><?= $cbEsc($custName) ?></strong>
                                <small>Customer</small>
                            </span>
                        </div>
                        <a class="cbar-item<?= $active === "profile" ? " active" : "" ?>" href="profile.php">My Profile</a>
                        <a class="cbar-item" href="my_orders.php">My Orders</a>
                        <a class="cbar-item cbar-logout" href="logout.php">Log out</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="landing_page.php?auth=login" class="cbar-btn cbar-btn-text" data-auth-open="login">Log in</a>
                <a href="landing_page.php?auth=register" class="cbar-btn cbar-btn-outline" data-auth-open="register">Sign up</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php include_once __DIR__ . "/store_gate.php"; ?>
<script src="../js/customer_bar.js?v=r4" defer></script>
