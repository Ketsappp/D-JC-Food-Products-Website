<?php
/**
 * Store hours (admin only).
 *
 * The admin sets the weekly Sunday-Saturday hours here, and can close the store
 * for a day (a holiday) or give a day shorter hours (a half day). Customers are
 * blocked from ordering outside these hours - see store_hours.php.
 */
session_start();
require_once __DIR__ . "/auth_check.php";

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: order.php");
    exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/store_hours.php";

ensure_store_hours_schema($conn);

$schedule = store_schedule(true);
$status = store_status();
$now = store_now();
$today = $now->format("Y-m-d");
$tomorrow = $now->modify("+1 day")->format("Y-m-d");
$maxDate = $now->modify("+366 days")->format("Y-m-d");

$dayNames = [0 => "Sunday", 1 => "Monday", 2 => "Tuesday", 3 => "Wednesday", 4 => "Thursday", 5 => "Friday", 6 => "Saturday"];

// Today's regular opening time (used to prefill a half day) and today's special-day entry, if any.
$todayRegular = $schedule["weekly"][(int) $now->format("w")] ?? null;
$todaySpecial = $schedule["overrides"][$today] ?? null;

// Special days from today on, soonest first.
$upcoming = array_filter(
    $schedule["overrides"],
    fn($date) => $date >= $today,
    ARRAY_FILTER_USE_KEY
);
ksort($upcoming);

function sh_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/** "2026-11-12" -> "Thu, Nov 12" plus how far away it is. */
function sh_date_label(string $date, DateTimeImmutable $now): array
{
    $day = DateTimeImmutable::createFromFormat("!Y-m-d", $date, $now->getTimezone());
    $days = (int) $now->setTime(0, 0)->diff($day)->format("%r%a");

    if ($days === 0) {
        $away = "Today";
    } elseif ($days === 1) {
        $away = "Tomorrow";
    } else {
        $away = "In " . $days . " days";
    }

    return [$day->format("D, M j, Y"), $away];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Store Hours - D&amp;JC Food Products</title>
<link rel="stylesheet" href="../css/dashboard.css?v=gt1">
<link rel="stylesheet" href="../css/store_hours_admin.css?v=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="../css/admin-theme.css?v=sc2">
<link rel="stylesheet" href="../css/admin-drawer.css?v=1">
<link rel="stylesheet" href="../css/a11y.css?v=gt1">
</head>
<body>

<div class="app-layout">

    <!-- ==== Sidebar ==== -->
            <?php $adminActive = 'hours'; include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- === Main content === -->
    <main class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>Store Hours</h1>
                <p class="page-sub">Set when customers can place orders, and close the store for a holiday or a half day.</p>
            </div>
        </div>

        <div id="alertBox" class="alert-box hidden" role="status"></div>

        <!-- ---------- Right now + quick actions ---------- -->
        <section class="sh-card sh-now" aria-labelledby="nowTitle">
            <div class="sh-now-state">
                <span class="sh-pill <?= $status["open"] ? "is-open" : "is-closed" ?>" id="nowPill">
                    <?= $status["open"] ? "Open now" : "Closed now" ?>
                </span>
                <p class="sh-now-text" id="nowTitle">
                    <?php if ($status["open"]): ?>
                        <?= sh_e($status["message"]) ?>
                    <?php else: ?>
                        <?= sh_e($status["closed_label"]) ?>
                    <?php endif; ?>
                </p>
                <?php if ($todaySpecial !== null): ?>
                    <p class="sh-now-sub">
                        Today is a special day:
                        <?php if ($todaySpecial["closed"]): ?>
                            <strong>closed all day</strong>
                        <?php else: ?>
                            <strong>open <?= sh_e(store_time_label((string) $todaySpecial["open"])) ?> to <?= sh_e(store_time_label((string) $todaySpecial["close"])) ?></strong>
                        <?php endif; ?>
                        <?php if ($todaySpecial["note"] !== ""): ?>(<?= sh_e($todaySpecial["note"]) ?>)<?php endif; ?>.
                    </p>
                <?php endif; ?>
            </div>

            <div class="sh-quick" role="group" aria-label="Quick actions">
                <button type="button" class="btn-secondary sh-quick-btn" data-quick="close-today">Close today</button>
                <button type="button" class="btn-secondary sh-quick-btn" data-quick="close-tomorrow">Close tomorrow</button>
                <button type="button" class="btn-secondary sh-quick-btn" data-quick="half-today">Half day today</button>
                <?php if ($todaySpecial !== null): ?>
                    <button type="button" class="btn-primary sh-quick-btn" id="reopenToday" data-date="<?= sh_e($today) ?>">Back to regular hours today</button>
                <?php endif; ?>
            </div>
        </section>

        <!-- ---------- Weekly hours ---------- -->
        <section class="sh-card" aria-labelledby="weeklyTitle">
            <div class="sh-card-head">
                <div>
                    <h2 id="weeklyTitle">Weekly hours</h2>
                    <p class="sh-hint">The regular hours for each day of the week. Switch a day off if the store is closed that day.</p>
                </div>
            </div>

            <form id="weeklyForm" novalidate>
                <div class="sh-days">
                    <?php foreach ($dayNames as $weekday => $dayName):
                        $hours = $schedule["weekly"][$weekday] ?? null;
                        $isOpen = $hours !== null;
                        $from = $hours[0] ?? "09:00";
                        $to = $hours[1] ?? "20:00";
                    ?>
                        <div class="sh-day<?= $isOpen ? "" : " is-off" ?>" data-weekday="<?= $weekday ?>">
                            <span class="sh-dayname"><?= $dayName ?></span>

                            <label class="sh-switch">
                                <input type="checkbox" class="sh-open" <?= $isOpen ? "checked" : "" ?>
                                       aria-label="<?= $dayName ?> is open">
                                <span class="sh-track" aria-hidden="true"></span>
                                <span class="sh-switch-text"><?= $isOpen ? "Open" : "Closed" ?></span>
                            </label>

                            <div class="sh-times">
                                <label class="sh-time">
                                    <span class="sh-sr">Opens on <?= $dayName ?></span>
                                    <input type="time" class="sh-from" value="<?= sh_e($from) ?>" <?= $isOpen ? "" : "disabled" ?> required>
                                </label>
                                <span class="sh-to" aria-hidden="true">to</span>
                                <label class="sh-time">
                                    <span class="sh-sr">Closes on <?= $dayName ?></span>
                                    <input type="time" class="sh-to-input" value="<?= sh_e($to) ?>" <?= $isOpen ? "" : "disabled" ?> required>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p id="weeklyMessage" class="sh-error" role="alert" hidden></p>

                <div class="sh-actions">
                    <button type="submit" id="saveWeekly" class="btn-primary">Save weekly hours</button>
                </div>
            </form>
        </section>

        <!-- ---------- Special days ---------- -->
        <section class="sh-card" aria-labelledby="specialTitle">
            <div class="sh-card-head">
                <div>
                    <h2 id="specialTitle">Special days</h2>
                    <p class="sh-hint">Holidays and half days. A special day replaces the weekly hours for that date only.</p>
                </div>
                <button type="button" id="addSpecial" class="btn-primary">+ Add special day</button>
            </div>

            <?php if (!$upcoming): ?>
                <p class="sh-empty" id="specialEmpty">No special days coming up. The store follows the weekly hours.</p>
            <?php else: ?>
                <ul class="sh-special" id="specialList">
                    <?php foreach ($upcoming as $date => $special):
                        [$dateText, $away] = sh_date_label($date, $now);
                    ?>
                        <li class="sh-special-item" data-date="<?= sh_e($date) ?>">
                            <div class="sh-special-main">
                                <p class="sh-special-date"><?= sh_e($dateText) ?> <span class="sh-away"><?= sh_e($away) ?></span></p>
                                <p class="sh-special-what">
                                    <?php if ($special["closed"]): ?>
                                        <span class="sh-tag is-closed">Closed all day</span>
                                    <?php else: ?>
                                        <span class="sh-tag is-half">Open <?= sh_e(store_time_label((string) $special["open"])) ?> &ndash; <?= sh_e(store_time_label((string) $special["close"])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($special["note"] !== ""): ?>
                                        <span class="sh-note"><?= sh_e($special["note"]) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="sh-special-actions">
                                <button type="button" class="btn-secondary sh-edit"
                                        data-date="<?= sh_e($date) ?>"
                                        data-mode="<?= $special["closed"] ? "closed" : "hours" ?>"
                                        data-from="<?= sh_e((string) $special["open"]) ?>"
                                        data-to="<?= sh_e((string) $special["close"]) ?>"
                                        data-note="<?= sh_e($special["note"]) ?>">Edit</button>
                                <button type="button" class="btn-secondary sh-remove" data-date="<?= sh_e($date) ?>"
                                        data-label="<?= sh_e($dateText) ?>">Remove</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Special day dialog -->
<div id="specialModal" class="modal-overlay hidden">
    <div class="modal-box small-modal" role="dialog" aria-modal="true" aria-labelledby="specialModalTitle">
        <h3 id="specialModalTitle">Special day</h3>
        <p class="modal-sub" id="specialModalSub">Close the store, or give one day different hours.</p>

        <form id="specialForm" class="sh-form" novalidate
              data-today="<?= sh_e($today) ?>" data-tomorrow="<?= sh_e($tomorrow) ?>" data-max="<?= sh_e($maxDate) ?>"
              data-regular-open="<?= sh_e($todayRegular[0] ?? "09:00") ?>">
            <div class="sh-field">
                <label for="spDate">Date</label>
                <input type="date" id="spDate" min="<?= sh_e($today) ?>" max="<?= sh_e($maxDate) ?>" required>
            </div>

            <fieldset class="sh-field sh-modes">
                <legend>The store will be</legend>
                <label class="sh-radio"><input type="radio" name="mode" value="closed" checked> <span>Closed all day <small>(holiday)</small></span></label>
                <label class="sh-radio"><input type="radio" name="mode" value="hours"> <span>Open with different hours <small>(half day)</small></span></label>
            </fieldset>

            <div class="sh-field sh-hours-row" id="spHoursRow" hidden>
                <div class="sh-times">
                    <label class="sh-time"><span class="sh-sr">Opens</span><input type="time" id="spFrom" value="09:00"></label>
                    <span class="sh-to" aria-hidden="true">to</span>
                    <label class="sh-time"><span class="sh-sr">Closes</span><input type="time" id="spTo" value="12:00"></label>
                </div>
            </div>

            <div class="sh-field">
                <label for="spNote">Note <small>(optional, customers will see it)</small></label>
                <input type="text" id="spNote" maxlength="120" placeholder="e.g. Christmas Day, or Half day">
            </div>

            <p id="specialMessage" class="sh-error" role="alert" hidden></p>
        </form>

        <div class="modal-actions">
            <button type="button" id="cancelSpecial" class="btn-cancel">Cancel</button>
            <button type="submit" form="specialForm" id="saveSpecial" class="btn-confirm">Save</button>
        </div>
    </div>
</div>

<!-- Remove confirmation -->
<div id="removeModal" class="modal-overlay hidden">
    <div class="modal-box small-modal" role="dialog" aria-modal="true" aria-labelledby="removeTitle">
        <h3 id="removeTitle">Remove this special day?</h3>
        <p class="modal-sub" id="removeText"></p>
        <div class="modal-actions">
            <button type="button" id="cancelRemove" class="btn-cancel">Keep it</button>
            <button type="button" id="confirmRemove" class="btn-confirm">Remove</button>
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

<script src="../js/logout.js"></script>
<script src="../js/store_hours_admin.js?v=1"></script>
<script src="../js/admin_notify.js?v=2"></script>
<script src="../js/admin_drawer.js?v=1"></script>
<script src="../js/a11y.js?v=gt1"></script>
</body>
</html>
