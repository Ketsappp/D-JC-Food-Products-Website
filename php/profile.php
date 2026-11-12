<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/customer_helpers.php";

$userId = current_customer_id();

if ($userId === null) {
    header("Location: landing_page.php?auth=login&next=profile.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$stmt = $conn->prepare("SELECT username, password, full_name, email, contact_no, home_address FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: logout.php");
    exit;
}

$flash = "";
$flashType = "ok";
$errors = [];
$values = [
    "full_name" => $user["full_name"],
    "email" => $user["email"],
    "mobile" => $user["contact_no"],
    "address" => (string) $user["home_address"],
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && csrf_valid()) {
    $action = $_POST["action"] ?? "";

    if ($action === "profile") {
        $values["full_name"] = trim($_POST["full_name"] ?? "");
        $values["email"]     = trim($_POST["email"] ?? "");
        $values["mobile"]    = trim($_POST["mobile"] ?? "");
        $values["address"]   = trim($_POST["address"] ?? "");

        if ($values["full_name"] === "") {
            $errors["full_name"] = "Please enter your name.";
        } elseif (mb_strlen($values["full_name"]) > 100 || !preg_match("/^[\p{L}][\p{L}\s.'\-]*$/u", $values["full_name"])) {
            $errors["full_name"] = "Use letters, spaces, periods, apostrophes or hyphens (max 100).";
        }

        if (!filter_var($values["email"], FILTER_VALIDATE_EMAIL) || mb_strlen($values["email"]) > 100) {
            $errors["email"] = "Please enter a valid email address.";
        }

        $mobile = normalize_mobile($values["mobile"]);
        if ($mobile === null) {
            $errors["mobile"] = "Enter a valid PH mobile number, e.g. 09123456789.";
        }

        if (mb_strlen($values["address"]) > 255) {
            $errors["address"] = "Address must be 255 characters or fewer.";
        }

        if (!$errors) {
            $dup = $conn->prepare(
                "SELECT email, contact_no FROM users
                 WHERE id <> ? AND (LOWER(email) = LOWER(?) OR contact_no = ?)"
            );
            $dup->bind_param("iss", $userId, $values["email"], $mobile);
            $dup->execute();
            $res = $dup->get_result();
            while ($row = $res->fetch_assoc()) {
                if (strcasecmp($row["email"], $values["email"]) === 0) {
                    $errors["email"] = "That email is already used by another account.";
                }
                if ($row["contact_no"] === $mobile) {
                    $errors["mobile"] = "That mobile number is already used by another account.";
                }
            }
            $dup->close();
        }

        if (!$errors) {
            $address = $values["address"] !== "" ? $values["address"] : null;
            $upd = $conn->prepare("UPDATE users SET full_name = ?, email = ?, contact_no = ?, home_address = ? WHERE id = ? AND role = 'customer'");
            $upd->bind_param("ssssi", $values["full_name"], $values["email"], $mobile, $address, $userId);

            if ($upd->execute()) {
                $_SESSION["full_name"] = $values["full_name"];
                $values["mobile"] = $mobile;
                $flash = "Your profile has been updated.";
            } else {
                $flash = "We couldn't save your changes. Please try again.";
                $flashType = "bad";
            }
            $upd->close();
        } else {
            $flash = "Please fix the highlighted fields.";
            $flashType = "bad";
        }

    } elseif ($action === "password") {
        $current = $_POST["current_password"] ?? "";
        $new     = $_POST["new_password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        if (!password_verify($current, $user["password"])) {
            $errors["current_password"] = "Your current password is incorrect.";
        }
        if (($problem = password_problem($new)) !== null) {
            $errors["new_password"] = $problem;
        } elseif ($new === $current) {
            $errors["new_password"] = "Choose a password different from your current one.";
        }
        if ($new !== $confirm) {
            $errors["confirm_password"] = "Passwords do not match.";
        }

        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'customer'");
            $upd->bind_param("si", $hash, $userId);
            $upd->execute();
            $upd->close();
            session_regenerate_id(true);
            $flash = "Your password has been changed.";
        } else {
            $flash = "Please fix the highlighted fields.";
            $flashType = "bad";
        }
    }
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    $flash = "Your session expired. Please try again.";
    $flashType = "bad";
}

function pf_err(array $errors, string $key): void
{
    if (isset($errors[$key])) {
        echo '<p class="err">' . e($errors[$key]) . '</p>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile - D&amp;JC Food Products</title>
<link rel="stylesheet" href="../css/landing.css?v=gt1">
<link rel="stylesheet" href="../css/customer.css?v=gt1">
<link rel="stylesheet" href="../css/customer_bar.css?v=r5">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>
<?php $active = "profile"; include __DIR__ . "/customer_header.php"; ?>

<main class="page-main">
    <div class="wrap narrow">
        <h1 class="page-h1">My Profile</h1>
        <p class="page-sub">Keep your details up to date so we can reach you about your orders.</p>

        <?php if ($flash !== ""): ?>
            <p class="flash <?= $flashType ?>" role="status"><?= e($flash) ?></p>
        <?php endif; ?>

        <form method="POST" action="profile.php" class="profile-card" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="profile">
            <h2>Personal details</h2>
            <p class="hint">Your username can&rsquo;t be changed.</p>

            <div class="pf-grid">
                <div class="pf-field">
                    <label for="username">Username</label>
                    <input id="username" type="text" value="<?= e($user["username"]) ?>" readonly>
                </div>
                <div class="pf-field">
                    <label for="full_name">Name</label>
                    <input id="full_name" name="full_name" type="text" maxlength="100" value="<?= e($values["full_name"]) ?>" required>
                    <?php pf_err($errors, "full_name"); ?>
                </div>
                <div class="pf-field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" maxlength="100" value="<?= e($values["email"]) ?>" required>
                    <?php pf_err($errors, "email"); ?>
                </div>
                <div class="pf-field">
                    <label for="mobile">Mobile Number</label>
                    <input id="mobile" name="mobile" type="tel" maxlength="16" value="<?= e($values["mobile"]) ?>" required>
                    <?php pf_err($errors, "mobile"); ?>
                </div>
                <div class="pf-field full">
                    <label for="address">Address <span class="muted">(optional)</span></label>
                    <input id="address" name="address" type="text" maxlength="255" value="<?= e($values["address"]) ?>">
                    <?php pf_err($errors, "address"); ?>
                </div>
            </div>

            <div class="pf-actions"><button type="submit" class="btn-order">Save Changes</button></div>
        </form>

        <form method="POST" action="profile.php" class="profile-card" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="password">
            <h2>Change password</h2>
            <p class="hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</p>

            <div class="pf-grid">
                <div class="pf-field full">
                    <label for="current_password">Current password</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    <?php pf_err($errors, "current_password"); ?>
                </div>
                <div class="pf-field">
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
                    <?php pf_err($errors, "new_password"); ?>
                </div>
                <div class="pf-field">
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                    <?php pf_err($errors, "confirm_password"); ?>
                </div>
            </div>

            <div class="pf-actions"><button type="submit" class="btn-order">Update Password</button></div>
        </form>
    </div>
</main>

<script src="../js/customer_notify.js?v=gt3"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
