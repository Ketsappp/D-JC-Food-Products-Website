<?php
session_start();

// Customers go back to the home page; staff and admins get the login pop-up.
$wasCustomer = (($_SESSION["role"] ?? "") === "customer");

// Clear all session data
$_SESSION = [];

// Destroy the session
session_destroy();

header("Location: " . ($wasCustomer ? "landing_page.php" : "landing_page.php?auth=login"));
exit;
?>
