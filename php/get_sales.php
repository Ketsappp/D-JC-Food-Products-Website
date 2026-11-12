<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Only admin and staff may read sales. (This file used to have no login check.)
$callerRole = $_SESSION['role'] ?? '';

if (!in_array($callerRole, ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'You do not have permission to view sales.'
    ]);
    exit;
}

// Staff only get TODAY's sales records - nothing else. This is decided here on the
// server, so changing the page or the request in the browser cannot widen it.
$staffToday = ($callerRole === 'staff');

// Read and validate optional date filters.
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$showAll = ($_GET['all'] ?? '') === '1';

// Which quick period the admin picked (the Daily / Weekly / Monthly cards). It decides what the
// sales trend chart shows and which customers are counted. Anything else means "daily".
$period = $_GET['period'] ?? 'daily';

if (!in_array($period, ['daily', 'weekly', 'monthly', 'custom'], true)) {
    $period = 'daily';
}

if ($staffToday) {
    $startDate = '';
    $endDate = '';
    $showAll = true;   // every record of today, not just the latest 10
}

function isValidDate($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);

    return checkdate(
        (int) $parts[1],
        (int) $parts[2],
        (int) $parts[0]
    );
}

if (
    ($startDate !== '' && !isValidDate($startDate)) ||
    ($endDate !== '' && !isValidDate($endDate)) ||
    ($startDate !== '' && $endDate !== '' && $startDate > $endDate)
) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide a valid date range.'
    ]);
    exit;
}

// A custom date range (picked in the Filters panel): the trend chart and the customer count
// follow it. A missing end means "until today"; a missing start means "30 days before the end".
// A very long range is cut to the last 3 years so the chart stays readable.
$rangeStart = '';
$rangeEnd = '';

if ($period === 'custom' && !$staffToday && ($startDate !== '' || $endDate !== '')) {
    $rangeEnd = $endDate !== '' ? $endDate : date('Y-m-d');
    $rangeStart = $startDate !== ''
        ? $startDate
        : date('Y-m-d', strtotime($rangeEnd . ' -29 days'));

    $earliest = date('Y-m-d', strtotime($rangeEnd . ' -1095 days'));

    if ($rangeStart < $earliest) {
        $rangeStart = $earliest;
    }
} else {
    $period = ($period === 'custom') ? 'daily' : $period;
}

try {
    // Monday of this week (the week runs Monday to Sunday, same as the Weekly button).
    $weekStartSql = "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";

    // 1. Summary cards: today's sales, plus the customer count of the chosen period
    //    (daily = today, weekly = this week, monthly = this month).
    $customerWhere = [
        'daily'   => "DATE(created_at) = CURDATE()",
        'weekly'  => "created_at >= $weekStartSql AND created_at < DATE_ADD($weekStartSql, INTERVAL 7 DAY)",
        'monthly' => "MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())",
        // the dates were checked above (YYYY-MM-DD), and are escaped again here
        'custom'  => "DATE(created_at) >= '" . $conn->real_escape_string($rangeStart) . "'"
                   . " AND DATE(created_at) <= '" . $conn->real_escape_string($rangeEnd) . "'"
    ][$period];

    $todayRes = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount END), 0) AS daily_sales,
            COUNT(DISTINCT id) AS total_customers
        FROM orders
        WHERE ($customerWhere)
          AND status = 'Done'
    ");
    $todayStats = $todayRes->fetch_assoc();

    // 2. Summary cards: weekly and monthly sales (not for staff)
    $monthStats = ['monthly_sales' => null];
    $weekStats = ['weekly_sales' => null];

    if (!$staffToday) {
        $weekRes = $conn->query("
            SELECT COALESCE(SUM(total_amount), 0) AS weekly_sales
            FROM orders
            WHERE created_at >= $weekStartSql
              AND created_at < DATE_ADD($weekStartSql, INTERVAL 7 DAY)
              AND status = 'Done'
        ");
        $weekStats = $weekRes->fetch_assoc();

        $monthRes = $conn->query("
            SELECT COALESCE(SUM(total_amount), 0) AS monthly_sales
            FROM orders
            WHERE MONTH(created_at) = MONTH(CURDATE())
              AND YEAR(created_at) = YEAR(CURDATE())
              AND status = 'Done'
        ");
        $monthStats = $monthRes->fetch_assoc();
    }

    // Optional date filter shared by the records table and the most-sold chart.
    $dateSql = '';
    $dateParams = [];
    $dateTypes = '';

    if ($startDate !== '') {
        $dateSql .= " AND DATE(o.created_at) >= ?";
        $dateParams[] = $startDate;
        $dateTypes .= 's';
    }

    if ($endDate !== '') {
        $dateSql .= " AND DATE(o.created_at) <= ?";
        $dateParams[] = $endDate;
        $dateTypes .= 's';
    }

    // Staff: today only (no parameters needed).
    if ($staffToday) {
        $dateSql = " AND DATE(o.created_at) = CURDATE()";
    }

    // Prepare, bind the date filter (if any) and return all rows.
    $fetchRows = function (string $sql) use ($conn, $dateParams, $dateTypes): array {
        $stmt = $conn->prepare($sql);

        if ($dateParams) {
            $stmt->bind_param($dateTypes, ...$dateParams);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    };

    // 3. Sales records: ONE ROW PER COMPLETED ORDER, newest first.
    //    order_no is the number shown to staff and customers.
    $salesQuery = "
        SELECT
            o.id,
            IF(o.order_number > 0, o.order_number, o.id) AS order_no,
            o.customer_name AS customer,
            o.payment_method AS payment,
            o.created_at,
            o.total_amount AS total
        FROM orders o
        WHERE o.status = 'Done'
        $dateSql
        ORDER BY o.created_at DESC, o.id DESC
    ";

    if (!$showAll) {
        $salesQuery .= " LIMIT 10";
    }

    $report = $fetchRows($salesQuery);

    // What each of those orders contained.
    if ($report) {
        $orderIds = implode(',', array_map('intval', array_column($report, 'id')));

        $itemResult = $conn->query("
            SELECT order_id, product_name, quantity, unit_price, total_price
            FROM order_items
            WHERE order_id IN ($orderIds)
            ORDER BY id
        ");

        $itemsByOrder = [];

        while ($item = $itemResult->fetch_assoc()) {
            $itemsByOrder[(int) $item['order_id']][] = [
                'name' => $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'total' => (float) $item['total_price']
            ];
        }

        foreach ($report as &$order) {
            $items = $itemsByOrder[(int) $order['id']] ?? [];

            $order['id'] = (int) $order['id'];
            $order['order_no'] = (int) $order['order_no'];
            $order['items'] = $items;
            $order['quantity'] = array_sum(array_column($items, 'quantity'));
        }

        unset($order);
    }

    // 4. Most sold products. Sizes and add-ons in the order line, e.g.
    //    "Big Pizza (Large) + Take Out", are removed so every variation
    //    counts toward the base product (same rule as the landing page).
    $mostSold = $staffToday ? [] : $fetchRows("
        SELECT
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(oi.product_name, ' (', 1), ' + ', 1)) AS product,
            SUM(oi.quantity) AS quantity,
            SUM(oi.total_price) AS total
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.status = 'Done'
        $dateSql
        GROUP BY product
        ORDER BY quantity DESC, total DESC
        LIMIT 10
    ");

    // 7. Sales trend for the chart (not for staff). It follows the chosen period:
    //    daily   = the last 7 days
    //    weekly  = the last 8 weeks (Monday to Sunday)
    //    monthly = the last 12 months
    //    Days / weeks / months without sales are included as zero so the line has no gaps.
    $trendPoints = [];
    $trendUnit = $period;

    if ($period === 'custom') {
        // Few days: one point per day. Some months: per week. A long range: per month.
        $rangeDays = (int) ((strtotime($rangeEnd) - strtotime($rangeStart)) / 86400) + 1;
        $trendUnit = $rangeDays <= 31 ? 'daily' : ($rangeDays <= 180 ? 'weekly' : 'monthly');
    }

    if (!$staffToday) {
        if ($period === 'custom') {
            $bucketSql = [
                'daily'   => "DATE(created_at)",
                'weekly'  => "DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)",
                'monthly' => "DATE_FORMAT(created_at, '%Y-%m-01')"
            ][$trendUnit];

            $trendRes = $conn->query("
                SELECT $bucketSql AS bucket, SUM(total_amount) AS total
                FROM orders
                WHERE status = 'Done'
                  AND DATE(created_at) >= '" . $conn->real_escape_string($rangeStart) . "'
                  AND DATE(created_at) <= '" . $conn->real_escape_string($rangeEnd) . "'
                GROUP BY bucket
            ");
        } elseif ($period === 'weekly') {
            $trendRes = $conn->query("
                SELECT
                    DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS bucket,
                    SUM(total_amount) AS total
                FROM orders
                WHERE status = 'Done'
                  AND created_at >= DATE_SUB($weekStartSql, INTERVAL 49 DAY)
                  AND created_at < DATE_ADD($weekStartSql, INTERVAL 7 DAY)
                GROUP BY bucket
            ");
        } elseif ($period === 'monthly') {
            $trendRes = $conn->query("
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m-01') AS bucket,
                    SUM(total_amount) AS total
                FROM orders
                WHERE status = 'Done'
                  AND created_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
                  AND created_at < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')
                GROUP BY bucket
            ");
        } else {
            $trendRes = $conn->query("
                SELECT
                    DATE(created_at) AS bucket,
                    SUM(total_amount) AS total
                FROM orders
                WHERE status = 'Done'
                  AND created_at >= CURDATE() - INTERVAL 6 DAY
                  AND created_at < CURDATE() + INTERVAL 1 DAY
                GROUP BY bucket
            ");
        }

        $totalByBucket = [];
        while ($row = $trendRes->fetch_assoc()) {
            $totalByBucket[$row['bucket']] = (float) $row['total'];
        }

        $today = new DateTimeImmutable('today');

        if ($period === 'custom') {
            $from = new DateTimeImmutable($rangeStart);
            $to = new DateTimeImmutable($rangeEnd);

            if ($trendUnit === 'weekly') {
                $cursor = $from->modify('-' . ((int) $from->format('N') - 1) . ' days');
                $step = '+1 week';
            } elseif ($trendUnit === 'monthly') {
                $cursor = $from->modify('first day of this month');
                $step = '+1 month';
            } else {
                $cursor = $from;
                $step = '+1 day';
            }

            while ($cursor <= $to) {
                $key = $cursor->format('Y-m-d');
                $end = $trendUnit === 'weekly' ? $cursor->modify('+6 days')
                    : ($trendUnit === 'monthly' ? $cursor->modify('last day of this month') : $cursor);

                $trendPoints[] = [
                    'date' => $key,
                    'end' => $end->format('Y-m-d'),
                    'total' => $totalByBucket[$key] ?? 0
                ];

                $cursor = $cursor->modify($step);
            }
        } elseif ($period === 'weekly') {
            $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');

            for ($i = 7; $i >= 0; $i--) {
                $start = $monday->modify("-$i weeks");
                $key = $start->format('Y-m-d');
                $trendPoints[] = [
                    'date' => $key,
                    'end' => $start->modify('+6 days')->format('Y-m-d'),
                    'total' => $totalByBucket[$key] ?? 0
                ];
            }
        } elseif ($period === 'monthly') {
            $firstOfMonth = $today->modify('first day of this month');

            for ($i = 11; $i >= 0; $i--) {
                $start = $firstOfMonth->modify("-$i months");
                $key = $start->format('Y-m-d');
                $trendPoints[] = [
                    'date' => $key,
                    'end' => $start->modify('last day of this month')->format('Y-m-d'),
                    'total' => $totalByBucket[$key] ?? 0
                ];
            }
        } else {
            for ($i = 6; $i >= 0; $i--) {
                $key = $today->modify("-$i days")->format('Y-m-d');
                $trendPoints[] = [
                    'date' => $key,
                    'end' => $key,
                    'total' => $totalByBucket[$key] ?? 0
                ];
            }
        }
    }

    // 8. Top-selling product = the first of the most sold products
    $topProduct = $mostSold[0] ?? null;

    // 9. Return the summary cards, records and chart data
    echo json_encode([
        'status' => 'success',

        'stats' => [
            'daily_sales' => number_format(
                (float) $todayStats['daily_sales'],
                2,
                '.',
                ''
            ),
            'weekly_sales' => $staffToday ? null : number_format(
                (float) $weekStats['weekly_sales'],
                2,
                '.',
                ''
            ),
            'monthly_sales' => $staffToday ? null : number_format(
                (float) $monthStats['monthly_sales'],
                2,
                '.',
                ''
            ),
            'total_customers' => (int) $todayStats['total_customers']
        ],

        'report' => $report,
        'most_sold' => $mostSold,
        'period' => $period,
        'trend_unit' => $trendUnit,
        'range' => $period === 'custom' ? ['start' => $rangeStart, 'end' => $rangeEnd] : null,
        'trend' => $trendPoints,
        'top_product' => $topProduct
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to load sales report data.'
    ]);
}
?>
