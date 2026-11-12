<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/email_verification.php";

ensure_email_verification_schema($conn);

$error = "";
$sent = false;
$email = trim($_GET["email"] ?? ($_POST["email"] ?? ""));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare(
            "SELECT id, full_name FROM users
             WHERE LOWER(email) = LOWER(?) AND role = 'customer' AND email_verified_at IS NULL LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            // Throttle: one email per minute per account.
            $recent = $conn->prepare(
                "SELECT 1 FROM email_verifications WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE) LIMIT 1"
            );
            $recent->bind_param("i", $user["id"]);
            $recent->execute();
            $tooSoon = $recent->get_result()->num_rows > 0;
            $recent->close();

            if (!$tooSoon) {
                issue_email_verification($conn, (int) $user["id"], $email, $user["full_name"]);
            }
        }
        // Same answer either way (prevents account probing).
        $sent = true;
    }
}

auth_header("Resend Confirmation");
?>
            <h1 class="page-title">Confirm your email</h1>

            <?php if ($sent): ?>
                <p class="login-success" role="status">
                    If that email belongs to an unconfirmed account, we&rsquo;ve sent a new confirmation link.
                    It expires in <?= VERIFY_TOKEN_HOURS ?> hours.
                </p>
            <?php else: ?>
                <p class="page-desc">Enter the email you registered with and we&rsquo;ll send you a new confirmation link.</p>
                <?php if ($error !== ""): ?>
                    <p class="login-error" role="alert"><?= e($error) ?></p>
                <?php endif; ?>
                <form method="POST" action="resend_verification.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="input-group">
                        <input type="email" name="email" placeholder="Email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
                    </div>
                    <div class="login-actions">
                        <button type="submit" class="btn-login">Send confirmation link</button>
                    </div>
                </form>
            <?php endif; ?>
            <p class="auth-switch"><a href="login.php" class="auth-link strong">Back to Login</a></p>
<?php auth_footer(); ?>
