<?php
/**
 * First login of a staff member who was given a temporary password by e-mail:
 * they must choose their own password before anything else opens (see auth_check.php).
 */
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/staff_accounts.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if (empty($_SESSION["user_id"])) {
    header("Location: landing_page.php?auth=login");
    exit;
}

ensure_password_change_schema($conn);

$stmt = $conn->prepare(
    "SELECT id, username, password, full_name, role, is_active, must_change_password FROM users WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $_SESSION["user_id"]);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || (int) $user["is_active"] !== 1) {
    $_SESSION = [];
    session_destroy();
    header("Location: landing_page.php?auth=login");
    exit;
}

$home = ($user["role"] === "admin") ? "inventory.php" : "order.php";

// Customers never use this page, and anyone who already chose their own password has nothing to do here.
if ($user["role"] === "customer") {
    header("Location: landing_page.php");
    exit;
}

if ((int) $user["must_change_password"] !== 1) {
    header("Location: " . $home);
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new = (string) ($_POST["new_password"] ?? "");
    $confirm = (string) ($_POST["confirm_password"] ?? "");

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif ($new === "" || $confirm === "") {
        $error = "Please enter your new password twice.";
    } elseif ($new !== $confirm) {
        $error = "The two passwords do not match.";
    } elseif (($problem = staff_password_problem($new)) !== null) {
        $error = $problem;
    } elseif (password_verify($new, (string) $user["password"])) {
        $error = "Your new password must be different from the temporary password.";
    } elseif (strcasecmp($new, (string) $user["username"]) === 0) {
        $error = "Your password cannot be the same as your username.";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ? AND must_change_password = 1");
        $upd->bind_param("si", $hash, $user["id"]);
        $upd->execute();
        $upd->close();

        session_regenerate_id(true);
        header("Location: " . $home);
        exit;
    }
}

$firstName = trim(explode(" ", trim((string) $user["full_name"]))[0] ?? "");
$cpFirstName = $firstName;   // the landing page below reuses the name $firstName

/*
 * The form is a pop-up in the middle of the landing page, over a blurred background
 * (same look as the Login pop-up, css/auth_modal.css). It cannot be closed: the staff member
 * has to choose a password, or log out.
 */
ob_start();
?>
<div class="am-overlay is-open" id="cpOverlay">
    <div class="am-dialog" role="dialog" aria-modal="true" aria-labelledby="cpTitle" tabindex="-1">

        <div class="am-brand">
            <span class="am-brand-logo">D&amp;JC</span>
            <span class="am-brand-text">Food Products</span>
        </div>

        <h2 class="am-title" id="cpTitle">Set a new password</h2>
        <p class="am-sub">
            <?= $firstName !== "" ? "Welcome, " . e($firstName) . ". " : "" ?>You signed in with the temporary password from your welcome e-mail.
            Choose your own password to continue.
        </p>

        <?php if ($error !== ""): ?>
            <p class="am-alert am-alert-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="POST" action="change_password.php" class="am-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="am-field">
                <label class="am-label" for="new_password">New password</label>
                <div class="am-input">
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password"
                           placeholder="New password" required autofocus>
                    <button type="button" class="am-toggle-pw toggle-pw" data-target="new_password">Show</button>
                </div>
                <p class="am-hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</p>
            </div>

            <div class="am-field">
                <label class="am-label" for="confirm_password">Confirm new password</label>
                <div class="am-input">
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
                           placeholder="Confirm new password" required>
                    <button type="button" class="am-toggle-pw toggle-pw" data-target="confirm_password">Show</button>
                </div>
            </div>

            <button type="submit" class="am-submit">SAVE AND CONTINUE</button>
        </form>

        <p class="am-switch"><a href="logout.php" class="am-link">Log out</a></p>
    </div>
</div>
<script src="../js/password_toggle.js?v=1"></script>
<script>
// Nothing behind the pop-up can be reached with the keyboard, and the page behind does not scroll.
document.documentElement.classList.add("am-locked");
document.querySelectorAll("body > *:not(#cpOverlay):not(script)").forEach(function (el) {
    el.setAttribute("inert", "");
});
var cpFirst = document.getElementById("new_password");
if (cpFirst) { cpFirst.focus(); }
</script>
<?php
$overlay = ob_get_clean();

// Draw the landing page and put the pop-up on top of it.
$backdrop = null;
$level = ob_get_level();

try {
    ob_start();
    include __DIR__ . "/landing_page.php";
    $backdrop = ob_get_clean();
} catch (Throwable $e) {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
    error_log("change_password.php: landing page backdrop failed: " . $e->getMessage());
    $backdrop = null;
}

$bodyEnd = is_string($backdrop) ? strripos($backdrop, "</body>") : false;

if ($bodyEnd !== false) {
    echo substr($backdrop, 0, $bodyEnd) . $overlay . substr($backdrop, $bodyEnd);
    exit;
}

// Fallback (the landing page could not be drawn): the same form on a plain page.
auth_header("Set a new password");
?>
                <h1 class="page-title">Set a new password</h1>
                <p class="page-desc">
                    <?= $cpFirstName !== "" ? "Welcome, " . e($cpFirstName) . ". " : "" ?>You signed in with the temporary password from your welcome e-mail.
                    Choose your own password to continue.
                </p>

                <?php if ($error !== ""): ?>
                    <p class="login-error" role="alert"><?= e($error) ?></p>
                <?php endif; ?>

                <form method="POST" action="change_password.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="field">
                        <label class="field-label" for="new_password">New password</label>
                        <div class="input-group">
                            <input type="password" id="new_password" name="new_password" autocomplete="new-password"
                                   placeholder="New password" required autofocus>
                            <button type="button" class="toggle-pw" data-target="new_password">Show</button>
                        </div>
                        <p class="field-hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</p>
                    </div>

                    <div class="field">
                        <label class="field-label" for="confirm_password">Confirm new password</label>
                        <div class="input-group">
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
                                   placeholder="Confirm new password" required>
                            <button type="button" class="toggle-pw" data-target="confirm_password">Show</button>
                        </div>
                    </div>

                    <div class="login-actions">
                        <button type="submit" class="btn-login">Save and continue</button>
                    </div>
                </form>

                <p class="auth-switch"><a href="logout.php" class="auth-link">Log out</a></p>
<script src="../js/password_toggle.js?v=1"></script>
<?php auth_footer(); ?>
