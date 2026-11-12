<?php
/**
 * Is the store open right now? (JSON)
 *
 * Customer pages ask this every minute so the "open / closed" banner and the
 * Order button stay correct even if a page is left open past closing time.
 */
require_once __DIR__ . "/store_hours.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

echo json_encode(store_status());
