<?php
/**
 * Save the store hours. Admin only. Used by the "Store hours" page.
 *
 * POST action=save_weekly
 *      days[0..6][open]  1 or 0       (0 = Sunday ... 6 = Saturday)
 *      days[0..6][from]  "HH:MM"
 *      days[0..6][to]    "HH:MM"
 *
 * POST action=save_day        (a holiday, or a half day with shorter hours)
 *      date  "YYYY-MM-DD"
 *      mode  "closed" | "hours"
 *      from, to  "HH:MM"      (only for mode=hours)
 *      note  optional text, up to 120 characters
 *
 * POST action=delete_day
 *      date  "YYYY-MM-DD"
 *
 * Answers JSON: { success, message, status }
 */

require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/store_hours.php";

header("Content-Type: application/json; charset=utf-8");

function respond(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra));
    exit;
}

if (($_SESSION["role"] ?? "") !== "admin") {
    respond(false, "Administrator access required.", 403);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Invalid request method.", 405);
}

require_once __DIR__ . "/db.php";

ensure_store_hours_schema($conn);

/** A real calendar date written as YYYY-MM-DD. */
function valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);

    return $parsed !== false && $parsed->format("Y-m-d") === $date;
}

/** "HH:MM" -> "9 AM" / "7:30 PM" (same wording as the customer pages). */
function pretty(string $time): string
{
    return store_time_label($time);
}

$action = (string) ($_POST["action"] ?? "");
$todayDate = store_now()->format("Y-m-d");

try {
    // ---------------------------------------------------------------- weekly
    if ($action === "save_weekly") {
        $days = $_POST["days"] ?? null;
        $names = [0 => "Sunday", 1 => "Monday", 2 => "Tuesday", 3 => "Wednesday", 4 => "Thursday", 5 => "Friday", 6 => "Saturday"];
        $rows = [];

        if (!is_array($days)) {
            respond(false, "No hours were sent.", 400);
        }

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $day = $days[$weekday] ?? null;

            if (!is_array($day)) {
                respond(false, "The hours for {$names[$weekday]} are missing.", 400);
            }

            $isOpen = ((string) ($day["open"] ?? "0")) === "1" ? 1 : 0;
            $from = trim((string) ($day["from"] ?? ""));
            $to = trim((string) ($day["to"] ?? ""));

            if ($isOpen === 1) {
                if (!store_valid_time($from) || !store_valid_time($to)) {
                    respond(false, "Please enter an opening and a closing time for {$names[$weekday]}.", 400);
                }

                if ($from >= $to) {
                    respond(false, "{$names[$weekday]}: the closing time must be later than the opening time.", 400);
                }
            } else {
                // Keep sensible times so switching the day back on starts from something useful.
                $from = store_valid_time($from) ? $from : "09:00";
                $to = store_valid_time($to) ? $to : "20:00";
            }

            $rows[$weekday] = [$isOpen, $from, $to];
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare(
            "INSERT INTO store_weekly_hours (weekday, is_open, open_time, close_time) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), open_time = VALUES(open_time), close_time = VALUES(close_time)"
        );

        foreach ($rows as $weekday => [$isOpen, $from, $to]) {
            $stmt->bind_param("iiss", $weekday, $isOpen, $from, $to);
            $stmt->execute();
        }

        $stmt->close();
        $conn->commit();

        store_schedule(true);

        respond(true, "Weekly hours saved.", 200, ["status" => store_status()]);
    }

    // ----------------------------------------------------------- special day
    if ($action === "save_day") {
        $date = trim((string) ($_POST["date"] ?? ""));
        $mode = (string) ($_POST["mode"] ?? "closed");
        $from = trim((string) ($_POST["from"] ?? ""));
        $to = trim((string) ($_POST["to"] ?? ""));
        $note = trim((string) ($_POST["note"] ?? ""));

        if (!valid_date($date)) {
            respond(false, "Please choose a date.", 400);
        }

        if ($date < $todayDate) {
            respond(false, "That date has already passed. Please choose today or a later date.", 400);
        }

        if ($date > store_now()->modify("+366 days")->format("Y-m-d")) {
            respond(false, "Please choose a date within the next year.", 400);
        }

        if (!in_array($mode, ["closed", "hours"], true)) {
            respond(false, "Please choose Closed all day or Different hours.", 400);
        }

        if (mb_strlen($note) > 120) {
            respond(false, "The note is too long (the most is 120 characters).", 400);
        }

        $isClosed = $mode === "closed" ? 1 : 0;

        if ($isClosed === 1) {
            $from = null;
            $to = null;
        } else {
            if (!store_valid_time($from) || !store_valid_time($to)) {
                respond(false, "Please enter an opening and a closing time.", 400);
            }

            if ($from >= $to) {
                respond(false, "The closing time must be later than the opening time.", 400);
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO store_day_overrides (override_date, is_closed, open_time, close_time, note) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_closed = VALUES(is_closed), open_time = VALUES(open_time),
                                     close_time = VALUES(close_time), note = VALUES(note)"
        );
        $stmt->bind_param("sisss", $date, $isClosed, $from, $to, $note);
        $stmt->execute();
        $stmt->close();

        store_schedule(true);

        $label = DateTimeImmutable::createFromFormat("!Y-m-d", $date)->format("l, F j");
        $what = $isClosed === 1 ? "closed all day" : "open " . pretty($from) . " to " . pretty($to);

        respond(true, "Saved: the store will be {$what} on {$label}.", 200, ["status" => store_status()]);
    }

    // ---------------------------------------------------------- remove a day
    if ($action === "delete_day") {
        $date = trim((string) ($_POST["date"] ?? ""));

        if (!valid_date($date)) {
            respond(false, "That date is not valid.", 400);
        }

        $stmt = $conn->prepare("DELETE FROM store_day_overrides WHERE override_date = ?");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $stmt->close();

        store_schedule(true);

        $label = DateTimeImmutable::createFromFormat("!Y-m-d", $date)->format("l, F j");

        respond(true, "Removed. {$label} now follows the regular weekly hours.", 200, ["status" => store_status()]);
    }

    respond(false, "Unknown action.", 400);
} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log("store_hours_save.php: " . $error->getMessage());
    respond(false, "Unable to save the store hours. Please try again.", 500);
}
