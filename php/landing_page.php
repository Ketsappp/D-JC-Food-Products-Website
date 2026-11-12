<?php
/**
 * Customer landing page.
 *
 * The first thing a customer sees is the BEST SELLERS carousel. It is built
 * from the same data as the Sales Report: completed orders (status = 'Done')
 * in order_items, grouped by product name. Add-ons / size labels in the
 * order line, e.g. "Big Pizza (Regular) + Take Out", are stripped so every
 * variation counts toward the base product.
 *
 * Below it, a "sneak peek" of the menu shows PEEK_LIMIT items.
 *
 * If fewer than BEST_SELLER_LIMIT menu products have sales yet, the section
 * is filled with other available menu items labelled "Featured".
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/menu_recipe_helpers.php";
require_once __DIR__ . "/feedback_helpers.php";
require_once __DIR__ . "/user_status.php";
require_once __DIR__ . "/store_hours.php";
require_once __DIR__ . "/store_info.php";

// A blocked customer is signed out right away.
drop_session_if_customer_blocked($conn);

// Open or closed right now? While closed, every link to the menu below turns
// into a "We're closed" message (see store_link_attrs() in store_hours.php).
$storeStatus = store_status();

// Login and Create an Account are a pop-up on this page. This handles their
// forms (and redirects after a successful login / sign-up) before any output.
require_once __DIR__ . "/auth_process.php";
$auth = auth_process($conn);

const BEST_SELLER_LIMIT = 6;
const PEEK_LIMIT = 8;
const FEEDBACK_SHOW_LIMIT = 9;
const BEST_BADGE_LIMIT = 3;       // how many top sellers get the "Best Seller" tag on the cards
const NEW_BADGE_DAYS = 14;        // products added in the last N days get the "New" tag
// Where "Order Now" goes. Change this if your customer ordering page moves.
const ORDER_URL = "customerorder.php";

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}

function category_emoji(string $category, string $name): string
{
    // Inline SVG icons (name kept so existing calls still work). Emoji look different on every device.
    $open   = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
    $pizza  = $open . '<path d="M12 21 3.5 6.2a15 15 0 0 1 17 0L12 21z"/><circle cx="10" cy="9" r="1"/><circle cx="14" cy="11" r="1"/><circle cx="12" cy="14.5" r=".9"/></svg>';
    $coffee = $open . '<path d="M5 8h12v6a5 5 0 0 1-5 5h-2a5 5 0 0 1-5-5V8z"/><path d="M17 10h1.5a2.5 2.5 0 0 1 0 5H17"/><path d="M8 3v2M12 3v2"/></svg>';
    $drink  = $open . '<path d="M7 7h10l-1.2 13a1 1 0 0 1-1 .9H9.2a1 1 0 0 1-1-.9L7 7z"/><path d="M6 7h12"/><path d="M13 7l1.5-4H17"/></svg>';
    $text = strtolower($category . " " . $name);
    if (str_contains($text, "pizza")) return $pizza;
    if (str_contains($text, "coffee") || str_contains($text, "latte") || str_contains($text, "macchiato")) return $coffee;
    return $drink;
}

/**
 * Colour family of a category (used as a CSS class: cat-pizza, cat-shake ...).
 * It is picked from the category / product name, so new categories work too.
 */
function category_theme(string $category, string $name = ""): string
{
    $text = strtolower($category . " " . $name);
    if (str_contains($text, "pizza")) return "pizza";
    if (str_contains($text, "shake")) return "shake";
    if (str_contains($text, "soda") || str_contains($text, "fruit")) return "soda";
    if (str_contains($text, "mango")) return "mango";
    if (str_contains($text, "coffee") || str_contains($text, "latte") || str_contains($text, "macchiato") || str_contains($text, "mocha")) return "coffee";
    if (str_contains($text, "tea")) return "milktea";
    return "default";
}

/** One friendly line for products that have no description yet. */
function category_blurb(string $theme): string
{
    $lines = [
        "pizza"   => "Hot, cheesy and baked to order.",
        "shake"   => "Thick, creamy and blended fresh.",
        "soda"    => "Fizzy, fruity and ice cold.",
        "mango"   => "Sweet mango goodness, made to order.",
        "coffee"  => "Rich, smooth and freshly made.",
        "milktea" => "Freshly brewed milk tea, your way.",
    ];
    return $lines[$theme] ?? "Freshly made to order.";
}

/** "S · M · L" for the sizes a product is sold in (empty when it has only one price). */
function sizes_label(array $p): string
{
    $out = [];
    if ((float) $p["small_price"] > 0)  $out[] = "S";
    if ((float) $p["medium_price"] > 0) $out[] = "M";
    if ((float) $p["large_price"] > 0)  $out[] = "L";
    return count($out) >= 2 ? implode(" · ", $out) : "";
}

function price_label(array $p): string
{
    $sizes = array_filter([
        (float) $p["small_price"], (float) $p["medium_price"], (float) $p["large_price"],
    ], fn($v) => $v > 0);

    if ($sizes) {
        return "From ₱" . number_format(min($sizes), 0);
    }

    return "₱" . number_format((float) $p["selling_price"], 0);
}

$featured = [];
$bestKeys = [];
$catThumb = [];
$categories = [];
$peek = [];
$allItems = [];
$loadError = false;

try {
    ensure_menu_size_schema($conn);

    // 1. Active menu products (what customers can actually see/order).
    $menu = [];
    $res = $conn->query(
        "SELECT id, product_name, image, category, quantity, unit, selling_price,
                small_price, medium_price, large_price, description, created_at
         FROM menu_inventory
         WHERE is_available = 1 AND is_archived = 0
         ORDER BY id DESC"
    );
    while ($row = $res->fetch_assoc()) {
        $menu[] = $row;
    }

    $availability = $menu ? get_menu_availability($conn, $menu) : [];

    // One card per product name (the menu can contain duplicates).
    $byName = [];
    foreach ($menu as $p) {
        $key = mb_strtolower(trim($p["product_name"]));
        if (!isset($byName[$key])) {
            $p["orderable"] = (bool) ($availability[(int) $p["id"]]["orderable"] ?? false);
            $byName[$key] = $p;
            $categories[$p["category"]] = ($categories[$p["category"]] ?? 0) + 1;
        }
    }

    // 2. Units sold per base product, from completed orders (Sales Report data).
    $sold = $conn->query(
        "SELECT TRIM(SUBSTRING_INDEX(oi.product_name, ' (', 1)) AS base_name,
                SUM(oi.quantity) AS units
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'Done'
         GROUP BY base_name
         ORDER BY units DESC"
    );

    while ($row = $sold->fetch_assoc()) {
        $key = mb_strtolower($row["base_name"]);
        if (isset($byName[$key]) && count($featured) < BEST_SELLER_LIMIT) {
            $item = $byName[$key];
            $item["units"] = (int) $row["units"];
            $item["is_best"] = true;
            $featured[$key] = $item;
        }
    }

    // 3. Not enough sales yet? Top up with other menu items.
    foreach ($byName as $key => $item) {
        if (count($featured) >= BEST_SELLER_LIMIT) break;
        if (!isset($featured[$key])) {
            $item["units"] = 0;
            $item["is_best"] = false;
            $featured[$key] = $item;
        }
    }

    // 4. Sneak peek of the menu: up to PEEK_LIMIT items, taken in turns from
    //    each category so every kind of product shows up.
    $groups = [];
    foreach ($byName as $item) {
        $groups[$item["category"]][] = $item;
    }
    $allItems = array_values($byName);
    while (count($peek) < PEEK_LIMIT && $groups) {
        foreach ($groups as $cat => &$list) {
            if (count($peek) >= PEEK_LIMIT) break;
            $peek[] = array_shift($list);
            if (!$list) unset($groups[$cat]);
        }
        unset($list);
    }

    // Keep sold-out items last, otherwise keep best-seller order.
    $featured = array_values($featured);
    usort($featured, fn($a, $b) => ($b["orderable"] <=> $a["orderable"]));

    // Badges on the menu cards: the top sellers, and products added recently.
    foreach (array_slice(array_keys($featured), 0, BEST_BADGE_LIMIT) as $rank) {
        if (!empty($featured[$rank]["is_best"])) {
            $bestKeys[mb_strtolower(trim($featured[$rank]["product_name"]))] = true;
        }
    }

    // The first photo of each category becomes the little picture on its filter button.
    foreach ($byName as $item) {
        $file = basename((string) $item["image"]);
        if ($file !== "" && !isset($catThumb[$item["category"]]) && is_file(__DIR__ . "/../uploads/products/" . $file)) {
            $catThumb[$item["category"]] = $file;
        }
    }
} catch (Throwable $e) {
    error_log("landing_page.php: " . $e->getMessage());
    $loadError = true;
}

// Customer feedback (public): newest first, plus the average rating.
$feedbackList = [];
$feedbackCount = 0;
$feedbackAvg = 0.0;
$feedbackDist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$myFeedback = null;

/** A soft colour for a customer's initial, always the same for the same name. */
function avatar_hue(string $name): int
{
    return abs(crc32(mb_strtolower($name))) % 360;
}

try {
    ensure_feedback_schema($conn);

    $stats = $conn->query("SELECT COUNT(*) AS c, AVG(f.rating) AS a FROM feedback f INNER JOIN users u ON u.id = f.user_id WHERE f.is_visible = 1 AND u.is_active = 1")->fetch_assoc();
    $feedbackCount = (int) ($stats["c"] ?? 0);
    $feedbackAvg = (float) ($stats["a"] ?? 0);

    // How many reviews gave 5, 4, 3, 2, 1 stars (for the bars next to the average).
    $dist = $conn->query("SELECT f.rating, COUNT(*) AS c FROM feedback f INNER JOIN users u ON u.id = f.user_id WHERE f.is_visible = 1 AND u.is_active = 1 GROUP BY f.rating");
    while ($row = $dist->fetch_assoc()) {
        $r = (int) $row["rating"];
        if ($r >= 1 && $r <= 5) $feedbackDist[$r] = (int) $row["c"];
    }

    $res = $conn->query(
        "SELECT f.id, f.user_id, f.rating, f.comment, f.created_at, u.full_name
         FROM feedback f INNER JOIN users u ON u.id = f.user_id
         WHERE f.is_visible = 1 AND u.is_active = 1
         ORDER BY f.created_at DESC, f.id DESC
         LIMIT " . FEEDBACK_SHOW_LIMIT
    );
    while ($row = $res->fetch_assoc()) {
        $feedbackList[] = $row;
    }

    $myId = feedback_customer_id();
    if ($myId !== null) {
        $stmt = $conn->prepare("SELECT rating, comment FROM feedback WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $myId);
        $stmt->execute();
        $myFeedback = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log("landing_page.php feedback: " . $e->getMessage());
}

$isCustomer = feedback_customer_id() !== null;
$loggedIn = !empty($_SESSION["user_id"]);
$firstName = $loggedIn
    ? explode(" ", trim($_SESSION["full_name"] ?? $_SESSION["username"] ?? ""))[0]
    : "";
$hasRealBest = $featured && !empty($featured[0]["is_best"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>D&amp;JC Food Products - Milktea, Milkshake, Fruit Soda &amp; Pizza</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>document.documentElement.classList.add("js");</script>
<link rel="stylesheet" href="../css/landing.css?v=r4">
<link rel="stylesheet" href="../css/categories.css?v=r3">
<link rel="stylesheet" href="../css/customer.css?v=r3">
<link rel="stylesheet" href="../css/auth_modal.css?v=gt4">
<link rel="stylesheet" href="../css/customer_bar.css?v=r5">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>

<?php $active = "home"; include __DIR__ . "/customer_header.php"; ?>

<main>

<!-- ===== Best sellers carousel: the first thing customers see ===== -->
<section class="hero" id="best-sellers" aria-labelledby="heroTitle">
    <h1 id="heroTitle" class="sr-only">D&amp;JC Food Products &mdash; <?= $hasRealBest ? "Best Sellers" : "Featured Items" ?></h1>

    <?php if ($loadError): ?>
        <div class="wrap"><p class="empty">We couldn&rsquo;t load our featured items right now. Please try again in a moment.</p></div>
    <?php elseif (!$featured): ?>
        <div class="wrap"><p class="empty">Our menu is being updated &mdash; check back soon!</p></div>
    <?php else:
        $total = count($featured);
    ?>
    <div class="carousel" id="carousel" role="region" aria-roledescription="carousel"
         aria-label="<?= $hasRealBest ? "Best sellers" : "Featured items" ?>" data-count="<?= $total ?>">

        <div class="slides" id="slides">
        <?php foreach ($featured as $i => $p):
            $imgFile = basename((string) $p["image"]);
            $hasImg = $imgFile !== "" && is_file(__DIR__ . "/../uploads/products/" . $imgFile);
            $desc = trim((string) $p["description"]);
        ?>
            <article class="slide theme-<?= $i % 4 ?><?= $i === 0 ? " is-active" : "" ?><?= $p["orderable"] ? "" : " soldout" ?>"
                     role="group" aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= $total ?>"
                     <?= $i === 0 ? "" : 'aria-hidden="true"' ?>>
                <div class="wrap slide-inner">
                    <div class="slide-copy">
                        <p class="slide-badge">
                            <?php if ($p["is_best"]): ?>
                                <span class="rank">#<?= $i + 1 ?></span> Best Seller
                            <?php else: ?>
                                &#9733; Featured
                            <?php endif; ?>
                        </p>
                        <h2 class="slide-title"><?= h($p["product_name"]) ?></h2>
                        <p class="slide-cat"><?= h($p["category"]) ?></p>
                        <?php if ($desc !== ""): ?>
                            <p class="slide-desc"><?= h($desc) ?></p>
                        <?php endif; ?>

                        <div class="slide-meta">
                            <span class="slide-price"><?= h(price_label($p)) ?></span>
                            <?php if ($p["is_best"] && $p["units"] > 0): ?>
                                <span class="slide-sold"><?= number_format($p["units"]) ?> sold</span>
                            <?php endif; ?>
                        </div>

                        <div class="slide-actions">
                            <?php if ($p["orderable"]): ?>
                                <a class="btn-order"<?= store_link_attrs($storeStatus, ORDER_URL . "?category=" . rawurlencode($p["category"]) . "&add=" . (int) $p["id"], "Order Now") ?> <?= $i === 0 ? "" : 'tabindex="-1"' ?>><?= store_link_label($storeStatus, "Order Now") ?></a>
                            <?php else: ?>
                                <span class="btn-order is-disabled">Sold out for now</span>
                            <?php endif; ?>
                            <a class="link-more"<?= store_link_attrs($storeStatus, ORDER_URL, "", ["hide" => true, "keep" => true]) ?> <?= $i === 0 ? "" : 'tabindex="-1"' ?>>See full menu &rarr;</a>
                        </div>
                    </div>

                    <div class="slide-art" aria-hidden="<?= $hasImg ? "false" : "true" ?>">
                        <span class="plate"></span>
                        <?php if ($hasImg): ?>
                            <img src="../uploads/products/<?= h(rawurlencode($imgFile)) ?>" draggable="false"
                                 alt="<?= h($p["product_name"]) ?>" loading="<?= $i === 0 ? "eager" : "lazy" ?>">
                        <?php else: ?>
                            <span class="art-emoji"><?= category_emoji($p["category"], $p["product_name"]) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        </div>

        <?php if ($total > 1): ?>
        <div class="car-footer">
            <div class="dots" id="dots" role="tablist" aria-label="Choose a slide">
                <?php foreach ($featured as $i => $p): ?>
                    <button type="button" class="dot<?= $i === 0 ? " is-active" : "" ?>" role="tab"
                            aria-label="<?= h($p["product_name"]) ?>" aria-selected="<?= $i === 0 ? "true" : "false" ?>"
                            data-index="<?= $i ?>"><span class="dot-fill"></span></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<!-- ===== Sneak peek of the menu ===== -->
<?php if ($allItems): ?>
<?php $peekIds = array_map(fn($p) => (int) $p["id"], $peek); ?>
<section class="peek reveal" id="menu-peek" aria-labelledby="peekTitle">
    <div class="wrap">
        <div class="peek-head">
            <p class="eyebrow">Sneak peek</p>
            <h2 id="peekTitle" class="band-title">A taste of our menu</h2>
            <p class="lede">Milktea, milkshakes, fruit soda and pizza &mdash; here&rsquo;s a little of what&rsquo;s waiting for you.</p>
            <div class="chips" id="peekFilters" role="group" aria-label="Filter the menu by category">
                <button type="button" class="chip is-active" data-filter="all" aria-pressed="true">
                    <span class="chip-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/></svg></span><span>All</span>
                </button>
                <?php foreach ($categories as $name => $count): ?>
                    <button type="button" class="chip cat-<?= h(category_theme($name)) ?>" data-filter="<?= h($name) ?>" aria-pressed="false">
                        <?php if (isset($catThumb[$name])): ?>
                            <span class="chip-ico chip-photo" aria-hidden="true"><img src="../uploads/products/<?= h(rawurlencode($catThumb[$name])) ?>" alt="" loading="lazy"></span>
                        <?php else: ?>
                            <span class="chip-ico" aria-hidden="true"><?= category_emoji($name, "") ?></span>
                        <?php endif; ?>
                        <span><?= h($name) ?></span>
                        <small><?= (int) $count ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="peek-grid">
            <?php foreach ($allItems as $i => $p):
                $imgFile = basename((string) $p["image"]);
                $hasImg = $imgFile !== "" && is_file(__DIR__ . "/../uploads/products/" . $imgFile);
                $theme = category_theme($p["category"], $p["product_name"]);
                $nameKey = mb_strtolower(trim($p["product_name"]));
                $isBest = isset($bestKeys[$nameKey]);
                $isNew = !empty($p["created_at"]) && (time() - strtotime($p["created_at"])) <= NEW_BADGE_DAYS * 86400;
                $desc = trim((string) $p["description"]);
                if ($desc === "") $desc = category_blurb($theme);
                $sizes = sizes_label($p);
            ?>
            <article class="card cat-<?= h($theme) ?><?= $p["orderable"] ? "" : " soldout" ?>"
                     data-category="<?= h($p["category"]) ?>" data-default="<?= in_array((int) $p["id"], $peekIds, true) ? "1" : "0" ?>"
                     style="--i: <?= $i % 8 ?>">
                <div class="card-media">
                    <?php if ($hasImg): ?>
                        <img src="../uploads/products/<?= h(rawurlencode($imgFile)) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="card-emoji" aria-hidden="true"><?= category_emoji($p["category"], $p["product_name"]) ?></span>
                    <?php endif; ?>
                    <?php if ($p["orderable"] && ($isBest || $isNew)): ?>
                        <span class="card-tags">
                            <?php if ($isBest): ?><span class="tag tag-best">&#9733; Best Seller</span><?php endif; ?>
                            <?php if ($isNew): ?><span class="tag tag-new">New</span><?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!$p["orderable"]): ?><span class="soldout-tag">Sold out</span><?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="card-cat"><?= h($p["category"]) ?></p>
                    <h3 class="card-title">
                        <a class="card-link"<?= store_link_attrs($storeStatus, ORDER_URL . "?category=" . rawurlencode($p["category"]) . ($p["orderable"] ? "&add=" . (int) $p["id"] : ""), "", ["keep" => true]) ?>
                           aria-label="<?= h($p["product_name"]) ?>, <?= h(price_label($p)) ?><?= $p["orderable"] ? "" : ", sold out" ?>"><?= h($p["product_name"]) ?></a>
                    </h3>
                    <p class="card-desc"><?= h($desc) ?></p>
                    <div class="card-foot">
                        <div class="card-price">
                            <span class="price"><?= h(price_label($p)) ?></span>
                            <?php if ($sizes !== ""): ?><span class="card-sizes" title="Available sizes"><?= h($sizes) ?></span><?php endif; ?>
                        </div>
                        <?php if ($p["orderable"]): ?>
                            <span class="card-add" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <div class="cta-row">
            <a class="btn-order" id="peekMore"<?= store_link_attrs($storeStatus, ORDER_URL, "See the Full Menu") ?>><?= store_link_label($storeStatus, "See the Full Menu") ?></a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== How it works ===== -->
<section class="perks reveal" id="how-it-works" aria-labelledby="howTitle">
    <div class="wrap">
        <div class="peek-head">
            <p class="eyebrow">Easy as 1-2-3</p>
            <h2 id="howTitle" class="band-title">How ordering works</h2>
        </div>
        <ol class="steps">
            <li class="step" style="--i: 0">
                <span class="step-n" aria-hidden="true">1</span>
                <span class="step-ico step-ico-1" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 7h10l-1.2 13a1 1 0 0 1-1 .9H9.2a1 1 0 0 1-1-.9L7 7z"/><path d="M6 7h12"/><path d="M13 7l1.5-4H17"/></svg>
                </span>
                <h3>Pick your favorites</h3>
                <p>Browse milktea, milkshakes, fruit soda and pizza, then choose your size and add-ons.</p>
            </li>
            <li class="step" style="--i: 1">
                <span class="step-n" aria-hidden="true">2</span>
                <span class="step-ico step-ico-2" aria-hidden="true">
                    <img src="../image/GCash_logo.svg" alt="" loading="lazy">
                </span>
                <h3>Pay with GCash</h3>
                <p>Scan the GCash QR code to pay. Your order is confirmed once the payment is verified.</p>
            </li>
            <li class="step" style="--i: 2">
                <span class="step-n" aria-hidden="true">3</span>
                <span class="step-ico step-ico-3" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><path d="M9.5 14.2l1.8 1.8 3.4-3.6"/></svg>
                </span>
                <h3>Get notified, then pick up</h3>
                <p>We prepare your order fresh and notify you as soon as it&rsquo;s ready for pick-up.</p>
            </li>
        </ol>
        <p class="gcash-note"><strong>GCash only.</strong> Verified payments help us keep out fake orders.</p>
    </div>
</section>

<!-- ===== Customer feedback ===== -->
<section class="feedback reveal" id="feedback" aria-labelledby="feedbackTitle">
    <div class="wrap">
        <div class="peek-head">
            <p class="eyebrow">Customer feedback</p>
            <h2 id="feedbackTitle" class="band-title">What our customers say</h2>
            <?php if ($feedbackCount === 0): ?>
                <p class="lede">Be the first to share your experience with D&amp;JC.</p>
            <?php endif; ?>
        </div>

        <?php if ($feedbackCount > 0):
            $avgRounded = (int) round($feedbackAvg);
        ?>
        <div class="fb-overview">
            <div class="fb-score">
                <p class="fb-big"><?= number_format($feedbackAvg, 1) ?></p>
                <div class="fb-stars" role="img" aria-label="<?= number_format($feedbackAvg, 1) ?> out of 5 stars"><?= str_repeat("★", $avgRounded) ?><?= str_repeat("☆", 5 - $avgRounded) ?></div>
                <p class="fb-count"><?= $feedbackCount ?> review<?= $feedbackCount === 1 ? "" : "s" ?></p>
            </div>
            <ul class="fb-bars" aria-label="Rating breakdown">
                <?php foreach ([5, 4, 3, 2, 1] as $star):
                    $c = $feedbackDist[$star] ?? 0;
                    $pct = $feedbackCount > 0 ? round($c / $feedbackCount * 100) : 0;
                ?>
                <li>
                    <span class="fb-bar-label"><?= $star ?> <span aria-hidden="true">★</span></span>
                    <span class="fb-bar" role="img" aria-label="<?= $c ?> of <?= $feedbackCount ?> reviews gave <?= $star ?> star<?= $star === 1 ? "" : "s" ?>"><span style="width: <?= $pct ?>%"></span></span>
                    <span class="fb-bar-n"><?= $c ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($feedbackList): ?>
        <div class="fb-scroller">
            <button type="button" class="fb-nav fb-prev" aria-label="Previous reviews" hidden>&lsaquo;</button>
            <div class="fb-track" id="fbTrack" tabindex="0" role="region" aria-label="Customer reviews">
            <?php foreach ($feedbackList as $fb):
                $shown = feedback_display_name($fb["full_name"]);
                $mine = $isCustomer && (int) $fb["user_id"] === feedback_customer_id();
            ?>
            <article class="fb-card<?= $mine ? " is-mine" : "" ?>">
                <header class="fb-head">
                    <span class="fb-avatar" aria-hidden="true" style="--h: <?= avatar_hue($shown) ?>"><?= h(mb_strtoupper(mb_substr($shown, 0, 1))) ?></span>
                    <span class="fb-who">
                        <span class="fb-name"><?= h($shown) ?><?= $mine ? " (you)" : "" ?></span>
                        <time datetime="<?= h($fb["created_at"]) ?>"><?= h(date("M j, Y", strtotime($fb["created_at"]))) ?></time>
                    </span>
                </header>
                <div class="fb-stars" role="img" aria-label="<?= (int) $fb["rating"] ?> out of 5 stars">
                    <?= str_repeat("★", (int) $fb["rating"]) ?><?= str_repeat("☆", 5 - (int) $fb["rating"]) ?>
                </div>
                <p class="fb-text"><?= nl2br(h($fb["comment"])) ?></p>
            </article>
            <?php endforeach; ?>
            </div>
            <button type="button" class="fb-nav fb-next" aria-label="More reviews" hidden>&rsaquo;</button>
        </div>
        <?php endif; ?>

        <div class="fb-form-wrap">
            <?php if ($isCustomer): ?>
            <form id="feedbackForm" class="fb-form" data-token="<?= h(feedback_token()) ?>" novalidate>
                <h3><?= $myFeedback ? "Update your feedback" : "Share your feedback" ?></h3>

                <div class="fb-rate" role="radiogroup" aria-label="Your rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" name="rating" id="rate<?= $i ?>" value="<?= $i ?>" <?= $myFeedback && (int) $myFeedback["rating"] === $i ? "checked" : "" ?>>
                        <label for="rate<?= $i ?>" title="<?= $i ?> star<?= $i === 1 ? "" : "s" ?>"><span aria-hidden="true">★</span><span class="sr-only"><?= $i ?> star<?= $i === 1 ? "" : "s" ?></span></label>
                    <?php endfor; ?>
                </div>

                <label class="sr-only" for="fbComment">Your feedback</label>
                <textarea id="fbComment" name="comment" rows="4" maxlength="<?= FEEDBACK_MAX_LENGTH ?>"
                          placeholder="Tell us what you liked or how we can improve..."><?= h($myFeedback["comment"] ?? "") ?></textarea>
                <div class="fb-meta"><span id="fbCount">0</span>/<?= FEEDBACK_MAX_LENGTH ?></div>

                <p class="fb-note">Your feedback, with your first name and last initial, will be visible to other customers.</p>
                <p class="fb-msg" id="fbMsg" role="status" hidden></p>

                <div class="fb-actions">
                    <button type="submit" class="btn-order">Submit Feedback</button>
                    <?php if ($myFeedback): ?>
                        <button type="button" class="fb-delete" id="fbDelete">Delete mine</button>
                    <?php endif; ?>
                </div>
            </form>
            <?php elseif ($loggedIn): ?>
                <p class="fb-login">Only customer accounts can leave feedback.</p>
            <?php else: ?>
                <p class="fb-login">
                    <a href="landing_page.php?auth=login" class="fb-link" data-auth-open="login">Log in</a>
                    or <a href="landing_page.php?auth=register" class="fb-link" data-auth-open="register">create an account</a> to leave your feedback.
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!$loggedIn):
    // Drop a photo named storefront.jpg / .png / .webp into the image folder and it appears here.
    $storefront = null;
    foreach (["storefront.webp", "storefront.jpg", "storefront.jpeg", "storefront.png"] as $f) {
        if (is_file(__DIR__ . "/../image/" . $f)) { $storefront = $f; break; }
    }
?>
<section class="join reveal">
    <div class="wrap join-box<?= $storefront ? " has-photo" : "" ?>">
        <div class="join-copy">
            <h2>Create your free account</h2>
            <p>Sign up in a minute to order faster, track your pick-up and keep your favorites close.</p>
            <a href="landing_page.php?auth=register" class="btn-order btn-light" data-auth-open="register">Create an Account</a>
        </div>
        <div class="join-art" aria-hidden="true">
            <?php if ($storefront): ?>
                <img src="../image/<?= h($storefront) ?>" alt="" loading="lazy">
            <?php else: ?>
                <span class="join-bubble jb-1"><?= category_emoji("", "Milktea") ?></span>
                <span class="join-bubble jb-2"><?= category_emoji("pizza", "Pizza") ?></span>
                <span class="join-bubble jb-3"><?= category_emoji("", "Soda") ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

</main>

<?php
// Footer: opening hours come from store_hours.php, the rest from store_info.php.
$hoursRows = [];
foreach (explode(" · ", store_hours_summary()) as $row) {
    $bits = explode(" ", $row, 2);
    $hoursRows[] = ["days" => $bits[0], "time" => $bits[1] ?? ""];
}
$hasPhone = STORE_PHONE !== "";
$telHref = preg_replace('/[^0-9+]/', "", STORE_PHONE);
?>
<footer class="footer" id="contact">
    <div class="wrap">
        <div class="foot-grid">
            <div class="foot-col foot-brand">
                <a href="landing_page.php" class="foot-logo" aria-label="D&amp;JC Food Products home">
                    <span class="foot-mark">D&amp;JC</span>
                    <span>Food Products</span>
                </a>
                <p>Milktea, milkshake, fruit soda and pizza &mdash; made fresh and ready for pick-up.</p>
                <p class="foot-gcash"><img src="../image/GCash_logo.svg" alt="GCash" loading="lazy"> GCash payments only</p>
            </div>

            <div class="foot-col">
                <h3>Opening hours</h3>
                <p class="foot-status <?= $storeStatus["open"] ? "is-open" : "is-closed" ?>" data-foot-status>
                    <span class="foot-dot" aria-hidden="true"></span><?= h($storeStatus["title"]) ?>
                </p>
                <ul class="foot-hours">
                    <?php foreach ($hoursRows as $row): ?>
                        <li><span><?= h($row["days"]) ?></span><span><?= h($row["time"]) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="foot-col">
                <h3>Visit &amp; contact</h3>
                <ul class="foot-list">
                    <?php if (STORE_ADDRESS !== ""): ?>
                        <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><span><?= h(STORE_ADDRESS) ?><?php if (STORE_MAP_URL !== ""): ?> <a href="<?= h(STORE_MAP_URL) ?>" target="_blank" rel="noopener">Open in Maps</a><?php endif; ?></span></li>
                    <?php endif; ?>
                    <?php if ($hasPhone): ?>
                        <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg><span><a href="tel:<?= h($telHref) ?>"><?= h(STORE_PHONE) ?></a></span></li>
                    <?php endif; ?>
                    <?php if (STORE_EMAIL !== ""): ?>
                        <li><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg><span><a href="mailto:<?= h(STORE_EMAIL) ?>"><?= h(STORE_EMAIL) ?></a></span></li>
                    <?php endif; ?>
                    <?php if (STORE_FACEBOOK_URL !== ""): ?>
                        <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v6h4v-6h3l1-4h-4V8a1 1 0 0 1 1-1z"/></svg><span><a href="<?= h(STORE_FACEBOOK_URL) ?>" target="_blank" rel="noopener">Follow us on Facebook</a></span></li>
                    <?php endif; ?>
                </ul>
                <?php if (STORE_PICKUP_NOTE !== ""): ?><p class="foot-note"><?= h(STORE_PICKUP_NOTE) ?></p><?php endif; ?>
            </div>

            <div class="foot-col">
                <h3>Quick links</h3>
                <ul class="foot-links">
                    <li><a href="#menu-peek">Menu preview</a></li>
                    <li><a href="#how-it-works">How ordering works</a></li>
                    <li><a href="#feedback">Customer feedback</a></li>
                    <?php if ($isCustomer): ?>
                        <li><a href="my_orders.php">My Orders</a></li>
                    <?php elseif (!$loggedIn): ?>
                        <li><a href="landing_page.php?auth=login" data-auth-open="login">Log in</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="foot-bottom">
            <span>&copy; <?= date("Y") ?> D&amp;JC Food Products</span>
            <span>Milktea &middot; Milkshake &middot; Fruit Soda &middot; Pizza</span>
        </div>
    </div>
</footer>

<?php include __DIR__ . "/auth_modal.php"; ?>

<?php if ($loggedIn && ($_SESSION["role"] ?? "") === "customer"): ?>
<script src="../js/customer_notify.js?v=gt3"></script>
<?php endif; ?>
<script src="../js/landing.js?v=r4"></script>
<script src="../js/hero_fit.js?v=2"></script>
<script src="../js/feedback.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
