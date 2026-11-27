<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('user_management', 'view', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$cssPageOverride = 'admin_dashboard';
date_default_timezone_set('Asia/Manila');

/* --------------------------------------------------------------------------
 | Dashboard data helpers
 | Keep dashboard read-only and tolerant of older installations. The schema
 | has evolved over time, so optional tables are checked before querying them.
 * -------------------------------------------------------------------------- */
function adash_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        $stmt->execute([':table' => $table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function adash_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Admin dashboard count failed: ' . $e->getMessage());
        return 0;
    }
}

function adash_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Admin dashboard rows failed: ' . $e->getMessage());
        return [];
    }
}

function adash_one(PDO $pdo, string $sql, array $params = []): array
{
    $rows = adash_rows($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function adash_num($value): string
{
    return number_format((int)$value);
}

function adash_pct(int $value, int $total): int
{
    return $total > 0 ? max(0, min(100, (int)round(($value / $total) * 100))) : 0;
}

// Calendar state.
$rawM = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawM)) $rawM = date('Y-m');
[$calYear, $calMonth] = array_map('intval', explode('-', $rawM));
$calToday = date('Y-m') === $rawM ? (int)date('j') : 0;
$calFirstDay = (int)date('N', mktime(0, 0, 0, $calMonth, 1, $calYear)) % 7;
$calDays = (int)date('t', mktime(0, 0, 0, $calMonth, 1, $calYear));
$calMonthName = date('F Y', mktime(0, 0, 0, $calMonth, 1, $calYear));
$prevHref = '?m=' . date('Y-m', mktime(0, 0, 0, $calMonth - 1, 1, $calYear));
$nextHref = '?m=' . date('Y-m', mktime(0, 0, 0, $calMonth + 1, 1, $calYear));
$monthOptions = [];
for ($i = 1; $i <= 12; $i++) $monthOptions[] = ['val' => str_pad($i, 2, '0', STR_PAD_LEFT), 'lbl' => date('F', mktime(0, 0, 0, $i, 1))];
$currentYear = (int)date('Y');
$yearOptions = range($currentYear, $currentYear - 5);

// Core workforce and account health.
$totalEmployees = adash_count($pdo, "SELECT COUNT(*) FROM employees");
$activeEmployees = adash_count($pdo, "SELECT COUNT(*) FROM employees WHERE employment_status = 'Active'");
$onLeaveEmployees = adash_count($pdo, "SELECT COUNT(DISTINCT employee_id) FROM leave_requests WHERE status = 'Approved' AND CURDATE() BETWEEN start_date AND end_date");
$inactiveEmployees = adash_count($pdo, "SELECT COUNT(*) FROM employees WHERE employment_status <> 'Active'");
$totalUsers = adash_count($pdo, "SELECT COUNT(*) FROM users");
$activeUsers = adash_count($pdo, "SELECT COUNT(*) FROM users WHERE status = 'Active'");
$pendingSetupUsers = adash_count($pdo, "SELECT COUNT(*) FROM users WHERE status = 'Active' AND setup_completed = 0");
$onlineUsers = adash_count($pdo, "SELECT COUNT(*) FROM users WHERE status = 'Active' AND last_activity >= NOW() - INTERVAL 15 MINUTE");

// Operational queues.
$pendingLeave = adash_count($pdo, "SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'");
$pendingLeaveDays = adash_one($pdo, "SELECT COALESCE(SUM(number_of_days), 0) AS total FROM leave_requests WHERE status = 'Pending'");
$pendingOvertime = adash_table_exists($pdo, 'overtime_requests') ? adash_count($pdo, "SELECT COUNT(*) FROM overtime_requests WHERE status = 'Pending'") : 0;
$pendingPromotions = adash_table_exists($pdo, 'promotion_requests') ? adash_count($pdo, "SELECT COUNT(*) FROM promotion_requests WHERE status NOT IN ('approved', 'rejected', 'cancelled')") : 0;
$pendingSeparation = adash_table_exists($pdo, 'separation_settlements') ? adash_count($pdo, "SELECT COUNT(*) FROM separation_settlements WHERE status LIKE 'Pending%'") : 0;
$pendingAttendanceCorrections = adash_table_exists($pdo, 'attendance_corrections') ? adash_count($pdo, "SELECT COUNT(*) FROM attendance_corrections WHERE status = 'Pending'") : 0;
$totalQueue = $pendingLeave + $pendingOvertime + $pendingPromotions + $pendingSeparation + $pendingAttendanceCorrections;

// Attendance and payroll status.
$presentToday = adash_count($pdo, "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE shift_date = CURDATE() OR DATE(time_in) = CURDATE()");
$missingClockOut = adash_count($pdo, "SELECT COUNT(*) FROM attendance WHERE (shift_date = CURDATE() OR DATE(time_in) = CURDATE()) AND time_in IS NOT NULL AND time_out IS NULL");
$attendanceCoverage = adash_pct($presentToday, $activeEmployees);
$latestPeriod = adash_one($pdo, "SELECT id, period_name, start_date, end_date, pay_date, status FROM payroll_periods ORDER BY end_date DESC, id DESC LIMIT 1");
$draftPayslips = adash_count($pdo, "SELECT COUNT(*) FROM payslips WHERE status = 'Draft'");
$finalizedPayslips = adash_count($pdo, "SELECT COUNT(*) FROM payslips WHERE status = 'Finalized'");
$paidPayslips = adash_count($pdo, "SELECT COUNT(*) FROM payslips WHERE status = 'Paid'");
$activeBenefits = adash_count($pdo, "SELECT COUNT(*) FROM employee_benefits WHERE status = 'Active'");

// Security and resilience.
$audit24h = adash_count($pdo, "SELECT COUNT(*) FROM audit_logs WHERE created_at >= NOW() - INTERVAL 24 HOUR");
$failedSecurityEvents = adash_count($pdo, "SELECT COUNT(*) FROM audit_logs WHERE created_at >= NOW() - INTERVAL 7 DAY AND (LOWER(action) LIKE '%fail%' OR LOWER(action) LIKE '%denied%' OR LOWER(action) LIKE '%delete%')");
$lastAuditAt = adash_one($pdo, "SELECT created_at FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 1");
$backupEnabled = adash_count($pdo, "SELECT COUNT(*) FROM backup_settings WHERE enabled = 1") > 0;
$lastBackup = adash_one($pdo, "SELECT status, completed_at, filename FROM automatic_backup_logs ORDER BY COALESCE(completed_at, started_at, created_at) DESC, id DESC LIMIT 1");
$lastRestore = adash_one($pdo, "SELECT status, completed_at, selected_filename FROM restore_history ORDER BY COALESCE(completed_at, started_at, created_at) DESC, id DESC LIMIT 1");

// Hiring trend controls. Monthly shows the selected year's hiring cadence;
// yearly shows the recent historical record without inventing projections.
$trendMode = ($_GET['trend'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$trendYear = (int)($_GET['year'] ?? date('Y'));
if ($trendYear < 2000 || $trendYear > ((int)date('Y') + 1)) $trendYear = (int)date('Y');
$trendYears = range((int)date('Y'), (int)date('Y') - 5);
$growthChartType = $trendMode === 'yearly' ? 'line' : 'bar';
if ($trendMode === 'yearly') {
    $growthRaw = adash_rows($pdo, "SELECT YEAR(date_hired) AS period_key, COUNT(*) AS total FROM employees WHERE date_hired IS NOT NULL AND YEAR(date_hired) BETWEEN :from_year AND :to_year GROUP BY YEAR(date_hired) ORDER BY YEAR(date_hired)", [':from_year' => $trendYear - 5, ':to_year' => $trendYear]);
    $growthMap = [];
    foreach ($growthRaw as $row) $growthMap[(int)$row['period_key']] = (int)$row['total'];
    $growthLabels = range($trendYear - 5, $trendYear);
    $growthData = array_map(static fn(int $year): int => $growthMap[$year] ?? 0, $growthLabels);
    $growthTitle = 'New hires by year';
    $growthSubtitle = 'Six-year hiring history';
} else {
    $growthRaw = adash_rows($pdo, "SELECT MONTH(date_hired) AS period_key, COUNT(*) AS total FROM employees WHERE date_hired IS NOT NULL AND YEAR(date_hired) = :year GROUP BY MONTH(date_hired) ORDER BY MONTH(date_hired)", [':year' => $trendYear]);
    $growthMap = [];
    foreach ($growthRaw as $row) $growthMap[(int)$row['period_key']] = (int)$row['total'];
    $growthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $growthData = [];
    for ($i = 1; $i <= 12; $i++) $growthData[] = $growthMap[$i] ?? 0;
    $growthTitle = 'New hires by month';
    $growthSubtitle = 'Monthly hiring record for ' . $trendYear;
}
$growthTotal = array_sum($growthData);

// Workforce composition snapshot for the administrator overview.
$employmentMix = adash_rows($pdo, "SELECT COALESCE(NULLIF(TRIM(employment_type), ''), 'Unspecified') AS label, COUNT(*) AS total FROM employees WHERE employment_status = 'Active' GROUP BY COALESCE(NULLIF(TRIM(employment_type), ''), 'Unspecified') ORDER BY total DESC, label");
$tenureMix = adash_one($pdo, "SELECT SUM(date_hired >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) AS under_one_year, SUM(date_hired < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AND date_hired >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR)) AS one_to_three_years, SUM(date_hired < DATE_SUB(CURDATE(), INTERVAL 3 YEAR)) AS over_three_years FROM employees WHERE employment_status = 'Active'");
$tenureMix = [
    'Under 1 year' => (int)($tenureMix['under_one_year'] ?? 0),
    '1–3 years' => (int)($tenureMix['one_to_three_years'] ?? 0),
    '3+ years' => (int)($tenureMix['over_three_years'] ?? 0),
];
$compositionMax = max(1, max(array_map('intval', array_merge(array_column($employmentMix, 'total'), array_values($tenureMix)) ?: [1])));

// Department distribution.
$deptDist = adash_rows($pdo, "SELECT d.name, COUNT(e.id) AS total FROM departments d LEFT JOIN employees e ON e.department_id = d.id AND e.employment_status = 'Active' WHERE d.status = 'Active' GROUP BY d.id, d.name ORDER BY total DESC LIMIT 8");
$maxDeptCount = max(1, (int)($deptDist[0]['total'] ?? 1));
$totalDepartments = adash_count($pdo, "SELECT COUNT(*) FROM departments WHERE status = 'Active'");

// People and calendar records.
$birthdays = adash_rows($pdo, "SELECT e.first_name, e.last_name, e.date_of_birth, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.date_of_birth IS NOT NULL AND MONTH(e.date_of_birth) = :month AND e.employment_status = 'Active' ORDER BY DAY(e.date_of_birth)", [':month' => date('n')]);
$upcomingLeaves = adash_rows($pdo, "SELECT lr.start_date, lr.end_date, lr.number_of_days, e.first_name, e.last_name, lt.leave_name FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.status = 'Approved' AND lr.end_date >= CURDATE() AND lr.start_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY lr.start_date ASC LIMIT 30");
$todayBirthdays = adash_rows($pdo, "SELECT e.first_name, e.last_name, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.date_of_birth IS NOT NULL AND MONTH(e.date_of_birth) = MONTH(CURDATE()) AND DAY(e.date_of_birth) = DAY(CURDATE()) AND e.employment_status = 'Active'");
$todayLeaves = adash_rows($pdo, "SELECT e.first_name, e.last_name, lt.leave_name, lr.start_date, lr.end_date FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.status = 'Approved' AND CURDATE() BETWEEN lr.start_date AND lr.end_date ORDER BY e.last_name LIMIT 10");
$holidays = adash_rows($pdo, "SELECT holiday_name, holiday_date, holiday_type, coverage, region FROM holidays WHERE status = 'Active' AND YEAR(holiday_date) = :year AND MONTH(holiday_date) = :month ORDER BY holiday_date", [':year' => $calYear, ':month' => $calMonth]);
$upcomingHolidays = adash_rows($pdo, "SELECT holiday_name, holiday_date, holiday_type FROM holidays WHERE status = 'Active' AND holiday_date >= CURDATE() AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY holiday_date");

$recentAudit = adash_rows($pdo, "SELECT al.action, al.entity_type, al.created_at, u.username AS actor FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id ORDER BY al.created_at DESC, al.id DESC LIMIT 7");
$auditTrail = adash_rows($pdo, "SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.username AS actor FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id ORDER BY al.created_at DESC, al.id DESC LIMIT 7");

// Calendar markers and modal data.
$leaveRows = adash_rows($pdo, "SELECT DAY(cd.calendar_date) AS day_num, CONCAT(e.first_name, ' ', e.last_name) AS employee_name, lt.leave_name AS leave_type, lr.start_date, lr.end_date, lr.number_of_days FROM (SELECT DATE_ADD(STR_TO_DATE(:month_start, '%Y-%m-%d'), INTERVAL nums.seq DAY) AS calendar_date FROM (SELECT 0 seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29 UNION SELECT 30) nums WHERE nums.seq < :days_in_month) cd JOIN leave_requests lr ON cd.calendar_date BETWEEN lr.start_date AND lr.end_date JOIN employees e ON e.id = lr.employee_id JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.status = 'Approved' ORDER BY day_num, employee_name", [':month_start' => sprintf('%04d-%02d-01', $calYear, $calMonth), ':days_in_month' => $calDays]);
$leaveDays = $leaveByDay = $holidayDays = $holidayByDay = [];
foreach ($leaveRows as $row) {
    $day = (int)$row['day_num'];
    $leaveDays[$day] = true;
    $leaveByDay[$day][] = ['name' => $row['employee_name'], 'leave_type' => $row['leave_type'], 'start' => $row['start_date'], 'end' => $row['end_date'], 'days' => $row['number_of_days']];
}
foreach ($holidays as $holiday) {
    $day = (int)date('j', strtotime($holiday['holiday_date']));
    $holidayDays[$day] = true;
    $holidayByDay[$day][] = ['name' => $holiday['holiday_name'], 'type' => $holiday['holiday_type']];
}
$monthBirthdays = adash_rows($pdo, "SELECT first_name, last_name, date_of_birth, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.date_of_birth IS NOT NULL AND MONTH(e.date_of_birth) = :month AND e.employment_status = 'Active' ORDER BY DAY(e.date_of_birth)", [':month' => $calMonth]);
$scheduleEvents = [];
foreach ($holidays as $holiday) {
    $scheduleEvents[] = ['date' => $holiday['holiday_date'], 'kind' => 'Holiday', 'title' => $holiday['holiday_name'], 'detail' => $holiday['holiday_type']];
}
foreach ($leaveRows as $leave) {
    $scheduleEvents[] = ['date' => sprintf('%04d-%02d-%02d', $calYear, $calMonth, (int)$leave['day_num']), 'kind' => 'Leave', 'title' => $leave['employee_name'], 'detail' => $leave['leave_type'] . ' · ' . $leave['number_of_days'] . ' day(s)'];
}
foreach ($monthBirthdays as $birthday) {
    $scheduleEvents[] = ['date' => sprintf('%04d-%02d-%02d', $calYear, $calMonth, (int)date('j', strtotime($birthday['date_of_birth']))), 'kind' => 'Birthday', 'title' => $birthday['first_name'] . ' ' . $birthday['last_name'], 'detail' => $birthday['department_name'] ?: 'Employee'];
}
usort($scheduleEvents, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$leaveByDayJson = json_encode($leaveByDay, $jsonFlags) ?: '{}';
$holidayByDayJson = json_encode($holidayByDay, $jsonFlags) ?: '{}';

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<section class="adash-shell">
    <div class="adash-intro">
        <div>
            <p class="adash-eyebrow"><i class="bi bi-command"></i> Administrator control center</p>
            <h1>System overview</h1>
            <p class="adash-subtitle">A precise operational view of people, approvals, payroll, security, and system resilience.</p>
        </div>
        <div class="adash-intro-meta"><span class="adash-live-dot"></span> Data current as of <?= h(date('M j, Y · g:i A')) ?></div>
    </div>

    <div class="adash-quick-actions">
        <div>
            <p class="panel-kicker">Administrator shortcuts</p>
            <h2>Common actions</h2>
        </div>
        <div class="quick-action-list"><a href="/admin/users.php"><i class="bi bi-person-gear"></i><span>Manage users</span></a><a href="/admin/permissions.php"><i class="bi bi-shield-lock"></i><span>Review permissions</span></a><a href="/hr/employees.php"><i class="bi bi-person-plus"></i><span>Employee records</span></a><a href="/hr/report.php"><i class="bi bi-file-earmark-bar-graph"></i><span>Open reports</span></a><a href="/admin/backup_management.php"><i class="bi bi-database-check"></i><span>Backup management</span></a><a href="/admin/audit_logs.php"><i class="bi bi-clipboard-data"></i><span>Audit trail</span></a></div>
    </div>

    <div class="adash-kpis">
        <a class="adash-kpi kpi-teal" href="/hr/employees.php"><span class="kpi-icon"><i class="bi bi-people"></i></span><span class="kpi-label">Active workforce</span><strong><?= adash_num($activeEmployees) ?></strong><small><?= adash_num($totalEmployees) ?> total employees</small></a>
        <a class="adash-kpi kpi-blue" href="/admin/users.php"><span class="kpi-icon"><i class="bi bi-person-check"></i></span><span class="kpi-label">Active accounts</span><strong><?= adash_num($activeUsers) ?></strong><small><?= adash_num($onlineUsers) ?> active in last 15 min</small></a>
        <a class="adash-kpi kpi-violet" href="/hr/leave_management.php"><span class="kpi-icon"><i class="bi bi-inbox"></i></span><span class="kpi-label">Approval queue</span><strong><?= adash_num($totalQueue) ?></strong><small><?= adash_num($pendingLeave) ?> leave · <?= adash_num($pendingOvertime) ?> overtime</small></a>
        <a class="adash-kpi kpi-green" href="/hr/attendance.php"><span class="kpi-icon"><i class="bi bi-clock-history"></i></span><span class="kpi-label">Present today</span><strong><?= adash_num($presentToday) ?></strong><small><?= adash_num($attendanceCoverage) ?>% of active workforce</small></a>
    </div>

    <div class="adash-main-grid">
        <article class="adash-panel adash-panel-wide">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Executive pulse</p>
                    <h2>Operational readiness</h2>
                </div><span class="panel-badge"><i class="bi bi-shield-check"></i> Read-only summary</span>
            </div>
            <div class="readiness-grid">
                <div class="readiness-card"><span class="readiness-icon teal"><i class="bi bi-person-lines-fill"></i></span>
                    <div><strong><?= adash_num($activeEmployees) ?></strong><span>active employees</span></div><b><?= adash_num($inactiveEmployees) ?> inactive</b>
                </div>
                <div class="readiness-card"><span class="readiness-icon blue"><i class="bi bi-person-lock"></i></span>
                    <div><strong><?= adash_num($pendingSetupUsers) ?></strong><span>accounts awaiting setup</span></div><b><?= adash_num($totalUsers) ?> total accounts</b>
                </div>
                <div class="readiness-card"><span class="readiness-icon violet"><i class="bi bi-calendar2-check"></i></span>
                    <div><strong><?= adash_num($onLeaveEmployees) ?></strong><span>on approved leave today</span></div><b><?= adash_num($pendingLeaveDays['total'] ?? 0) ?> pending days</b>
                </div>
                <div class="readiness-card"><span class="readiness-icon amber"><i class="bi bi-box-arrow-right"></i></span>
                    <div><strong><?= adash_num($missingClockOut) ?></strong><span>missing clock-out</span></div><b>today's attendance</b>
                </div>
            </div>
            <div class="readiness-secondary">
                <div><span><i class="bi bi-broadcast"></i> Online now</span><strong><?= adash_num($onlineUsers) ?></strong><small>active in the last 15 minutes</small></div>
                <div><span><i class="bi bi-pencil-square"></i> Attendance corrections</span><strong><?= adash_num($pendingAttendanceCorrections) ?></strong><small>awaiting administrator review</small></div>
            </div>
        </article>

        <article class="adash-panel adash-queue-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Needs attention</p>
                    <h2>Approval queues</h2>
                </div><i class="bi bi-arrow-up-right panel-heading-icon"></i>
            </div>
            <div class="queue-list">
                <a href="/hr/leave_management.php" class="queue-row"><span class="queue-dot violet"></span><span>Leave requests</span><strong><?= adash_num($pendingLeave) ?></strong></a>
                <a href="/hr/overtime.php" class="queue-row"><span class="queue-dot blue"></span><span>Overtime requests</span><strong><?= adash_num($pendingOvertime) ?></strong></a>
                <a href="/hr/promotion_requests.php" class="queue-row"><span class="queue-dot teal"></span><span>Promotion reviews</span><strong><?= adash_num($pendingPromotions) ?></strong></a>
                <a href="/hr/attendance.php" class="queue-row"><span class="queue-dot amber"></span><span>Attendance corrections</span><strong><?= adash_num($pendingAttendanceCorrections) ?></strong></a>
                <a href="/hr/benefits.php" class="queue-row"><span class="queue-dot danger"></span><span>Separation clearances</span><strong><?= adash_num($pendingSeparation) ?></strong></a>
            </div>
            <?php if ($totalQueue === 0): ?><p class="adash-empty">All tracked approval queues are clear.</p><?php endif; ?>
        </article>

        <article class="adash-panel adash-chart-panel">
            <div class="panel-heading trend-heading">
                <div>
                    <p class="panel-kicker">Workforce movement</p>
                    <h2><?= h($growthTitle) ?></h2><span class="panel-note"><?= h($growthSubtitle) ?> · <?= adash_num($growthTotal) ?> hires</span>
                </div>
                <form class="trend-controls" method="get"><select name="trend" aria-label="Hiring trend view">
                        <option value="monthly" <?= $trendMode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="yearly" <?= $trendMode === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                    </select><select name="year" aria-label="Hiring trend year"><?php foreach ($trendYears as $year): ?><option value="<?= $year ?>" <?= $year === $trendYear ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?></select><button type="submit" class="trend-apply"><i class="bi bi-arrow-repeat"></i></button></form>
            </div>
            <div class="chart-wrap"><canvas id="growthChart"></canvas></div>
            <div class="trend-history">
                <div class="trend-history-label">Historical record</div>
                <div class="trend-history-grid"><?php foreach ($growthLabels as $index => $label): ?><span><b><?= h((string)$label) ?></b><strong><?= adash_num($growthData[$index] ?? 0) ?></strong></span><?php endforeach; ?></div>
            </div>
            <div class="composition-snapshot">
                <div class="composition-heading"><strong><i class="bi bi-diagram-3"></i> Workforce composition</strong><span><?= adash_num($activeEmployees) ?> active employees</span></div>
                <div class="composition-columns">
                    <div><small>Employment type</small><?php if (empty($employmentMix)): ?><p class="adash-empty">No composition data available.</p><?php else: foreach ($employmentMix as $mix): ?><div class="composition-row"><span><?= h($mix['label']) ?></span>
                                    <div><i style="width:<?= adash_pct((int)$mix['total'], $compositionMax) ?>%"></i></div><b><?= adash_num($mix['total']) ?></b>
                                </div><?php endforeach;
                                                                                                                                                endif; ?></div>
                    <div><small>Tenure profile</small><?php foreach ($tenureMix as $label => $total): ?><div class="composition-row"><span><?= h($label) ?></span>
                                <div><i style="width:<?= adash_pct($total, $compositionMax) ?>%"></i></div><b><?= adash_num($total) ?></b>
                            </div><?php endforeach; ?></div>
                </div>
            </div>
        </article>

        <article class="adash-panel adash-calendar-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Schedule visibility</p>
                    <h2><?= h($calMonthName) ?></h2>
                </div><a class="panel-link" href="/hr/leave_management.php">Manage leave <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="cal-controls"><a href="<?= h($prevHref) ?>" class="cal-nav-btn" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a><select id="calMonthSelect" class="cal-month-select" onchange="updateCalendar()"><?php foreach ($monthOptions as $opt): ?><option value="<?= h($opt['val']) ?>" <?= (int)$opt['val'] === $calMonth ? 'selected' : '' ?>><?= h($opt['lbl']) ?></option><?php endforeach; ?></select><select id="calYearSelect" class="cal-month-select" onchange="updateCalendar()"><?php foreach ($yearOptions as $year): ?><option value="<?= $year ?>" <?= $year === $calYear ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?></select><a href="<?= h($nextHref) ?>" class="cal-nav-btn" aria-label="Next month"><i class="bi bi-chevron-right"></i></a></div>
            <div class="cal-grid"><?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?><?php for ($blank = 0; $blank < $calFirstDay; $blank++): ?><div></div><?php endfor; ?><?php for ($day = 1; $day <= $calDays; $day++): $hasLeave = isset($leaveDays[$day]);
                                                                                                                                                                                                                                            $hasHoliday = isset($holidayDays[$day]); ?><div class="cal-day <?= $day === $calToday ? 'cal-today' : '' ?> <?= ($hasLeave || $hasHoliday) ? 'cal-event-day' : '' ?>" <?= ($hasLeave || $hasHoliday) ? 'data-day="' . $day . '" data-bs-toggle="modal" data-bs-target="#leaveModal"' : '' ?>><?= $day ?><?php if ($hasLeave): ?><span class="cal-dot violet"></span><?php endif; ?><?php if ($hasHoliday): ?><span class="cal-dot teal"></span><?php endif; ?></div><?php endfor; ?></div>
            <div class="cal-legend"><span><i class="cal-dot violet"></i> Leave</span><span><i class="cal-dot teal"></i> Holiday</span><span class="cal-today-key">Today</span></div>
            <div class="schedule-agenda">
                <div class="agenda-heading"><strong><i class="bi bi-list-check"></i> Month agenda</strong><span><?= adash_num(count($scheduleEvents)) ?> events</span></div>
                <div class="agenda-list"><?php if (empty($scheduleEvents)): ?><p class="adash-empty">No leave, holidays, or birthdays are recorded for this month.</p><?php else: foreach (array_slice($scheduleEvents, 0, 10) as $event): ?><div class="agenda-row"><time datetime="<?= h($event['date']) ?>"><b><?= date('j', strtotime($event['date'])) ?></b><span><?= date('M', strtotime($event['date'])) ?></span></time><span class="agenda-icon <?= strtolower($event['kind']) ?>"><i class="bi <?= $event['kind'] === 'Holiday' ? 'bi-sun' : ($event['kind'] === 'Birthday' ? 'bi-gift' : 'bi-calendar-check') ?>"></i></span>
                                <div><strong><?= h($event['title']) ?></strong><small><?= h($event['kind']) ?> · <?= h($event['detail']) ?></small></div>
                            </div><?php endforeach;
                                                                                                                                                                        if (count($scheduleEvents) > 10): ?><p class="agenda-more">+ <?= adash_num(count($scheduleEvents) - 10) ?> more events in this month</p><?php endif;
                                                                                                                                                                                    endif; ?></div>
            </div>
        </article>

        <article class="adash-panel adash-payroll-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Compensation operations</p>
                    <h2>Payroll &amp; benefits</h2>
                </div><a class="panel-link" href="/hr/payroll.php">Open payroll <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="payroll-period"><span>Latest period</span><strong><?= h($latestPeriod['period_name'] ?? 'No payroll period') ?></strong><b class="status-chip <?= strtolower((string)($latestPeriod['status'] ?? 'none')) ?>"><?= h($latestPeriod['status'] ?? 'Not started') ?></b></div>
            <div class="payroll-stats">
                <div><strong><?= adash_num($draftPayslips) ?></strong><span>draft</span></div>
                <div><strong><?= adash_num($finalizedPayslips) ?></strong><span>finalized</span></div>
                <div><strong><?= adash_num($paidPayslips) ?></strong><span>paid</span></div>
                <div><strong><?= adash_num($activeBenefits) ?></strong><span>active benefits</span></div>
            </div>
        </article>

        <article class="adash-panel adash-dept-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Organization structure</p>
                    <h2>Department mix</h2>
                </div><span class="panel-note"><?= adash_num($totalDepartments) ?> active departments</span>
            </div>
            <div class="dept-summary"><strong><?= adash_num($activeEmployees) ?></strong><span>active employees distributed across the organization</span></div>
            <div class="dept-list"><?php if (empty($deptDist)): ?><p class="adash-empty">No department data available.</p><?php else: foreach ($deptDist as $dept): $count = (int)$dept['total']; ?><div class="dept-row"><span><?= h($dept['name']) ?></span>
                            <div class="dept-track"><i style="width:<?= adash_pct($count, $maxDeptCount) ?>%"></i></div><strong><?= adash_num($count) ?></strong>
                        </div><?php endforeach;
                                                                                                                        endif; ?></div>
        </article>

        <article class="adash-panel adash-security-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Governance &amp; resilience</p>
                    <h2>System health</h2>
                </div><a class="panel-link" href="/admin/audit_logs.php">Audit trail <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="health-list">
                <div class="health-row"><span class="health-icon teal"><i class="bi bi-activity"></i></span>
                    <div><strong><?= adash_num($audit24h) ?> audit events</strong><small>recorded in the last 24 hours</small></div><em class="health-ok">Tracked</em>
                </div>
                <div class="health-row"><span class="health-icon <?= $failedSecurityEvents > 0 ? 'danger' : 'green' ?>"><i class="bi bi-shield-exclamation"></i></span>
                    <div><strong><?= adash_num($failedSecurityEvents) ?> flagged events</strong><small>failures, denials, or deletes in 7 days</small></div><em class="<?= $failedSecurityEvents > 0 ? 'health-warn' : 'health-ok' ?>"><?= $failedSecurityEvents > 0 ? 'Review' : 'Clear' ?></em>
                </div>
                <div class="health-row"><span class="health-icon blue"><i class="bi bi-database-check"></i></span>
                    <div><strong><?= $backupEnabled ? 'Automatic backup enabled' : 'Backup schedule disabled' ?></strong><small><?= h($lastBackup['status'] ?? 'No backup run recorded') ?><?= !empty($lastBackup['completed_at']) ? ' · ' . h(date('M j, g:i A', strtotime($lastBackup['completed_at']))) : '' ?></small></div><em class="<?= $backupEnabled ? 'health-ok' : 'health-warn' ?>"><?= $backupEnabled ? 'Ready' : 'Action' ?></em>
                </div>
            </div>
        </article>

        <article class="adash-panel adash-activity-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Traceability</p>
                    <h2>Recent system activity</h2>
                </div><a class="panel-link" href="/admin/audit_logs.php">View all <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="activity-list"><?php if (empty($recentAudit)): ?><p class="adash-empty">No audit activity recorded yet.</p><?php else: foreach ($recentAudit as $entry): ?><div class="activity-row"><span class="activity-icon"><i class="bi bi-dot"></i></span>
                            <div><strong><?= h($entry['action']) ?></strong><small><?= h($entry['actor'] ?? 'System') ?> · <?= h($entry['entity_type'] ?? 'System') ?></small></div><time><?= h(date('M j, g:i A', strtotime($entry['created_at']))) ?></time>
                        </div><?php endforeach;
                                                                                                                                endif; ?></div>
        </article>
    </div>

</section>

<div class="modal fade" id="leaveModal" tabindex="-1" aria-labelledby="leaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content adash-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveModalLabel"><i class="bi bi-calendar-event me-2"></i>Calendar events — <span id="leaveModalDate"></span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="leaveModalBody"></div>
            <div class="modal-footer"><a href="/hr/leave_management.php" class="adash-modal-btn">View all leave requests <i class="bi bi-arrow-up-right"></i></a></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const growthCanvas = document.getElementById('growthChart');
    if (growthCanvas) new Chart(growthCanvas.getContext('2d'), {
        type: <?= json_encode($growthChartType) ?>,
        data: {
            labels: <?= json_encode($growthLabels) ?>,
            datasets: [{
                data: <?= json_encode($growthData) ?>,
                backgroundColor: 'rgba(118,245,232,.78)',
                borderColor: '#76f5e8',
                tension: .35,
                fill: true,
                hoverBackgroundColor: '#aadcff',
                borderRadius: 6,
                borderSkipped: false
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
                        label: c => ` ${c.raw} hired`
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#9aa6cf',
                        font: {
                            size: 10
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(42,53,102,.55)'
                    },
                    ticks: {
                        color: '#9aa6cf',
                        precision: 0
                    }
                }
            }
        }
    });
    const leaveByDay = <?= $leaveByDayJson ?>,
        holidayByDay = <?= $holidayByDayJson ?>,
        monthName = <?= json_encode($calMonthName) ?>;

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        } [c]));
    }
    const modal = document.getElementById('leaveModal');
    if (modal) modal.addEventListener('show.bs.modal', event => {
        const day = Number(event.relatedTarget?.dataset.day || 0),
            body = document.getElementById('leaveModalBody');
        document.getElementById('leaveModalDate').textContent = `${monthName.replace(/\s\d{4}$/,'')} ${day}`;
        let html = '';
        (holidayByDay[day] || []).forEach(h => html += `<div class="adash-modal-row"><span class="modal-avatar teal"><i class="bi bi-sun"></i></span><div><strong>${escapeHTML(h.name)}</strong><small>${escapeHTML(h.type)}</small></div></div>`);
        (leaveByDay[day] || []).forEach(l => html += `<div class="adash-modal-row"><span class="modal-avatar violet"><i class="bi bi-person"></i></span><div><strong>${escapeHTML(l.name)}</strong><small>${escapeHTML(l.leave_type)} · ${escapeHTML(l.start)} → ${escapeHTML(l.end)}</small></div><b>${escapeHTML(l.days)} day${Number(l.days)!==1?'s':''}</b></div>`);
        body.innerHTML = html || '<p class="adash-modal-empty">No calendar records for this day.</p>';
    });

    function updateCalendar() {
        const m = document.getElementById('calMonthSelect').value,
            y = document.getElementById('calYearSelect').value;
        window.location.href = `?m=${y}-${m}`;
    }
</script>

</section>
</main>
</body>

</html>