<?php 
session_start();
require_once __DIR__ . "/auth_check.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    header("Location: order.php");
    exit;
}

require_once "db.php";
require_once __DIR__ . "/user_status.php";

ensure_user_status_schema($conn);

$sql = "SELECT id, username, full_name, email, contact_no, home_address, image, is_active, must_change_password
        FROM users
        WHERE role = 'staff'
        ORDER BY id ASC";

$result = $conn->query($sql);

?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff - D&JC Food Products</title> 
<link rel="stylesheet" href="../css/dashboard.css?v=gt1">
<link rel="stylesheet" href="../css/staff.css?v=gt2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/admin-theme.css?v=sc2">
<link rel="stylesheet" href="../css/forms.css?v=sc2">
<link rel="stylesheet" href="../css/admin-drawer.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>

<div class="app-layout">

    <!-- ==== Sidebar === -->
            <?php $adminActive = 'staff'; include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- === Main content === -->
    <main class="main-content">
        <div class="page-header">
            <h1>Staff Account</h1>
            <button type="button" id="addStaffBtn" class="btn-primary">Add Staff</button>
        </div>

        <div id="alertBox" class="alert-box hidden"></div>
        
<div class="staff-grid" id="staffTableBody">
    <?php while ($row = $result->fetch_assoc()): ?>
        <?php $isActive = (int) $row['is_active'] === 1; ?>
        <div class="staff-card<?= $isActive ? '' : ' is-disabled' ?>" data-id="<?= (int)$row['id'] ?>">

            <div class="staff-card-header">
                <div class="staff-profile">
                    <div class="staff-avatar">
                        <?php if (!empty($row['image'])): ?>
                            <img
                                src="../uploads/<?= htmlspecialchars(basename($row['image']), ENT_QUOTES, 'UTF-8') ?>"
                                alt="" loading="lazy" decoding="async"
                            >
                        <?php else: ?>
                            <span aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
                        <?php endif; ?>
                    </div>

                    <div class="staff-profile-info">
                        <h3 title="<?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <span class="staff-id">Staff ID: #<?= (int)$row['id'] ?></span>
                        <span class="status-badge <?= $isActive ? 'is-enabled' : 'is-off' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                        <?php if ((int) ($row['must_change_password'] ?? 0) === 1): ?>
                            <span class="status-badge is-pending" title="They have not logged in and chosen their own password yet.">Awaiting first login</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="staff-card-details">
                <p>
                    <strong>Username:</strong>
                    <?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <strong>Email:</strong>
                    <a href="mailto:<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </p>

                <p>
                    <strong>Contact:</strong>
                    <?= htmlspecialchars($row['contact_no'] ?? '', ENT_QUOTES, 'UTF-8') ?: 'Not provided' ?>
                </p>
            </div>

            <div class="staff-card-footer">
                <button
                    type="button"
                    class="icon-btn view-btn"
                    data-id="<?= (int)$row['id'] ?>"
                    data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"
                    data-fullname="<?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>"
                    data-contact="<?= htmlspecialchars($row['contact_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-address="<?= htmlspecialchars($row['home_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    title="View staff details">
                    View
                </button>

                <button
                    type="button"
                    class="icon-btn edit-btn"
                    data-id="<?= (int)$row['id'] ?>"
                    data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"
                    data-fullname="<?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>"
                    data-contact="<?= htmlspecialchars($row['contact_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-address="<?= htmlspecialchars($row['home_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    title="Edit">
                    &#9998; Edit
                </button>

                <button
                    type="button"
                    class="icon-btn toggle-btn <?= $isActive ? 'disable-btn' : 'enable-btn' ?>"
                    data-id="<?= (int)$row['id'] ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    title="<?= $isActive ? 'Deactivate this account' : 'Activate this account' ?>">
                    <?= $isActive ? '&#9940; Deactivate' : '&#10003; Activate' ?>
                </button>
            </div>

        </div>
    <?php endwhile; ?>
</div>



<!-- Add / Edit Staff Modal -->
<div id="staffModal" class="modal-overlay hidden">
    <div class="modal-box staff-modal form-modal">

        <form id="staffForm" method="POST" action="staff_save.php" novalidate>
            <input type="hidden" name="id" id="staffId">

            <header class="form-modal-head">
                <div>
                    <h2 id="staffModalTitle">Add Staff</h2>
                    <p class="modal-sub" id="staffModalSub"></p>
                </div>
                <button type="button" class="modal-x" aria-label="Close" data-click-target="cancelStaffModal"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </header>

            <div class="form-modal-body">

                <p id="staffFormMessage" class="form-alert" role="alert" hidden></p>

                <div class="field">
                    <label for="full_name">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" name="full_name" id="full_name" maxlength="100"
                           placeholder="e.g. Juan Dela Cruz" autocomplete="off" required>
                </div>

                <div class="field">
                    <label for="email">Email address <span class="req" aria-hidden="true">*</span></label>
                    <input type="email" name="email" id="email" maxlength="100"
                           placeholder="name@example.com" autocomplete="off" required>
                    <p class="field-hint" id="emailHint">The username and a temporary password are sent to this address.</p>
                </div>

                <!-- Add Staff: what happens after "Create account" -->
                <section id="staffAddInfo" class="staff-info" aria-live="polite">
                    <h3>What happens next</h3>
                    <ul>
                        <li>Username: <strong id="usernamePreview" class="username-preview">&mdash;</strong>
                            <span class="staff-info-note">(first letter of the first name + the last name; a number is added if it is already taken)</span></li>
                        <li>A temporary password is created automatically and sent only to their e-mail. Nobody, including you, can see it.</li>
                        <li>They must choose their own password the first time they log in.</li>
                    </ul>
                </section>

                <!-- Edit Staff: the other details -->
                <div id="staffEditFields" hidden>
                    <div class="field">
                        <label for="username">Username <span class="req" aria-hidden="true">*</span></label>
                        <input type="text" name="username" id="username" maxlength="50" placeholder="Username" autocomplete="off">
                    </div>

                    <div class="field">
                        <label for="contact_no">Phone number</label>
                        <input type="tel" name="contact_no" id="contact_no" maxlength="13"
                               placeholder="09171234567 or +639171234567"
                               pattern="(?:09[0-9]{9}|\+639[0-9]{9})"
                               title="Enter a valid Philippine mobile number, such as 09171234567 or +639171234567.">
                    </div>

                    <div class="field">
                        <label for="home_address">Home address</label>
                        <textarea name="home_address" id="home_address" maxlength="255" rows="3"
                                  placeholder="Enter staff home address"></textarea>
                    </div>

                    <div class="field">
                        <label for="current_admin_password">Your admin password <span class="req" aria-hidden="true">*</span></label>
                        <input type="password" name="current_admin_password" id="current_admin_password" autocomplete="current-password"
                               placeholder="Your password">
                        <p class="field-hint">Required to confirm changes to a staff account.</p>
                    </div>
                </div>

            </div>

            <footer class="form-modal-foot">
                <div class="foot-actions">
                    <button type="button" id="cancelStaffModal" class="btn-cancel">Cancel</button>
                    <button type="submit" id="staffSubmit" class="btn-confirm">Create account</button>
                </div>
            </footer>
        </form>
    </div>
</div>

<!-- View Staff Details Modal -->
<div id="viewStaffModal" class="modal-overlay hidden">
    <div class="modal-box">
        <h3>Staff Details</h3>

        <div class="staff-view-details">
            <p><strong>Staff ID:</strong> <span id="viewStaffId">—</span></p>
            <p><strong>Full Name:</strong> <span id="viewFullName">—</span></p>
            <p><strong>Username:</strong> <span id="viewUsername">—</span></p>
            <p><strong>Email Address:</strong> <span id="viewEmail">—</span></p>
            <p><strong>Phone Number:</strong> <span id="viewContact">—</span></p>
            <p><strong>Home Address:</strong> <span id="viewAddress">—</span></p>
        </div>

        <div class="modal-actions">
            <button type="button" id="closeViewStaff" class="btn-cancel">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div id="deleteModal" class="modal-overlay hidden">
    <div class="modal-box small-modal">
        <h3 id="statusModalTitle">Deactivate this staff account?</h3>
        <p class="modal-sub" id="statusModalText">They will no longer be able to log in. You can activate the account again anytime.</p>
        <div class="modal-actions">
            <a href="#" id="cancelDelete" class="btn-cancel">Cancel</a>
            <button type="button" id="confirmDelete" class="btn-confirm">Confirm</button>
        </div>
    </div>
</div>

<!-- Logout confirmation modal -->
<div id="logoutModal" class="modal-overlay hidden">
    <div class="modal-box small-modal">
        <h3>You are about to Logout</h3>
        <p class="modal-sub">Are you sure?</p>
        <div class="modal-actions">
            <a href="#" id="cancelLogout" class="btn-cancel">Cancel</a>
            <a href="logout.php" class="btn-confirm">Confirm</a>
        </div>
    </div>
</div>

<script src="../js/sidebar.js"></script>
<script src="../js/staff.js?v=13"></script>
<script>
window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>
<script src="../js/admin_notify.js?v=2"></script>
<script src="../js/admin_forms.js?v=gt3"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
