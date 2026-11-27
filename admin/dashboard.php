<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('user_management', 'view', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$cssPageOverride = 'admin_dashboard';

date_default_timezone_set('Asia/Manila');

// ── Calendar month ───────────────────────────────────────────────────────────

$rawM = $_GET['m'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawM)) {
    $rawM = date('Y-m');
}

[$calYear, $calMonth] = array_map('intval', explode('-', $rawM));

$calToday = (
    date('n') == $calMonth &&
    date('Y') == $calYear
) ? (int)date('j') : 0;

$calFirstDay = (int)date(
    'N',
    mktime(0, 0, 0, $calMonth, 1, $calYear)
) % 7;

$calDays = (int)date(
    't',
    mktime(0, 0, 0, $calMonth, 1, $calYear)
);

$calMonthName = date(
    'F Y',
    mktime(0, 0, 0, $calMonth, 1, $calYear)
);

$prevTS = mktime(0, 0, 0, $calMonth - 1, 1, $calYear);
$nextTS = mktime(0, 0, 0, $calMonth + 1, 1, $calYear);

$prevHref = '?m=' . date('Y-m', $prevTS);
$nextHref = '?m=' . date('Y-m', $nextTS);

// Month dropdown
$monthOptions = [];

for ($i = 1; $i <= 12; $i++) {
    $monthOptions[] = [
        'val' => str_pad($i, 2, '0', STR_PAD_LEFT),
        'lbl' => date('F', mktime(0, 0, 0, $i, 1))
    ];
}

$currentYear = (int)date('Y');
$yearOptions = range($currentYear, $currentYear - 5);


// ── Today's User Activity ────────────────────────────────────────────────────

$loggedToday = 0;
$currentlyOnline = 0;
$totalOffline = 0;

try {
    $activityStmt = $pdo->query("
        SELECT
            COUNT(DISTINCT al.actor_user_id) AS logged_today,

            COUNT(DISTINCT CASE
                WHEN u.status = 'Active'
                 AND u.last_activity >= NOW() - INTERVAL 15 MINUTE
                THEN al.actor_user_id
            END) AS online_today

        FROM audit_logs al

        LEFT JOIN users u
            ON u.id = al.actor_user_id

        WHERE al.action = 'login'
          AND DATE(al.created_at) = CURDATE()
          AND al.actor_user_id IS NOT NULL
    ");

    $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);

    $loggedToday = (int)($activity['logged_today'] ?? 0);
    $currentlyOnline = (int)($activity['online_today'] ?? 0);

    $totalOffline = max(0, $loggedToday - $currentlyOnline);
} catch (Throwable $e) {
    error_log('Dashboard activity query failed: ' . $e->getMessage());
}


// ── Active Employees ─────────────────────────────────────────────────────────

$totalActiveStmt = $pdo->query("
    SELECT COUNT(*)
    FROM employees
    WHERE employment_status = 'Active'
");

$totalActive = (int)$totalActiveStmt->fetchColumn();


// ── Pending Leave ────────────────────────────────────────────────────────────

$pendingLeaveStmt = $pdo->query("
    SELECT COUNT(*)
    FROM leave_requests
    WHERE status = 'Pending'
");

$pendingLeave = (int)$pendingLeaveStmt->fetchColumn();


// ── Employee Growth ──────────────────────────────────────────────────────────

$growthStmt = $pdo->query("
    SELECT
        MONTH(date_hired) AS m,
        COUNT(*) AS cnt
    FROM employees
    WHERE date_hired IS NOT NULL
      AND YEAR(date_hired) = YEAR(CURDATE())
    GROUP BY MONTH(date_hired)
");

$growthRaw = $growthStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$monthLabels = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec'
];

$growthData = [];

for ($i = 1; $i <= 12; $i++) {
    $growthData[] = (int)($growthRaw[$i] ?? 0);
}


// ── Department Distribution ──────────────────────────────────────────────────

$deptStmt = $pdo->query("
    SELECT
        d.name,
        COUNT(e.id) AS cnt

    FROM departments d

    LEFT JOIN employees e
        ON e.department_id = d.id
       AND e.employment_status = 'Active'

    WHERE d.status = 'Active'

    GROUP BY d.id, d.name

    ORDER BY cnt DESC

    LIMIT 8
");

$deptDist = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

$maxDeptCount = max(
    1,
    (int)($deptDist[0]['cnt'] ?? 1)
);


// ── Birthdays This Month ─────────────────────────────────────────────────────

$birthdayStmt = $pdo->prepare("
    SELECT
        e.first_name,
        e.last_name,
        e.date_of_birth,
        d.name AS department_name

    FROM employees e

    LEFT JOIN departments d
        ON d.id = e.department_id

    WHERE MONTH(e.date_of_birth) = :month
      AND e.date_of_birth IS NOT NULL
      AND e.employment_status = 'Active'

    ORDER BY DAY(e.date_of_birth)
");

$birthdayStmt->execute([
    ':month' => date('n')
]);

$birthdays = $birthdayStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Upcoming Approved Leaves ─────────────────────────────────────────────────

$upcomingLeaveStmt = $pdo->query("
    SELECT
        lr.start_date,
        lr.end_date,
        lr.number_of_days,

        e.first_name,
        e.last_name,

        lt.leave_name

    FROM leave_requests lr

    JOIN employees e
        ON e.id = lr.employee_id

    JOIN leave_types lt
        ON lt.id = lr.leave_type_id

    WHERE lr.status = 'Approved'
      AND lr.end_date >= CURDATE()
      AND lr.start_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)

    ORDER BY lr.start_date ASC
");

$upcomingLeaves = $upcomingLeaveStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Today's Birthdays ────────────────────────────────────────────────────────

$todayBirthdayStmt = $pdo->query("
    SELECT
        e.first_name,
        e.last_name,
        d.name AS department_name

    FROM employees e

    LEFT JOIN departments d
        ON d.id = e.department_id

    WHERE MONTH(e.date_of_birth) = MONTH(CURDATE())
      AND DAY(e.date_of_birth) = DAY(CURDATE())
      AND e.date_of_birth IS NOT NULL
      AND e.employment_status = 'Active'
");

$todayBirthdays = $todayBirthdayStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Employees on Leave Today ─────────────────────────────────────────────────

$todayLeaveStmt = $pdo->query("
    SELECT
        e.first_name,
        e.last_name,
        lt.leave_name,
        lr.start_date,
        lr.end_date

    FROM leave_requests lr

    JOIN employees e
        ON e.id = lr.employee_id

    JOIN leave_types lt
        ON lt.id = lr.leave_type_id

    WHERE lr.status = 'Approved'
      AND CURDATE() BETWEEN lr.start_date AND lr.end_date

    ORDER BY e.last_name

    LIMIT 10
");

$todayLeaves = $todayLeaveStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Holidays This Month ──────────────────────────────────────────────────────

$holidayStmt = $pdo->prepare("
    SELECT
        holiday_name,
        holiday_date,
        holiday_type,
        coverage,
        region

    FROM holidays

    WHERE status = 'Active'
      AND YEAR(holiday_date) = :year
      AND MONTH(holiday_date) = :month

    ORDER BY holiday_date ASC
");

$holidayStmt->execute([
    ':year' => $calYear,
    ':month' => $calMonth
]);

$holidays = $holidayStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Upcoming Holidays ────────────────────────────────────────────────────────

$upcomingHolidayStmt = $pdo->query("
    SELECT
        holiday_name,
        holiday_date,
        holiday_type

    FROM holidays

    WHERE status = 'Active'
      AND holiday_date >= CURDATE()
      AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)

    ORDER BY holiday_date ASC
");

$upcomingHolidays = $upcomingHolidayStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Recent Activity ──────────────────────────────────────────────────────────

$recentAuditStmt = $pdo->query("
    SELECT
        al.action,
        al.entity_type,
        al.created_at,
        u.username AS actor

    FROM audit_logs al

    LEFT JOIN users u
        ON u.id = al.actor_user_id

    ORDER BY al.created_at DESC

    LIMIT 5
");

$recentAudit = $recentAuditStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Audit Trail ──────────────────────────────────────────────────────────────

$auditTrailStmt = $pdo->query("
    SELECT
        al.action,
        al.entity_type,
        al.entity_id,
        al.details,
        al.created_at,
        u.username AS actor

    FROM audit_logs al

    LEFT JOIN users u
        ON u.id = al.actor_user_id

    ORDER BY al.created_at DESC

    LIMIT 10
");

$auditTrail = $auditTrailStmt->fetchAll(PDO::FETCH_ASSOC);


// ── Calendar Leave Dots and Modal Data ───────────────────────────────────────

$calLeaveStmt = $pdo->prepare("
    SELECT
        DAY(calendar_days.calendar_date) AS day_num,

        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,

        lt.leave_name AS leave_type,

        lr.start_date,
        lr.end_date,
        lr.number_of_days

    FROM (
        SELECT
            DATE_ADD(
                STR_TO_DATE(:month_start, '%Y-%m-%d'),
                INTERVAL nums.seq DAY
            ) AS calendar_date

        FROM (
            SELECT 0 AS seq UNION SELECT 1 UNION SELECT 2
            UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
            UNION SELECT 6 UNION SELECT 7 UNION SELECT 8
            UNION SELECT 9 UNION SELECT 10 UNION SELECT 11
            UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
            UNION SELECT 15 UNION SELECT 16 UNION SELECT 17
            UNION SELECT 18 UNION SELECT 19 UNION SELECT 20
            UNION SELECT 21 UNION SELECT 22 UNION SELECT 23
            UNION SELECT 24 UNION SELECT 25 UNION SELECT 26
            UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
            UNION SELECT 30
        ) nums

        WHERE nums.seq < :days_in_month

    ) calendar_days

    JOIN leave_requests lr
        ON calendar_days.calendar_date
           BETWEEN lr.start_date AND lr.end_date

    JOIN employees e
        ON e.id = lr.employee_id

    JOIN leave_types lt
        ON lt.id = lr.leave_type_id

    WHERE lr.status = 'Approved'

    ORDER BY day_num, employee_name
");

$calLeaveStmt->execute([
    ':month_start' => sprintf(
        '%04d-%02d-01',
        $calYear,
        $calMonth
    ),
    ':days_in_month' => $calDays
]);

$calLeaveRows = $calLeaveStmt->fetchAll(PDO::FETCH_ASSOC);

$leaveDays = [];
$leaveByDay = [];

foreach ($calLeaveRows as $row) {

    $day = (int)$row['day_num'];

    $leaveDays[$day] = true;

    $leaveByDay[$day][] = [
        'name' => $row['employee_name'],
        'leave_type' => $row['leave_type'],
        'start' => $row['start_date'],
        'end' => $row['end_date'],
        'days' => $row['number_of_days'],
    ];
}


// ── Holiday Calendar Data ────────────────────────────────────────────────────

$holidayDays = [];
$holidayByDay = [];

foreach ($holidays as $holiday) {

    $day = (int)date(
        'j',
        strtotime($holiday['holiday_date'])
    );

    $holidayDays[$day] = true;

    $holidayByDay[$day][] = [
        'name' => $holiday['holiday_name'],
        'type' => $holiday['holiday_type']
    ];
}


// ── Calendar JSON ────────────────────────────────────────────────────────────

$leaveByDayJson = json_encode(
    $leaveByDay,
    JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
);

$holidayByDayJson = json_encode(
    $holidayByDay,
    JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
);


// ── Dashboard Totals ─────────────────────────────────────────────────────────

$totalEmpStmt = $pdo->query("
    SELECT COUNT(*)
    FROM employees
    WHERE employment_status = 'Active'
");

$totalEmployees = (int)$totalEmpStmt->fetchColumn();


$totalDeptStmt = $pdo->query("
    SELECT COUNT(*)
    FROM departments
    WHERE status = 'Active'
");

$totalDepts = (int)$totalDeptStmt->fetchColumn();


require __DIR__ . '/../templates/adminTemplates/header.php';

?>

<div class="adash-grid">

    <!-- ═══ BOX 1 — Today's User Activity ════════════════════════════════════ -->

    <div class="mbox mbox-1">

        <div class="mbox-title">
            Today's User Activity
        </div>

        <div class="mbox-1-stats">

            <div class="mstat-card">
                <span class="mstat-label">Logged in today</span>
                <strong class="mstat-num">
                    <?= $loggedToday ?>
                </strong>
            </div>

            <div class="mstat-card">
                <span class="mstat-label">Currently online</span>
                <strong class="mstat-num online">
                    <?= $currentlyOnline ?>
                </strong>
            </div>

            <div class="mstat-card">
                <span class="mstat-label">Currently offline</span>
                <strong class="mstat-num muted">
                    <?= $totalOffline ?>
                </strong>
            </div>

        </div>

    </div>


    <!-- ═══ BOX 2 — Calendar ════════════════════════════════════════════════ -->

    <div class="mbox mbox-2">

        <div class="cal-header">

            <div class="cal-nav">

                <a href="<?= h($prevHref) ?>"
                    class="cal-nav-btn"
                    title="Previous month">
                    &#8249;
                </a>

                <?php
                $selectedMonth = str_pad(
                    $calMonth,
                    2,
                    '0',
                    STR_PAD_LEFT
                );
                ?>

                <select
                    class="cal-month-select"
                    id="calMonthSelect"
                    onchange="updateCalendar()">

                    <?php foreach ($monthOptions as $opt): ?>

                        <option
                            value="<?= h($opt['val']) ?>"
                            <?= $opt['val'] === $selectedMonth ? 'selected' : '' ?>>

                            <?= h($opt['lbl']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <select
                    class="cal-month-select"
                    id="calYearSelect"
                    onchange="updateCalendar()">

                    <?php foreach ($yearOptions as $year): ?>

                        <option
                            value="<?= $year ?>"
                            <?= $year === $calYear ? 'selected' : '' ?>>

                            <?= $year ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <a href="<?= h($nextHref) ?>"
                    class="cal-nav-btn"
                    title="Next month">
                    &#8250;
                </a>

            </div>


            <?php if ($pendingLeave > 0): ?>

                <a href="/hr/leave_management.php"
                    class="cal-badge">

                    <?= $pendingLeave ?> pending

                </a>

            <?php endif; ?>

        </div>


        <!-- Calendar Grid -->

        <div class="cal-grid">

            <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?>

                <div class="cal-dow">
                    <?= $d ?>
                </div>

            <?php endforeach; ?>


            <?php for ($blank = 0; $blank < $calFirstDay; $blank++): ?>

                <div></div>

            <?php endfor; ?>


            <?php for ($day = 1; $day <= $calDays; $day++): ?>

                <?php
                $hasLeave = isset($leaveDays[$day]);
                $hasHoliday = isset($holidayDays[$day]);
                ?>

                <div
                    class="cal-day
                    <?= $day === $calToday ? 'cal-today' : '' ?>
                    <?= $hasLeave ? 'cal-has-leave' : '' ?>
                    <?= $hasHoliday ? 'cal-has-holiday' : '' ?>"

                    <?= ($hasLeave || $hasHoliday)
                        ? 'data-day="' . $day . '" data-bs-toggle="modal" data-bs-target="#leaveModal"'
                        : ''
                    ?>>

                    <?= $day ?>

                    <?php if ($hasLeave): ?>
                        <span class="cal-dot"></span>
                    <?php endif; ?>

                    <?php if ($hasHoliday): ?>
                        <span class="cal-holiday-dot"></span>
                    <?php endif; ?>

                </div>

            <?php endfor; ?>

        </div>


        <div class="cal-legend">

            <span class="dot-purple"></span>
            Leave

            <span class="cal-holiday-dot"></span>
            Holiday

        </div>


        <!-- Today's Events -->

        <div class="cal-today-events">

            <div class="cte-title">

                <i class="bi bi-calendar-day me-1"></i>

                Today's Events

                <span class="cte-date">
                    <?= date('F j, Y') ?>
                </span>

            </div>


            <?php if (
                empty($todayBirthdays) &&
                empty($todayLeaves)
            ): ?>

                <div class="cte-empty">
                    No events today.
                </div>

            <?php endif; ?>


            <?php foreach ($todayBirthdays as $tb): ?>

                <div class="cte-row cte-birthday">

                    <span class="cte-icon">🎂</span>

                    <div class="cte-info">

                        <span class="cte-name">
                            <?= h($tb['first_name'] . ' ' . $tb['last_name']) ?>
                        </span>

                        <span class="cte-meta">
                            Birthday · <?= h($tb['department_name'] ?? '—') ?>
                        </span>

                    </div>

                </div>

            <?php endforeach; ?>


            <?php foreach ($todayLeaves as $tl): ?>

                <div class="cte-row cte-leave">

                    <span class="cte-icon">🏖️</span>

                    <div class="cte-info">

                        <span class="cte-name">
                            <?= h($tl['first_name'] . ' ' . $tl['last_name']) ?>
                        </span>

                        <span class="cte-meta">
                            <?= h($tl['leave_name']) ?>
                            · until <?= date('M j', strtotime($tl['end_date'])) ?>
                        </span>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>


    <!-- ═══ BOX 3 — Employee Growth ═════════════════════════════════════════ -->

    <div class="mbox mbox-3">

        <div class="mbox-header">

            <div>

                <div class="mbox-title">
                    Employee Growth
                </div>

                <div class="mbox-sub">
                    Hired per month · <?= date('Y') ?>
                </div>

            </div>

        </div>


        <div class="chart-wrap">
            <canvas id="growthChart"></canvas>
        </div>

    </div>


    <!-- ═══ BOX 4 — People & Schedule ═══════════════════════════════════════ -->

    <div class="mbox mbox-4">

        <div class="mbox-header">

            <span class="mbox-title">
                People &amp; Schedule
            </span>

            <span class="mbox-sub">
                <?= h(date('F Y')) ?>
            </span>

        </div>


        <div class="people-tabs">

            <button class="people-tab active" data-tab="birthdays">
                Birthdays
            </button>

            <button class="people-tab" data-tab="leaves">
                Incoming Leave
            </button>

            <button class="people-tab" data-tab="events">
                Events
            </button>

        </div>


        <!-- Birthdays -->

        <div class="people-panel" id="tab-birthdays">

            <div id="birthdays-list"></div>

            <div class="people-pagination" id="birthdays-pagination">

                <button type="button" class="people-prev">
                    &laquo; Previous
                </button>

                <span class="people-page-info">
                    1 of 1
                </span>

                <button type="button" class="people-next">
                    Next &raquo;
                </button>

            </div>

        </div>


        <!-- Incoming Leave -->

        <div class="people-panel hidden" id="tab-leaves">

            <div id="leaves-list"></div>

            <div class="people-pagination" id="leaves-pagination">

                <button type="button" class="people-prev">
                    &laquo; Previous
                </button>

                <span class="people-page-info">
                    1 of 1
                </span>

                <button type="button" class="people-next">
                    Next &raquo;
                </button>

            </div>

        </div>


        <!-- Events -->

        <div class="people-panel hidden" id="tab-events">

            <div id="events-list"></div>

            <div class="people-pagination" id="events-pagination">

                <button type="button" class="people-prev">
                    &laquo; Previous
                </button>

                <span class="people-page-info">
                    1 of 1
                </span>

                <button type="button" class="people-next">
                    Next &raquo;
                </button>

            </div>

        </div>

    </div>


    <!-- ═══ BOX 5 — Department Distribution ═════════════════════════════════ -->

    <div class="mbox mbox-5">

        <div class="mbox-header">

            <div>

                <div class="mbox-title">
                    Department Distribution
                </div>

                <div class="mbox-sub">
                    Headcount by department
                </div>

            </div>

        </div>


        <div class="deptdist-summary">

            <div>

                <strong>
                    <?= $totalEmployees ?>
                </strong>

                <span>
                    Employees
                </span>

            </div>

            <div class="deptdist-sep"></div>

            <div>

                <strong>
                    <?= $totalDepts ?>
                </strong>

                <span>
                    Departments
                </span>

            </div>

        </div>


        <div class="deptdist-bars">

            <?php if (empty($deptDist)): ?>

                <p class="adash-empty">
                    No department data available.
                </p>

            <?php else: ?>

                <?php foreach ($deptDist as $dd): ?>

                    <?php
                    $pct = $maxDeptCount > 0
                        ? round(((int)$dd['cnt'] / $maxDeptCount) * 100)
                        : 0;
                    ?>

                    <div class="deptdist-row">

                        <div class="deptdist-label">
                            <?= h($dd['name']) ?>
                        </div>

                        <div class="deptdist-track">

                            <div
                                class="deptdist-fill"
                                style="width:<?= $pct ?>%">
                            </div>

                        </div>

                        <div class="deptdist-count">
                            <?= (int)$dd['cnt'] ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- ═══ BOX 6 — Recent Activity ═════════════════════════════════════════ -->

    <div class="mbox mbox-6">

        <div class="mbox-header">

            <span class="mbox-title">
                Recent Activity
            </span>

            <a href="/admin/audit_logs.php" class="adash-viewall">
                View all
            </a>

        </div>


        <?php if (empty($recentAudit)): ?>

            <p class="adash-empty">
                No activity yet.
            </p>

        <?php else: ?>

            <?php foreach ($recentAudit as $entry): ?>

                <?php
                $action = strtolower($entry['action']);

                $icon = str_contains($action, 'login')
                    ? '🔐'
                    : (str_contains($action, 'leave')
                        ? '📋'
                        : (str_contains($action, 'employee')
                            ? '👤'
                            : '📝'));
                ?>

                <div class="recent-row">

                    <div class="recent-icon">
                        <?= $icon ?>
                    </div>

                    <div class="recent-info">

                        <div class="recent-action">
                            <?= h($entry['action']) ?>
                        </div>

                        <div class="recent-actor">
                            <?= h($entry['actor'] ?? 'System') ?>
                            · <?= h($entry['entity_type'] ?? 'System') ?>
                        </div>

                        <div class="recent-time">
                            <?= h(date(
                                'M j, g:i a',
                                strtotime($entry['created_at'])
                            )) ?>
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>


    <!-- ═══ BOX 7 — Audit Trail ═════════════════════════════════════════════ -->

    <div class="mbox mbox-7">

        <div class="mbox-header">

            <span class="mbox-title">
                Audit Trail
            </span>

            <a href="/admin/audit_logs.php" class="adash-viewall">
                View all
            </a>

        </div>


        <?php if (empty($auditTrail)): ?>

            <p class="adash-empty">
                No audit logs yet.
            </p>

        <?php else: ?>

            <?php foreach ($auditTrail as $entry): ?>

                <?php
                $action = strtolower($entry['action']);

                $icon = str_contains($action, 'login')
                    ? '🔐'
                    : (str_contains($action, 'leave')
                        ? '📋'
                        : (str_contains($action, 'employee')
                            ? '👤'
                            : '📝'));
                ?>

                <div class="recent-row">

                    <div class="recent-icon">
                        <?= $icon ?>
                    </div>

                    <div class="recent-info">

                        <div class="recent-action">
                            <?= h($entry['action']) ?>
                        </div>

                        <div class="recent-actor">
                            <?= h($entry['actor'] ?? 'System') ?>
                        </div>

                        <div class="recent-time">
                            <?= h(date(
                                'M j, g:i a',
                                strtotime($entry['created_at'])
                            )) ?>
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>


<!-- ═══ Leave and Holiday Modal ═══════════════════════════════════════════════ -->

<div
    class="modal fade"
    id="leaveModal"
    tabindex="-1"
    aria-labelledby="leaveModalLabel"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content adash-modal">

            <div class="modal-header adash-modal-header">

                <h5 class="modal-title" id="leaveModalLabel">

                    <i class="bi bi-calendar-check me-2"></i>

                    Calendar Events —

                    <span id="leaveModalDate"></span>

                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close">
                </button>

            </div>


            <div class="modal-body adash-modal-body">

                <div id="leaveModalBody"></div>

                <div class="leave-pagination" id="leavePagination">

                    <button type="button" id="leavePrev" disabled>
                        &laquo; Previous
                    </button>

                    <span id="leavePageInfo">
                        1 of 1
                    </span>

                    <button type="button" id="leaveNext" disabled>
                        Next &raquo;
                    </button>

                </div>

            </div>


            <div class="modal-footer adash-modal-footer">

                <a
                    href="/hr/leave_management.php"
                    class="btn adash-modal-btn">

                    View All Leave Requests

                </a>

            </div>

        </div>

    </div>

</div>


<!-- ═══ Chart.js ═════════════════════════════════════════════════════════════ -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    // ── Growth Chart ─────────────────────────────────────────────────────────────

    const growthCanvas = document.getElementById('growthChart');

    if (growthCanvas) {

        const growthCtx = growthCanvas.getContext('2d');

        new Chart(growthCtx, {

            type: 'bar',

            data: {

                labels: <?= json_encode($monthLabels) ?>,

                datasets: [{

                    data: <?= json_encode($growthData) ?>,

                    backgroundColor: '#76f5e8',
                    hoverBackgroundColor: '#aadcff',

                    borderRadius: 4,

                    borderSkipped: false,

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {
                        display: false
                    },

                    tooltip: {

                        callbacks: {

                            label: ctx => ` ${ctx.raw} hired`

                        }

                    }

                },

                scales: {

                    x: {

                        grid: {
                            color: 'rgba(42, 53, 102, 0.55)'

                        },

                        ticks: {

                            color: '#B4A5C0',

                            font: {
                                size: 10
                            }

                        }

                    },

                    y: {

                        grid: {
                            color: '#332A3D'
                        },

                        ticks: {

                            color: '#B4A5C0',

                            font: {
                                size: 10
                            },

                            stepSize: 1

                        },

                        beginAtZero: true

                    }

                }

            }

        });

    }


    // ── People & Schedule Pagination ─────────────────────────────────────────────

    const peoplePerPage = 5;

    const peoplePages = {

        birthdays: 1,

        leaves: 1,

        events: 1

    };


    // ── PHP Data ─────────────────────────────────────────────────────────────────

    const birthdayData = <?= json_encode(
                                $birthdays,
                                JSON_HEX_TAG |
                                    JSON_HEX_APOS |
                                    JSON_HEX_AMP |
                                    JSON_HEX_QUOT
                            ) ?>;


    const incomingLeaveData = <?= json_encode(
                                    $upcomingLeaves,
                                    JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                ) ?>;


    const todayBirthdayData = <?= json_encode(
                                    $todayBirthdays,
                                    JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                ) ?>;


    const todayLeaveData = <?= json_encode(
                                $todayLeaves,
                                JSON_HEX_TAG |
                                    JSON_HEX_APOS |
                                    JSON_HEX_AMP |
                                    JSON_HEX_QUOT
                            ) ?>;


    const upcomingHolidayData = <?= json_encode(
                                    $upcomingHolidays,
                                    JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                ) ?>;


    // ── Escape HTML ──────────────────────────────────────────────────────────────

    function escapePeopleHTML(value) {

        return String(value ?? '').replace(/[&<>"']/g, char => ({

            '&': '&amp;',

            '<': '&lt;',

            '>': '&gt;',

            '"': '&quot;',

            "'": '&#039;'

        })[char]);

    }


    // ── Render Pagination ────────────────────────────────────────────────────────

    function renderPeoplePagination(type, data, renderer) {

        const list = document.getElementById(type + '-list');

        const pagination = document.getElementById(type + '-pagination');

        if (!list || !pagination) return;

        const prev = pagination.querySelector('.people-prev');

        const next = pagination.querySelector('.people-next');

        const info = pagination.querySelector('.people-page-info');


        const totalPages = Math.max(
            1,
            Math.ceil(data.length / peoplePerPage)
        );


        peoplePages[type] = Math.min(
            peoplePages[type],
            totalPages
        );


        const start = (
            peoplePages[type] - 1
        ) * peoplePerPage;


        const pageData = data.slice(
            start,
            start + peoplePerPage
        );


        if (data.length === 0) {

            list.innerHTML = `
            <div class="cte-empty">
                No records available.
            </div>
        `;

        } else {

            list.innerHTML = pageData.map(renderer).join('');

        }


        info.textContent = `${peoplePages[type]} of ${totalPages}`;

        prev.disabled = peoplePages[type] === 1;

        next.disabled = peoplePages[type] === totalPages;

        pagination.style.display =
            data.length > peoplePerPage ? 'flex' : 'none';

    }


    // ── Birthday Renderer ───────────────────────────────────────────────────────

    function renderBirthday(b) {

        const date = new Date(
            b.date_of_birth + 'T00:00:00'
        );

        return `

        <div class="people-row">

            <div class="people-date">

                <strong>
                    ${date.getDate()}
                </strong>

                <span>
                    ${date.toLocaleString('en', {
                        month: 'short'
                    }).toUpperCase()}
                </span>

            </div>


            <div class="people-info">

                <div class="people-name">
                    ${escapePeopleHTML(
                        b.first_name + ' ' + b.last_name
                    )}
                </div>

                <div class="people-dept">
                    ${escapePeopleHTML(
                        b.department_name || '—'
                    )}
                </div>

            </div>

        </div>

    `;

    }


    // ── Incoming Leave Renderer ──────────────────────────────────────────────────

    function renderIncomingLeave(lv) {

        const date = new Date(
            lv.start_date + 'T00:00:00'
        );

        const start = date.toLocaleString('en', {

            month: 'short',

            day: 'numeric'

        });


        const end = new Date(
            lv.end_date + 'T00:00:00'
        ).toLocaleString('en', {

            month: 'short',

            day: 'numeric'

        });


        return `

        <div class="people-row">

            <div class="people-date leave-date">

                <strong>
                    ${date.getDate()}
                </strong>

                <span>
                    ${date.toLocaleString('en', {
                        month: 'short'
                    }).toUpperCase()}
                </span>

            </div>


            <div class="people-info">

                <div class="people-name">
                    ${escapePeopleHTML(
                        lv.first_name + ' ' + lv.last_name
                    )}
                </div>

                <div class="people-dept">

                    ${escapePeopleHTML(lv.leave_name)}

                    · ${escapePeopleHTML(lv.number_of_days)} days

                </div>

            </div>


            <div class="people-day">
                ${start} – ${end}
            </div>

        </div>

    `;

    }


    // ── Events Renderer ─────────────────────────────────────────────────────────

    function renderEvent(event) {

        const date = new Date(
            event.date + 'T00:00:00'
        );


        let icon = '📅';

        let description = '';


        if (event.type === 'birthday') {

            icon = '🎂';

            description =
                'Birthday · ' +
                escapePeopleHTML(event.department || '—');

        } else if (event.type === 'leave') {

            icon = '🏖️';

            description =
                escapePeopleHTML(event.leave_name) +
                ' · until ' +
                escapePeopleHTML(event.end_date);

        } else if (event.type === 'holiday') {

            icon = '🎉';

            description =
                escapePeopleHTML(event.holiday_type);

        }


        return `

        <div class="people-row">

            <div class="people-date">

                <strong>
                    ${date.getDate()}
                </strong>

                <span>
                    ${date.toLocaleString('en', {
                        month: 'short'
                    }).toUpperCase()}
                </span>

            </div>


            <div class="people-info">

                <div class="people-name">

                    ${icon}

                    ${escapePeopleHTML(event.name)}

                </div>

                <div class="people-dept">
                    ${description}
                </div>

            </div>

        </div>

    `;

    }


    // ── Combine Events ───────────────────────────────────────────────────────────

    const eventData = [

        ...todayBirthdayData.map(b => ({

            type: 'birthday',

            name: b.first_name + ' ' + b.last_name,

            department: b.department_name,

            date: '<?= date('Y-m-d') ?>'

        })),


        ...todayLeaveData.map(lv => ({

            type: 'leave',

            name: lv.first_name + ' ' + lv.last_name,

            leave_name: lv.leave_name,

            date: '<?= date('Y-m-d') ?>',

            end_date: lv.end_date

        })),


        ...upcomingHolidayData.map(holiday => ({

            type: 'holiday',

            name: holiday.holiday_name,

            holiday_type: holiday.holiday_type,

            date: holiday.holiday_date

        }))

    ];


    // Sort events by date

    eventData.sort((a, b) => {

        return new Date(a.date) - new Date(b.date);

    });


    // ── Render All People Panels ─────────────────────────────────────────────────

    function renderAllPeople() {

        renderPeoplePagination(
            'birthdays',
            birthdayData,
            renderBirthday
        );


        renderPeoplePagination(
            'leaves',
            incomingLeaveData,
            renderIncomingLeave
        );


        renderPeoplePagination(
            'events',
            eventData,
            renderEvent
        );

    }


    // ── Previous and Next Buttons ─────────────────────────────────────────────────

    document.querySelectorAll('.people-pagination').forEach(pagination => {

        const type = pagination.id.replace(
            '-pagination',
            ''
        );


        pagination.querySelector('.people-prev').addEventListener(
            'click',
            () => {

                if (peoplePages[type] > 1) {

                    peoplePages[type]--;

                    renderAllPeople();

                }

            }
        );


        pagination.querySelector('.people-next').addEventListener(
            'click',
            () => {

                peoplePages[type]++;

                renderAllPeople();

            }
        );

    });


    // ── Tab Switching ────────────────────────────────────────────────────────────

    document.querySelectorAll('.people-tab').forEach(btn => {

        btn.addEventListener('click', () => {

            document.querySelectorAll('.people-tab').forEach(b => {

                b.classList.remove('active');

            });


            document.querySelectorAll('.people-panel').forEach(panel => {

                panel.classList.add('hidden');

            });


            btn.classList.add('active');


            const target = document.getElementById(
                'tab-' + btn.dataset.tab
            );


            if (target) {

                target.classList.remove('hidden');

            }

        });

    });


    // Initial rendering

    renderAllPeople();


    // ── Calendar Leave and Holiday Modal ─────────────────────────────────────────

    const leaveByDay = <?= $leaveByDayJson ?: '{}' ?>;

    const holidayByDay = <?= $holidayByDayJson ?: '{}' ?>;

    const monthName = <?= json_encode($calMonthName) ?>;


    const leaveModal = document.getElementById('leaveModal');


    if (leaveModal) {

        leaveModal.addEventListener(
            'show.bs.modal',
            function(e) {

                const day = parseInt(
                    e.relatedTarget.dataset.day,
                    10
                );


                const leaveEntries = leaveByDay[day] || [];

                const holidayEntries = holidayByDay[day] || [];


                document.getElementById(
                        'leaveModalDate'
                    ).textContent =

                    monthName.replace(/\s\d{4}$/, '') +
                    ' ' +
                    day;


                const body = document.getElementById(
                    'leaveModalBody'
                );


                let html = '';


                // Holidays

                holidayEntries.forEach(holiday => {

                    html += `

                    <div class="adash-modal-row">

                        <div class="adash-modal-avatar">
                            🎉
                        </div>

                        <div class="adash-modal-info">

                            <strong>
                                ${escapePeopleHTML(holiday.name)}
                            </strong>

                            <span class="adash-modal-type">
                                ${escapePeopleHTML(holiday.type)}
                            </span>

                        </div>

                    </div>

                `;

                });


                // Leaves

                leaveEntries.forEach(emp => {

                    html += `

                    <div class="adash-modal-row">

                        <div class="adash-modal-avatar">

                            ${escapePeopleHTML(
                                emp.name.charAt(0)
                            )}

                        </div>


                        <div class="adash-modal-info">

                            <strong>
                                ${escapePeopleHTML(emp.name)}
                            </strong>

                            <span class="adash-modal-type">
                                ${escapePeopleHTML(emp.leave_type)}
                            </span>

                        </div>


                        <div class="adash-modal-meta">

                            <span class="adash-modal-days">

                                ${escapePeopleHTML(emp.days)}

                                day${Number(emp.days) !== 1 ? 's' : ''}

                            </span>


                            <span class="adash-modal-range">

                                ${escapePeopleHTML(emp.start)}

                                →

                                ${escapePeopleHTML(emp.end)}

                            </span>

                        </div>

                    </div>

                `;

                });


                if (!html) {

                    body.innerHTML = `

                    <p class="adash-modal-empty">
                        No calendar records for this day.
                    </p>

                `;

                } else {

                    body.innerHTML = html;

                }

            }
        );

    }


    // ── Update Calendar ─────────────────────────────────────────────────────────

    function updateCalendar() {

        const month = document.getElementById(
            'calMonthSelect'
        ).value;


        const year = document.getElementById(
            'calYearSelect'
        ).value;


        window.location.href = `?m=${year}-${month}`;

    }
</script>

</section>
</main>
</body>

</html>