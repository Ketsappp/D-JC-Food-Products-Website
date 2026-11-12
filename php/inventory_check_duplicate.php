<?php

require_once "db.php";

$product_name = trim($_POST['product_name'] ?? '');

if ($product_name === '') {
    echo "0";
    exit;
}

$sql = "SELECT id FROM inventory
        WHERE LOWER(product_name) = LOWER(?)
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo "0";
    exit;
}

$stmt->bind_param(
    "s",
    $product_name
);

$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    echo "1";
} else {
    echo "0";
}

$stmt->close();
$conn->close();