<?php
/**
 * Store open / closed state for customer pages. Draws NOTHING on the page.
 *
 * Included by customer_header.php (Home, My Orders, Profile) and by the
 * customer menu page. It hands the current state to store_hours.js, which
 * re-checks store_status.php (and again exactly at opening / closing time) and
 * switches every "Order Now" / "See the Full Menu" / "Menu" link between its
 * normal and its "We're closed" form without the customer reloading the page.
 */
require_once __DIR__ . "/store_hours.php";

$storeStatus = store_status();
?>
<script>
    window.storeStatus = <?= json_encode($storeStatus, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.storeIsOpen = window.storeStatus.open;
    window.storeClosedMessage = window.storeStatus.order_message;
</script>
<script src="../js/store_hours.js?v=2" defer></script>
