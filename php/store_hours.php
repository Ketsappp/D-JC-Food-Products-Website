<?php
/**
 * Store hours - the ONE place that says when customers can place orders.
 *
 * Outside these hours customers cannot open the Menu (customerorder.php and
 * its menu data) or place an order. The Home page, My Orders and Profile still
 * work, and every "Order Now" / "See the Full Menu" button turns into a
 * "We're closed" message. Staff and admin accounts are not affected.
 *
 * The admin edits the hours on the "Store hours" page (store_hours_admin.php):
 * the weekly Sunday-Saturday hours, plus "special days" (a holiday, or a half
 * day with shorter hours). Those are saved in the database
 * (store_weekly_hours, store_day_overrides).
 *
 * STORE_HOURS and STORE_CLOSED_DATES below are only the DEFAULTS: they fill the
 * database the first time, and are used if the database cannot be reached.
 * Times use the 24-hour clock and are always read in Philippine time, whatever
 * the server's own clock or time zone is set to.
 */

const STORE_TIMEZONE = 'Asia/Manila';

// Opening and closing time for each weekday.  0 = Sunday ... 6 = Saturday.
// A day that is missing from this list is closed all day.
const STORE_HOURS = [
    0 => ['12:00', '19:00'],   // Sunday     12 PM - 7 PM
    1 => ['09:00', '20:00'],   // Monday      9 AM - 8 PM
    2 => ['09:00', '20:00'],   // Tuesday     9 AM - 8 PM
    3 => ['09:00', '20:00'],   // Wednesday   9 AM - 8 PM
    4 => ['09:00', '20:00'],   // Thursday    9 AM - 8 PM
    5 => ['09:00', '20:00'],   // Friday      9 AM - 8 PM
    6 => ['09:00', '20:00'],   // Saturday    9 AM - 8 PM
];

// Whole days the store is closed (holidays, etc.), as 'YYYY-MM-DD'.
// Example: const STORE_CLOSED_DATES = ['2026-12-25', '2027-01-01'];
const STORE_CLOSED_DATES = [];

// "Closing soon" is shown when this many minutes (or fewer) are left.
const STORE_CLOSING_SOON_MINUTES = 30;

/**
 * The database connection, or null when it cannot be reached.
 * Uses the page's own $conn when there is one, otherwise opens it through db.php.
 */
function store_db(): ?mysqli
{
    global $conn;   // db.php assigns $conn, so it must be the global one

    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }

    static $tried = false;

    if ($tried) {
        return null;
    }

    $tried = true;

    try {
        require_once __DIR__ . '/db.php';
    } catch (Throwable $error) {
        error_log('store_hours.php: ' . $error->getMessage());
        return null;
    }

    return (isset($conn) && $conn instanceof mysqli) ? $conn : null;
}

/** Creates the two store-hours tables (and fills the weekly one from STORE_HOURS) if missing. */
function ensure_store_hours_schema(mysqli $db): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $db->query(
        "CREATE TABLE IF NOT EXISTS `store_weekly_hours` (
            `weekday`    TINYINT UNSIGNED NOT NULL,
            `is_open`    TINYINT(1) NOT NULL DEFAULT 1,
            `open_time`  CHAR(5) NOT NULL DEFAULT '09:00',
            `close_time` CHAR(5) NOT NULL DEFAULT '20:00',
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`weekday`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS `store_day_overrides` (
            `override_date` DATE NOT NULL,
            `is_closed`     TINYINT(1) NOT NULL DEFAULT 1,
            `open_time`     CHAR(5) DEFAULT NULL,
            `close_time`    CHAR(5) DEFAULT NULL,
            `note`          VARCHAR(120) NOT NULL DEFAULT '',
            `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`override_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $count = $db->query('SELECT COUNT(*) AS c FROM `store_weekly_hours`');

    if ($count && (int) $count->fetch_assoc()['c'] === 0) {
        $insert = $db->prepare(
            'INSERT INTO `store_weekly_hours` (weekday, is_open, open_time, close_time) VALUES (?, ?, ?, ?)'
        );

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $hours = STORE_HOURS[$weekday] ?? null;
            $isOpen = $hours === null ? 0 : 1;
            $from = $hours[0] ?? '09:00';
            $to = $hours[1] ?? '20:00';
            $insert->bind_param('iiss', $weekday, $isOpen, $from, $to);
            $insert->execute();
        }

        $insert->close();
    }
}

/** True for "HH:MM" on the 24-hour clock, e.g. "09:00", "19:30". */
function store_valid_time(string $time): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
}

/**
 * The saved schedule (read once per request).
 *
 * 'weekly'    weekday (0 = Sunday ... 6 = Saturday) => ['09:00', '20:00'], or null when closed that day
 * 'overrides' 'YYYY-MM-DD' => ['closed' => bool, 'open' => ?string, 'close' => ?string, 'note' => string]
 *
 * Falls back to STORE_HOURS / STORE_CLOSED_DATES if the database cannot be read.
 * Pass $refresh = true after saving to read it again.
 */
function store_schedule(bool $refresh = false): array
{
    static $cache = null;

    if ($cache !== null && !$refresh) {
        return $cache;
    }

    $weekly = [];

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $weekly[$weekday] = STORE_HOURS[$weekday] ?? null;
    }

    $overrides = [];

    foreach (STORE_CLOSED_DATES as $date) {
        $overrides[$date] = ['closed' => true, 'open' => null, 'close' => null, 'note' => ''];
    }

    $db = store_db();

    if ($db !== null) {
        try {
            ensure_store_hours_schema($db);

            $result = $db->query('SELECT weekday, is_open, open_time, close_time FROM `store_weekly_hours`');

            if ($result && $result->num_rows > 0) {
                $weekly = array_fill(0, 7, null);

                while ($row = $result->fetch_assoc()) {
                    $weekday = (int) $row['weekday'];

                    if ($weekday >= 0 && $weekday <= 6 && (int) $row['is_open'] === 1) {
                        $weekly[$weekday] = [(string) $row['open_time'], (string) $row['close_time']];
                    }
                }
            }

            $result = $db->query(
                'SELECT override_date, is_closed, open_time, close_time, note FROM `store_day_overrides`'
            );

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $overrides[(string) $row['override_date']] = [
                        'closed' => (int) $row['is_closed'] === 1,
                        'open'   => $row['open_time'],
                        'close'  => $row['close_time'],
                        'note'   => (string) $row['note']
                    ];
                }
            }
        } catch (Throwable $error) {
            error_log('store_schedule: ' . $error->getMessage());
        }
    }

    return $cache = ['weekly' => $weekly, 'overrides' => $overrides];
}

/**
 * The current moment in store (Philippine) time.
 * $timestamp is only for testing; leave it out to use the real time.
 */
function store_now(?int $timestamp = null): DateTimeImmutable
{
    $moment = new DateTimeImmutable('@' . ($timestamp ?? time()));

    return $moment->setTimezone(new DateTimeZone(STORE_TIMEZONE));
}

/**
 * Opening and closing moment of the given day, or null when it is closed all day.
 *
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
 */
function store_hours_for(DateTimeImmutable $day): ?array
{
    $date = $day->format('Y-m-d');
    $schedule = store_schedule();

    if (isset($schedule['overrides'][$date])) {
        // A special day (holiday or half day) beats the weekly hours.
        $special = $schedule['overrides'][$date];

        if ($special['closed'] || $special['open'] === null || $special['close'] === null) {
            return null;
        }

        $hours = [$special['open'], $special['close']];
    } else {
        $hours = $schedule['weekly'][(int) $day->format('w')] ?? null;
    }

    if ($hours === null) {
        return null;
    }

    $zone = $day->getTimezone();

    return [
        new DateTimeImmutable($date . ' ' . $hours[0], $zone),
        new DateTimeImmutable($date . ' ' . $hours[1], $zone)
    ];
}

/** "09:00" -> "9 AM", "19:30" -> "7:30 PM". */
function store_time_label(string $time): string
{
    $moment = DateTimeImmutable::createFromFormat('H:i', $time);

    return $moment->format('i') === '00'
        ? $moment->format('g A')
        : $moment->format('g:i A');
}

/** "Mon-Sat 9 AM-8 PM · Sun 12 PM-7 PM", built from STORE_HOURS. */
function store_hours_summary(): string
{
    $names = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    // Monday first, Sunday last; neighbours with the same hours are joined.
    $groups = [];
    $weekly = store_schedule()['weekly'];

    foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday) {
        $hours = $weekly[$weekday] ?? null;
        $label = $hours === null
            ? 'Closed'
            : store_time_label($hours[0]) . '-' . store_time_label($hours[1]);

        $last = count($groups) - 1;

        if ($last >= 0 && $groups[$last]['label'] === $label) {
            $groups[$last]['to'] = $weekday;
        } else {
            $groups[] = ['label' => $label, 'from' => $weekday, 'to' => $weekday];
        }
    }

    $parts = array_map(function ($group) use ($names) {
        $days = $group['from'] === $group['to']
            ? $names[$group['from']]
            : $names[$group['from']] . '-' . $names[$group['to']];

        return $days . ' ' . $group['label'];
    }, $groups);

    return implode(' · ', $parts);
}

/**
 * Whether the store is open, and the messages to show.
 *
 * @return array{
 *   open: bool, closing_soon: bool, title: string, message: string,
 *   order_message: string, closed_label: string, seconds_until_change: ?int,
 *   hours_text: string,
 *   closes_at: ?string, opens_at: ?string
 * }
 */
function store_status(?int $timestamp = null): array
{
    $now = store_now($timestamp);
    $today = store_hours_for($now);

    $open = $today !== null && $now >= $today[0] && $now < $today[1];
    $closesAt = null;
    $opensAt = null;

    if ($open) {
        $closesAt = $today[1];
    } else {
        // The next time the store opens (later today, or on a following day).
        for ($offset = 0; $offset <= 31; $offset++) {
            $hours = store_hours_for($now->modify('+' . $offset . ' day'));

            if ($hours !== null && $hours[0] > $now) {
                $opensAt = $hours[0];
                break;
            }
        }
    }

    // Compared in seconds so the notice starts exactly 30 minutes before closing.
    $secondsLeft = $open ? $closesAt->getTimestamp() - $now->getTimestamp() : 0;
    $closingSoon = $open && $secondsLeft <= STORE_CLOSING_SOON_MINUTES * 60;

    // "today", "tomorrow" or the weekday, e.g. "tomorrow at 9:00 AM".
    $when = '';

    if ($opensAt !== null) {
        $days = (int) $now->setTime(0, 0)->diff($opensAt->setTime(0, 0))->format('%a');

        $dayText = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : $opensAt->format('l'));
        $when = $dayText . ' at ' . $opensAt->format('g:i A');
    }

    if ($open) {
        $title = $closingSoon ? "We're closing soon" : "We're open";
        $message = $closingSoon
            ? 'Last orders are taken until ' . $closesAt->format('g:i A') . '.'
            : 'Open until ' . $closesAt->format('g:i A') . ' today.';
        $orderMessage = '';
    } else {
        // Closed all day for a special day (holiday etc.)? Say so, with the admin's note if there is one.
        $special = store_schedule()['overrides'][$now->format('Y-m-d')] ?? null;
        $closedToday = $special !== null && $special['closed'];
        $why = ($closedToday && $special['note'] !== '') ? ' (' . $special['note'] . ')' : '';

        $title = $closedToday ? "We're closed today" : "We're closed right now";
        $noteText = ($closedToday && $special['note'] !== '') ? $special['note'] . '. ' : '';
        $message = $noteText . ($when !== ''
            ? "You can still browse the menu, but ordering is only available during store hours. We open $when."
            : 'You can still browse the menu, but ordering is only available during store hours.');
        $closedWord = $closedToday ? "We're closed today$why" : "We're closed right now";
        $orderMessage = $when !== ''
            ? "$closedWord, so we can't take new orders. We open $when."
            : "$closedWord, so we can't take new orders.";
    }

    // Short text for buttons that normally lead to the menu while we are closed.
    $closedLabel = $when !== '' ? "We're closed · Opens $when" : "We're closed";

    // Seconds until the store opens or closes next. Counted on the server so
    // a wrong clock on the customer's device cannot throw the page off.
    $secondsUntilChange = null;

    if ($open) {
        $secondsUntilChange = max(0, $closesAt->getTimestamp() - $now->getTimestamp());
    } elseif ($opensAt !== null) {
        $secondsUntilChange = max(0, $opensAt->getTimestamp() - $now->getTimestamp());
    }

    return [
        'open' => $open,
        'closing_soon' => $closingSoon,
        'title' => $title,
        'message' => $message,
        'order_message' => $orderMessage,
        'closed_label' => $closedLabel,
        'seconds_until_change' => $secondsUntilChange,
        'hours_text' => 'Store hours: ' . store_hours_summary(),
        'closes_at' => $closesAt ? $closesAt->format(DATE_ATOM) : null,
        'opens_at' => $opensAt ? $opensAt->format(DATE_ATOM) : null
    ];
}


/**
 * Attributes for a link that leads to the customer menu.
 *
 * While the store is open it is a normal link. While it is closed the href is
 * left out (so it cannot be clicked, opened in a new tab, or reached with the
 * keyboard) and the link is marked aria-disabled. store_hours.js flips the
 * same link back and forth if the store opens or closes while the page is
 * open, using the data-* attributes written here.
 *
 * $options:  'hide' => true   hide the link completely while closed
 *            'keep' => true   keep the link's own text while closed
 *                             (otherwise it becomes the "We're closed" message)
 */
function store_link_attrs(array $status, string $href, string $openLabel = '', array $options = []): string
{
    $e = fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $attrs = ' data-store-link data-open-href="' . $e($href) . '"';

    if ($openLabel !== '') {
        $attrs .= ' data-open-label="' . $e($openLabel) . '"';
    }

    if (!empty($options['hide'])) {
        $attrs .= ' data-closed-hide';
    }

    if (!empty($options['keep'])) {
        $attrs .= ' data-keep-label';
    }

    if ($status['open']) {
        return $attrs . ' href="' . $e($href) . '"';
    }

    $attrs .= ' aria-disabled="true" title="' . $e($status['order_message']) . '"';

    if (!empty($options['hide'])) {
        $attrs .= ' hidden';
    }

    return $attrs;
}

/** The text inside a link made with store_link_attrs() (already escaped). */
function store_link_label(array $status, string $openLabel, array $options = []): string
{
    $text = (!$status['open'] && empty($options['keep'])) ? $status['closed_label'] : $openLabel;

    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
