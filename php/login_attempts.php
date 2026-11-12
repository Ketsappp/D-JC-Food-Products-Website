<?php
/**
 * Login attempt limit.
 *
 * A username can fail to log in 5 times. The 5th wrong attempt locks that
 * username for 15 minutes. While it is locked every attempt is refused, even
 * with the correct password, and the lock is not extended by more tries.
 *
 *   - A correct login, or a successful "Forgot Password" reset, clears the count.
 *   - Failures older than 15 minutes no longer count (the count starts over).
 *   - Names that do not exist are counted the same way, so nobody can tell
 *     which usernames exist from the messages.
 *   - Customers, staff and admin all log in through the same form, so the
 *     same rule applies to everyone.
 *
 * Times are stored as Unix timestamps, so the server's time zone never matters.
 * If the database cannot be used for counting, login keeps working (it never
 * locks everybody out because of a counting problem).
 */

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

/** Create the table that keeps the counts (safe to call many times). */
function ensure_login_attempts_schema(mysqli $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                attempt_key VARCHAR(60) NOT NULL,
                failures INT UNSIGNED NOT NULL DEFAULT 0,
                last_failed_at INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (attempt_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (Throwable $error) {
        error_log("ensure_login_attempts_schema: " . $error->getMessage());
    }
}

/** "Ana", " ana " and "ANA" are the same account, so they share one count. */
function login_attempt_key(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 50);
}

/**
 * Is this username locked right now?
 *
 * @return array{locked: bool, seconds_left: int}
 */
function login_attempts_check(mysqli $conn, string $username): array
{
    $open = ["locked" => false, "seconds_left" => 0];

    try {
        $key = login_attempt_key($username);

        $stmt = $conn->prepare(
            "SELECT locked_until FROM login_attempts WHERE attempt_key = ? LIMIT 1"
        );
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $secondsLeft = $row ? (int) $row["locked_until"] - time() : 0;

        return $secondsLeft > 0
            ? ["locked" => true, "seconds_left" => $secondsLeft]
            : $open;
    } catch (Throwable $error) {
        error_log("login_attempts_check: " . $error->getMessage());

        return $open;
    }
}

/**
 * Count one failed attempt (the 5th one locks the username).
 *
 * @return array{locked: bool, seconds_left: int, attempts_left: int}
 *         attempts_left is -1 when the count could not be kept
 */
function login_attempt_failed(mysqli $conn, string $username): array
{
    $lockSeconds = LOGIN_LOCK_MINUTES * 60;

    try {
        $key = login_attempt_key($username);
        $now = time();

        $conn->begin_transaction();

        // Lock the row so two attempts at the same moment are counted one by one.
        $stmt = $conn->prepare(
            "SELECT failures, last_failed_at, locked_until
             FROM login_attempts
             WHERE attempt_key = ?
             FOR UPDATE"
        );
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $failures = 0;

        if ($row) {
            // Already locked: nothing changes, and the lock is not extended.
            if ((int) $row["locked_until"] > $now) {
                $conn->commit();

                return [
                    "locked" => true,
                    "seconds_left" => (int) $row["locked_until"] - $now,
                    "attempts_left" => 0
                ];
            }

            // Only recent failures count. A lock that has ended, or a last
            // failure older than the lock time, starts the count again.
            $isRecent = (int) $row["locked_until"] === 0
                && $now - (int) $row["last_failed_at"] < $lockSeconds;

            $failures = $isRecent ? (int) $row["failures"] : 0;
        }

        $failures++;
        $lockedUntil = $failures >= LOGIN_MAX_ATTEMPTS ? $now + $lockSeconds : 0;

        $save = $conn->prepare(
            "INSERT INTO login_attempts (attempt_key, failures, last_failed_at, locked_until)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                failures = VALUES(failures),
                last_failed_at = VALUES(last_failed_at),
                locked_until = VALUES(locked_until)"
        );
        $save->bind_param("siii", $key, $failures, $now, $lockedUntil);
        $save->execute();
        $save->close();

        $conn->commit();

        // Now and then, tidy away old rows so the table stays small.
        if (random_int(1, 50) === 1) {
            $oldest = $now - 86400;

            $clean = $conn->prepare(
                "DELETE FROM login_attempts WHERE locked_until < ? AND last_failed_at < ?"
            );
            $clean->bind_param("ii", $now, $oldest);
            $clean->execute();
            $clean->close();
        }

        return [
            "locked" => $lockedUntil > 0,
            "seconds_left" => $lockedUntil > 0 ? $lockSeconds : 0,
            "attempts_left" => max(0, LOGIN_MAX_ATTEMPTS - $failures)
        ];
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        error_log("login_attempt_failed: " . $error->getMessage());

        return ["locked" => false, "seconds_left" => 0, "attempts_left" => -1];
    }
}

/** Forget the failures of a username (correct login, or password reset). */
function login_attempts_clear(mysqli $conn, string $username): void
{
    try {
        ensure_login_attempts_schema($conn);

        $key = login_attempt_key($username);

        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log("login_attempts_clear: " . $error->getMessage());
    }
}

/**
 * The message shown on the login form.
 *
 * @param array $state a result of login_attempts_check() or login_attempt_failed()
 */
function login_attempts_message(array $state): string
{
    if (!empty($state["locked"])) {
        $minutes = max(1, (int) ceil($state["seconds_left"] / 60));

        return "Too many failed login attempts. Please try again in " . $minutes .
            " minute" . ($minutes !== 1 ? "s" : "") .
            ", or use Forgot Password to reset your password.";
    }

    $left = (int) ($state["attempts_left"] ?? -1);

    if ($left === 1) {
        return "Incorrect username or password. You have 1 attempt left before " .
            "this account is locked for " . LOGIN_LOCK_MINUTES . " minutes.";
    }

    if ($left > 1) {
        return "Incorrect username or password. You have " . $left . " attempts left.";
    }

    // The count could not be kept: show the usual message.
    return "Incorrect username or password.";
}
