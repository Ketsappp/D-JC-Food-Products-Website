<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/email_verification.php";

ensure_email_verification_schema($conn);

$token = $_GET["token"] ?? "";
$token = is_string($token) ? $token : "";
$ok = false;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash("sha256", $token);
    $stmt = $conn->prepare(
        "SELECT id, user_id FROM email_verifications
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $conn->begin_transaction();
        try {
            $u = $conn->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL");
            $u->bind_param("i", $row["user_id"]);
            $u->execute();
            $u->close();

            $conn->query("UPDATE email_verifications SET used_at = NOW() WHERE user_id = " . (int) $row["user_id"] . " AND used_at IS NULL");
            $conn->commit();
            $ok = true;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("verify_email: " . $e->getMessage());
        }
    }
}

auth_header("Confirm Email");
?>
<?php if ($ok): ?>
    <h1 class="page-title">Email confirmed!</h1>
    <p class="login-success" role="status">Thank you. Your email address is confirmed and your account is ready.</p>
    <p class="auth-switch"><a href="login.php?verified=1" class="auth-link strong">Log in to your account</a></p>
<?php else: ?>
    <h1 class="page-title">Link not valid</h1>
    <p class="login-error" role="alert">This confirmation link is invalid or has expired.</p>
    <p class="auth-switch"><a href="resend_verification.php" class="auth-link strong">Send me a new confirmation email</a></p>
<?php endif; ?>
<?php auth_footer(); ?>
