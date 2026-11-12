<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/login_attempts.php";
require_once __DIR__ . "/staff_accounts.php";

// Used by the "temporary password" check; added here so the UPDATE below works on older databases too.
ensure_password_change_schema($conn);

$token = $_GET["token"] ?? ($_POST["token"] ?? "");
$token = is_string($token) ? $token : "";
$error = "";
$resetRow = null;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash("sha256", $token);
    $stmt = $conn->prepare(
        "SELECT pr.id, pr.user_id, u.password AS current_hash
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $resetRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($resetRow && $_SERVER["REQUEST_METHOD"] === "POST") {

    $password = $_POST["password"] ?? "";
    $confirm  = $_POST["confirm_password"] ?? "";

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif (($problem = password_problem($password)) !== null) {
        $error = $problem;
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (password_verify($password, (string) $resetRow["current_hash"])) {
        // Same rule as Profile and the staff temporary-password page. The link stays valid, so they can try again.
        $error = "Your new password must be different from your current password.";
    } else {
        $newHash = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            $upd->bind_param("si", $newHash, $resetRow["user_id"]);
            $upd->execute();
            $upd->close();

            // Burn this link and any other outstanding ones for the account.
            $use = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
            $use->bind_param("i", $resetRow["user_id"]);
            $use->execute();
            $use->close();

            $conn->commit();

            // A new password also lifts a login lock: the customer has just
            // proved they can open the e-mail of this account.
            try {
                $who = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $who->bind_param("i", $resetRow["user_id"]);
                $who->execute();
                $account = $who->get_result()->fetch_assoc();
                $who->close();

                if ($account) {
                    login_attempts_clear($conn, (string) $account["username"]);
                }
            } catch (Throwable $ignored) {
                // The password is already changed; never fail the reset over this.
            }

            unset($_SESSION["csrf_token"]);
            header("Location: login.php?reset=1");
            exit;
        } catch (Throwable $ex) {
            $conn->rollback();
            error_log("Password reset failed: " . $ex->getMessage());
            $error = "We couldn't reset your password. Please try again.";
        }
    }
}

auth_header("Reset Password");
?>
            <h1 class="page-title">Reset Password</h1>

            <?php if (!$resetRow): ?>
                <p class="login-error" role="alert">This reset link is invalid or has expired.</p>
                <p class="auth-switch">
                    <a href="forgot_password.php" class="auth-link strong">Request a new link</a>
                </p>
            <?php else: ?>
                <p class="page-desc">Choose a new password for your account.</p>

                <?php if ($error !== ""): ?>
                    <p class="login-error" role="alert"><?= e($error) ?></p>
                <?php endif; ?>

                <form method="POST" action="reset_password.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="field">
                        <div class="input-group">
                            <input type="password" id="new_pw" name="password" placeholder="New Password"
                                   autocomplete="new-password" required autofocus>
                            <button type="button" class="toggle-pw" data-target="new_pw">Show</button>
                        </div>
                        <p class="field-hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</p>
                    </div>
                    <div class="field">
                        <div class="input-group">
                            <input type="password" id="confirm_pw" name="confirm_password" placeholder="Confirm New Password"
                                   autocomplete="new-password" required>
                            <button type="button" class="toggle-pw" data-target="confirm_pw">Show</button>
                        </div>
                    </div>

                    <div class="login-actions">
                        <button type="submit" class="btn-login">Reset password</button>
                    </div>
                </form>
            <?php endif; ?>
<script src="../js/password_toggle.js?v=1"></script>
<?php auth_footer(); ?>
