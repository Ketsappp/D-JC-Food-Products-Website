<?php
/** Removes the signed-in customer's own feedback. POST JSON: token */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/feedback_helpers.php";

$userId = feedback_customer_id();

if ($userId === null) {
    feedback_json(false, "Please log in.", 401);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    feedback_json(false, "Invalid request.", 405);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input) || !feedback_token_ok($input["token"] ?? null)) {
    feedback_json(false, "Your session expired. Please refresh the page and try again.", 403);
}

ensure_feedback_schema($conn);

$stmt = $conn->prepare("DELETE FROM feedback WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();

feedback_json(true, "Your feedback was removed.");
