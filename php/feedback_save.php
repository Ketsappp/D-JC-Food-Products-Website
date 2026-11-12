<?php
/** Adds or updates the signed-in customer's feedback. POST JSON: rating, comment, token */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/feedback_helpers.php";

$userId = feedback_customer_id();

if ($userId === null) {
    feedback_json(false, "Please log in with a customer account to leave feedback.", 401);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    feedback_json(false, "Invalid request.", 405);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input) || !feedback_token_ok($input["token"] ?? null)) {
    feedback_json(false, "Your session expired. Please refresh the page and try again.", 403);
}

$rating = filter_var($input["rating"] ?? null, FILTER_VALIDATE_INT);
$comment = trim((string) ($input["comment"] ?? ""));
$comment = preg_replace('/\s+/u', ' ', strip_tags($comment));

if (!$rating || $rating < 1 || $rating > 5) {
    feedback_json(false, "Please choose a rating from 1 to 5 stars.", 400);
}

$length = mb_strlen($comment);

if ($length < FEEDBACK_MIN_LENGTH) {
    feedback_json(false, "Please write at least " . FEEDBACK_MIN_LENGTH . " characters.", 400);
}

if ($length > FEEDBACK_MAX_LENGTH) {
    feedback_json(false, "Please keep your feedback under " . FEEDBACK_MAX_LENGTH . " characters.", 400);
}

ensure_feedback_schema($conn);

try {
    $stmt = $conn->prepare(
        "INSERT INTO feedback (user_id, rating, comment, is_visible)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), is_visible = 1"
    );
    $stmt->bind_param("iis", $userId, $rating, $comment);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    error_log("feedback_save: " . $e->getMessage());
    feedback_json(false, "We couldn't save your feedback. Please try again.", 500);
}

feedback_json(true, "Thank you for your feedback!");
