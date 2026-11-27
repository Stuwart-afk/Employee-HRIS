<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('user_management', 'view', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Rbac.php';

$cssPageOverride = 'admin_dashboard';
date_default_timezone_set('Asia/Manila');

/* --------------------------------------------------------------------------
 | Permission-aware sections
 | The dashboard only shows panels the signed-in administrator may open,
 | using the same RBAC checks as the sidebar.
 * -------------------------------------------------------------------------- */
$dashRole = Rbac::normalizeRole($_SESSION['role'] ?? '');
$dashRank = Rbac::normalizeRank($_SESSION['rank'] ?? 'employee');
$can = static fn(string $module, string $ability = 'view'): bool => Rbac::can($pdo, $dashRole, $module, $ability, $dashRank);
$canAudit    = $can('audit_logs');
$canBackups  = $can('backup_management') && Rbac::canManageBackups($dashRole);
$canPayroll  = $can('payroll');
$canPerms    = $can('system_settings');
$canReports  = $can('reports');

/* --------------------------------------------------------------------------
 | Data helpers (read-only, tolerant of older schemas)
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

function adash_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Admin dashboard query failed: ' . $e->getMessage());
        return [];
    }
}

function adash_one(PDO $pdo, string $sql, array $params = []): array
{
    $rows = adash_rows($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function adash_count(PDO $pdo, string $sql, array $params = []): int
{
    $row = adash_one($pdo, $sql, $params);
    return $row ? (int)reset($row) : 0;
}

function adash_num($value): string
{
    return number_format((int)$value);
}

function adash_dec($value): string
{
    $n = (float)$value;
    return rtrim(rtrim(number_format($n, 2), '0'), '.');
}

function adash_pct(int $value, int $total): int
{
    return $total > 0 ? max(0, min(100, (int)round(($value / $total) * 100))) : 0;
}

function adash_ago(?string $datetime): string
{
    if (!$datetime) return 'Never';
    $ts = strtotime($datetime);
    if ($ts === false) return '—';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 86400 * 30) return floor($diff / 86400) . ' d ago';
    return date('M j, Y', $ts);
}

function adash_age_days(?string $datetime): int
{
    if (!$datetime) return 0;
    $ts = strtotime($datetime);
    return $ts === false ? 0 : max(0, (int)floor((time() - $ts) / 86400));
}

function adash_bytes($bytes): string
{
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '—';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int)min(count($units) - 1, floor(log($bytes, 1024)));
    return round($bytes / (1024 ** $i), $i > 1 ? 1 : 0) . ' ' . $units[$i];
}

function adash_url(array $override = []): string
{
    $query = array_merge(array_intersect_key($_GET, array_flip(['m', 'trend', 'year'])), $override);
    $query = array_filter($query, static fn($v): bool => $v !== null && $v !== '');
    return '?' . http_build_query($query);
}

function adash_action_label(string $action): string
{
    return ucwords(str_replace(['.', '_'], [' · ', ' '], $action));
}

function adash_action_tone(string $action): string
{
    $a = strtolower($action);
    foreach (['fail', 'denied', 'delete'] as $needle) {
        if (str_contains($a, $needle)) return 'danger';
    }
    foreach (['deactivate', 'reject', 'cancel', 'status_change', 'restore'] as $needle) {
        if (str_contains($a, $needle)) return 'warn';
    }
    foreach (['login', 'logout'] as $needle) {
        if ($a === $needle) return 'info';
    }
    return 'ok';
}

/* --------------------------------------------------------------------------
 | Calendar state
 * -------------------------------------------------------------------------- */
$rawM = $_GET['m'] ?? date('Y-m');
if (!is_string($rawM) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawM)) $rawM = date('Y-m');
[$calYear, $calMonth] = array_map('intval', explode('-', $rawM));
$calToday     = date('Y-m') === $rawM ? (int)date('j') : 0;
$calFirstDay  = (int)date('N', mktime(0, 0, 0, $calMonth, 1, $calYear)) % 7;
$calDays      = (int)date('t', mktime(0, 0, 0, $calMonth, 1, $calYear));
$calMonthName = date('F Y', mktime(0, 0, 0, $calMonth, 1, $calYear));
$calMonthStart = sprintf('%04d-%02d-01', $calYear, $calMonth);
$calMonthEnd   = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $calDays);
$prevHref = adash_url(['m' => date('Y-m', mktime(0, 0, 0, $calMonth - 1, 1, $calYear))]) . '#adash-calendar';
$nextHref = adash_url(['m' => date('Y-m', mktime(0, 0, 0, $calMonth + 1, 1, $calYear))]) . '#adash-calendar';
$monthOptions = [];
for ($i = 1; $i <= 12; $i++) $monthOptions[] = ['val' => str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'lbl' => date('F', mktime(0, 0, 0, $i, 1))];
$currentYear = (int)date('Y');
$yearOptions = range($currentYear + 1, $currentYear - 5);
if (!in_array($calYear, $yearOptions, true)) {
    $yearOptions[] = $calYear;
    rsort($yearOptions);
}

/* --------------------------------------------------------------------------
 | Workforce
 * -------------------------------------------------------------------------- */
$emp = adash_one($pdo, "SELECT COUNT(*) AS total,
        SUM(employment_status = 'Active') AS active,
        SUM(employment_status <> 'Active') AS inactive,
        SUM(date_hired >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS hired_this_month,
        SUM(date_hired >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND date_hired < DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS hired_last_month
    FROM employees");
$totalEmployees   = (int)($emp['total'] ?? 0);
$activeEmployees  = (int)($emp['active'] ?? 0);
$inactiveEmployees = (int)($emp['inactive'] ?? 0);
$hiredThisMonth   = (int)($emp['hired_this_month'] ?? 0);
$hiredLastMonth   = (int)($emp['hired_last_month'] ?? 0);
$onLeaveEmployees = adash_count($pdo, "SELECT COUNT(DISTINCT employee_id) FROM leave_requests WHERE status = 'Approved' AND CURDATE() BETWEEN start_date AND end_date");

/* --------------------------------------------------------------------------
 | Accounts & access
 * -------------------------------------------------------------------------- */
$acct = adash_one($pdo, "SELECT COUNT(*) AS total,
        SUM(status = 'Active') AS active,
        SUM(status <> 'Active') AS inactive,
        SUM(status = 'Active' AND setup_completed = 0) AS pending_setup,
        SUM(status = 'Active' AND last_activity >= NOW() - INTERVAL 15 MINUTE) AS online,
        SUM(status = 'Active' AND last_activity >= NOW() - INTERVAL 24 HOUR) AS active_24h,
        SUM(status = 'Active' AND role = 'admin') AS admins
    FROM users");
$totalUsers       = (int)($acct['total'] ?? 0);
$activeUsers      = (int)($acct['active'] ?? 0);
$inactiveUsers    = (int)($acct['inactive'] ?? 0);
$pendingSetupUsers = (int)($acct['pending_setup'] ?? 0);
$onlineUsers      = (int)($acct['online'] ?? 0);
$active24hUsers   = (int)($acct['active_24h'] ?? 0);
$activeAdmins     = (int)($acct['admins'] ?? 0);
$expiredInvites   = adash_table_exists($pdo, 'user_setup_tokens')
    ? adash_count($pdo, "SELECT COUNT(*) FROM users u WHERE u.status = 'Active' AND u.setup_completed = 0 AND NOT EXISTS (SELECT 1 FROM user_setup_tokens t WHERE t.user_id = u.id AND t.used_at IS NULL AND t.expires_at > NOW())")
    : 0;
$roleMix = adash_table_exists($pdo, 'roles')
    ? adash_rows($pdo, "SELECT u.role AS role_key, COALESCE(r.role_name, u.role) AS label, COUNT(*) AS total FROM users u LEFT JOIN roles r ON r.role_key = u.role WHERE u.status = 'Active' GROUP BY u.role, r.role_name ORDER BY total DESC, label")
    : adash_rows($pdo, "SELECT role AS role_key, role AS label, COUNT(*) AS total FROM users WHERE status = 'Active' GROUP BY role ORDER BY total DESC");
$roleTotal = array_sum(array_map('intval', array_column($roleMix, 'total')));

/* --------------------------------------------------------------------------
 | Approval queues
 * -------------------------------------------------------------------------- */
$leaveQ = adash_one($pdo, "SELECT COUNT(*) AS total, COALESCE(SUM(number_of_days), 0) AS days, MIN(created_at) AS oldest, SUM(created_at < NOW() - INTERVAL 3 DAY) AS stale FROM leave_requests WHERE status = 'Pending'");
$pendingLeave     = (int)($leaveQ['total'] ?? 0);
$pendingLeaveDays = (float)($leaveQ['days'] ?? 0);
$leaveOldest      = $leaveQ['oldest'] ?? null;
$leaveStale       = (int)($leaveQ['stale'] ?? 0);

$otQ = adash_table_exists($pdo, 'overtime_requests')
    ? adash_one($pdo, "SELECT COUNT(*) AS total, MIN(created_at) AS oldest, SUM(created_at < NOW() - INTERVAL 3 DAY) AS stale FROM overtime_requests WHERE status = 'Pending'")
    : [];
$pendingOvertime = (int)($otQ['total'] ?? 0);
$overtimeOldest  = $otQ['oldest'] ?? null;
$overtimeStale   = (int)($otQ['stale'] ?? 0);

$pendingPromotions = adash_table_exists($pdo, 'promotion_requests')
    ? adash_count($pdo, "SELECT COUNT(*) FROM promotion_requests WHERE LOWER(status) NOT IN ('approved', 'rejected', 'cancelled')")
    : 0;
$pendingSeparation = adash_table_exists($pdo, 'separation_settlements')
    ? adash_count($pdo, "SELECT COUNT(*) FROM separation_settlements WHERE status LIKE 'Pending%'")
    : 0;
$pendingCorrections = adash_table_exists($pdo, 'attendance_corrections')
    ? adash_count($pdo, "SELECT COUNT(*) FROM attendance_corrections WHERE status = 'Pending'")
    : 0;

$queues = [
    ['label' => 'Leave requests',         'count' => $pendingLeave,       'href' => '/hr/leave_management.php',   'tone' => 'violet', 'note' => $pendingLeave > 0 ? adash_dec($pendingLeaveDays) . ' days · oldest ' . adash_age_days($leaveOldest) . ' d' : ''],
    ['label' => 'Overtime requests',      'count' => $pendingOvertime,    'href' => '/hr/overtime.php',           'tone' => 'blue',   'note' => $pendingOvertime > 0 ? 'oldest ' . adash_age_days($overtimeOldest) . ' d' : ''],
    ['label' => 'Promotion reviews',      'count' => $pendingPromotions,  'href' => '/hr/promotion_requests.php', 'tone' => 'teal',   'note' => ''],
    ['label' => 'Attendance corrections', 'count' => $pendingCorrections, 'href' => '/hr/attendance.php',         'tone' => 'amber',  'note' => ''],
    ['label' => 'Separation clearances',  'count' => $pendingSeparation,  'href' => '/hr/benefits.php',           'tone' => 'danger', 'note' => ''],
];
$totalQueue = array_sum(array_column($queues, 'count'));
$queueMax   = max(1, ...array_column($queues, 'count'));
$staleQueue = $leaveStale + $overtimeStale;

/* --------------------------------------------------------------------------
 | Attendance today
 * -------------------------------------------------------------------------- */
$att = adash_one($pdo, "SELECT COUNT(DISTINCT employee_id) AS present,
        COUNT(DISTINCT CASE WHEN status = 'Late' THEN employee_id END) AS late,
        SUM(time_in IS NOT NULL AND time_out IS NULL) AS missing_out
    FROM attendance WHERE shift_date = CURDATE() OR DATE(time_in) = CURDATE()");
$presentToday   = (int)($att['present'] ?? 0);
$lateToday      = (int)($att['late'] ?? 0);
$missingClockOut = (int)($att['missing_out'] ?? 0);
$notClockedIn   = max(0, $activeEmployees - $presentToday - $onLeaveEmployees);
$attendanceCoverage = adash_pct($presentToday, $activeEmployees);
$barPresent = adash_pct($presentToday, $activeEmployees);
$barLeave   = min(100 - $barPresent, adash_pct($onLeaveEmployees, $activeEmployees));
$barAbsent  = max(0, 100 - $barPresent - $barLeave);

/* --------------------------------------------------------------------------
 | Payroll & benefits (latest period only)
 * -------------------------------------------------------------------------- */
$latestPeriod = $canPayroll ? adash_one($pdo, "SELECT id, period_name, start_date, end_date, pay_date, status FROM payroll_periods ORDER BY end_date DESC, id DESC LIMIT 1") : [];
$slip = !empty($latestPeriod['id'])
    ? adash_one($pdo, "SELECT COUNT(*) AS total, SUM(status = 'Draft') AS draft, SUM(status = 'Finalized') AS finalized, SUM(status = 'Paid') AS paid FROM payslips WHERE payroll_period_id = :id", [':id' => (int)$latestPeriod['id']])
    : [];
$slipTotal     = (int)($slip['total'] ?? 0);
$draftPayslips = (int)($slip['draft'] ?? 0);
$finalizedPayslips = (int)($slip['finalized'] ?? 0);
$paidPayslips  = (int)($slip['paid'] ?? 0);
$payslipDone   = adash_pct($finalizedPayslips + $paidPayslips, $slipTotal);
$activeBenefits = $canPayroll && adash_table_exists($pdo, 'employee_benefits') ? adash_count($pdo, "SELECT COUNT(*) FROM employee_benefits WHERE status = 'Active'") : 0;
$payDays = null;
if (!empty($latestPeriod['pay_date'])) {
    $payDays = (int)floor((strtotime((string)$latestPeriod['pay_date']) - strtotime(date('Y-m-d'))) / 86400);
}

/* --------------------------------------------------------------------------
 | Security, audit and resilience
 * -------------------------------------------------------------------------- */
$aud = $canAudit ? adash_one($pdo, "SELECT
        SUM(created_at >= NOW() - INTERVAL 24 HOUR) AS events_24h,
        SUM(action = 'login' AND created_at >= NOW() - INTERVAL 24 HOUR) AS logins_24h,
        SUM(action = 'login.failed' AND created_at >= NOW() - INTERVAL 24 HOUR) AS failed_24h,
        SUM(action = 'login.failed') AS failed_7d,
        SUM(action LIKE '%delete%' OR action LIKE '%deactivate%' OR action LIKE '%denied%' OR action = 'user.status_change') AS sensitive_7d
    FROM audit_logs WHERE created_at >= NOW() - INTERVAL 7 DAY") : [];
$audit24h      = (int)($aud['events_24h'] ?? 0);
$logins24h     = (int)($aud['logins_24h'] ?? 0);
$failedLogins24h = (int)($aud['failed_24h'] ?? 0);
$failedLogins7d  = (int)($aud['failed_7d'] ?? 0);
$sensitive7d   = (int)($aud['sensitive_7d'] ?? 0);

$auditDaily = $canAudit ? adash_rows($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS total, SUM(action = 'login.failed') AS failed FROM audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY d") : [];
$auditMap = [];
foreach ($auditDaily as $row) $auditMap[(string)$row['d']] = ['total' => (int)$row['total'], 'failed' => (int)$row['failed']];
$auditLabels = $auditTotals = $auditFailed = [];
for ($i = 6; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime("-{$i} day"));
    $auditLabels[] = date('D', strtotime($key));
    $auditTotals[] = $auditMap[$key]['total'] ?? 0;
    $auditFailed[] = $auditMap[$key]['failed'] ?? 0;
}
$recentAudit = $canAudit ? adash_rows($pdo, "SELECT al.action, al.entity_type, al.created_at, u.username AS actor FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id ORDER BY al.created_at DESC, al.id DESC LIMIT 8") : [];

$hasBackupTables = $canBackups && adash_table_exists($pdo, 'backup_settings') && adash_table_exists($pdo, 'automatic_backup_logs');
$backupCfg   = $hasBackupTables ? adash_one($pdo, "SELECT enabled, frequency, backup_time, retention_days FROM backup_settings ORDER BY id LIMIT 1") : [];
$backupEnabled = (int)($backupCfg['enabled'] ?? 0) === 1;
$backupFrequency = (string)($backupCfg['frequency'] ?? 'daily');
$lastBackup  = $hasBackupTables ? adash_one($pdo, "SELECT status, completed_at, started_at, file_size_bytes FROM automatic_backup_logs ORDER BY COALESCE(completed_at, started_at, created_at) DESC, id DESC LIMIT 1") : [];
$lastGoodBackup = $hasBackupTables ? adash_one($pdo, "SELECT completed_at, file_size_bytes FROM automatic_backup_logs WHERE status = 'success' ORDER BY completed_at DESC, id DESC LIMIT 1") : [];
$lastRestore = $canBackups && adash_table_exists($pdo, 'restore_history')
    ? adash_one($pdo, "SELECT status, completed_at, selected_filename FROM restore_history ORDER BY COALESCE(completed_at, started_at, created_at) DESC, id DESC LIMIT 1")
    : [];
$backupMaxHours = match ($backupFrequency) {
    'hourly' => 3,
    'weekly' => 192,
    default  => 36,
};
$goodBackupHours = !empty($lastGoodBackup['completed_at']) ? (int)floor((time() - strtotime((string)$lastGoodBackup['completed_at'])) / 3600) : null;
$backupStale = $hasBackupTables && $backupEnabled && ($goodBackupHours === null || $goodBackupHours > $backupMaxHours);

/* --------------------------------------------------------------------------
 | Needs-attention feed
 * -------------------------------------------------------------------------- */
$alerts = [];
$addAlert = static function (string $level, string $icon, string $title, string $detail, string $href) use (&$alerts): void {
    $alerts[] = ['level' => $level, 'icon' => $icon, 'title' => $title, 'detail' => $detail, 'href' => $href];
};
if ($hasBackupTables) {
    if (($lastBackup['status'] ?? '') === 'failed') $addAlert('danger', 'bi-database-x', 'Last automatic backup failed', 'Open backup management to review the error.', '/admin/backup_management.php');
    if (!$backupEnabled) $addAlert('warn', 'bi-database-slash', 'Automatic backups are turned off', 'No scheduled backups will run until re-enabled.', '/admin/backup_management.php');
    elseif ($backupStale) $addAlert('warn', 'bi-database-exclamation', 'No recent successful backup', $goodBackupHours === null ? 'No successful backup is on record.' : 'Last success was ' . adash_num($goodBackupHours) . ' hours ago.', '/admin/backup_management.php');
    if (($lastRestore['status'] ?? '') === 'failed') $addAlert('danger', 'bi-arrow-counterclockwise', 'Last restore attempt failed', (string)($lastRestore['selected_filename'] ?? 'Review restore history.'), '/admin/backup_management.php');
}
if ($canAudit && $failedLogins24h >= 5) {
    $addAlert($failedLogins24h >= 20 ? 'danger' : 'warn', 'bi-shield-exclamation', adash_num($failedLogins24h) . ' failed sign-ins in 24 hours', 'Check the audit trail for repeated attempts.', '/admin/audit_logs.php');
}
if ($expiredInvites > 0) $addAlert('warn', 'bi-envelope-exclamation', adash_num($expiredInvites) . ' expired account invitation' . ($expiredInvites === 1 ? '' : 's'), 'These users cannot finish setup until the invitation is resent.', '/admin/users.php');
if ($staleQueue > 0) $addAlert('warn', 'bi-hourglass-split', adash_num($staleQueue) . ' request' . ($staleQueue === 1 ? '' : 's') . ' waiting over 3 days', 'Leave and overtime approvals are overdue.', '/hr/leave_management.php');
if ($missingClockOut > 0) $addAlert('info', 'bi-box-arrow-right', adash_num($missingClockOut) . ' missing clock-out' . ($missingClockOut === 1 ? '' : 's') . ' today', 'Attendance records are still open.', '/hr/attendance.php');
if ($activeAdmins === 1) $addAlert('info', 'bi-person-exclamation', 'Only one active administrator account', 'Consider a second administrator for recovery.', '/admin/users.php');
$levelOrder = ['danger' => 0, 'warn' => 1, 'info' => 2];
usort($alerts, static fn(array $a, array $b): int => $levelOrder[$a['level']] <=> $levelOrder[$b['level']]);

/* --------------------------------------------------------------------------
 | Hiring trend
 * -------------------------------------------------------------------------- */
$trendMode = (($_GET['trend'] ?? 'monthly') === 'yearly') ? 'yearly' : 'monthly';
$trendYear = (int)($_GET['year'] ?? $currentYear);
if ($trendYear < 2000 || $trendYear > $currentYear) $trendYear = $currentYear;
$trendYears = range($currentYear, $currentYear - 5);
$growthChartType = $trendMode === 'yearly' ? 'line' : 'bar';
$growthMap = [];
if ($trendMode === 'yearly') {
    $growthRaw = adash_rows($pdo, "SELECT YEAR(date_hired) AS period_key, COUNT(*) AS total FROM employees WHERE date_hired IS NOT NULL AND YEAR(date_hired) BETWEEN :from_year AND :to_year GROUP BY YEAR(date_hired) ORDER BY YEAR(date_hired)", [':from_year' => $trendYear - 5, ':to_year' => $trendYear]);
    foreach ($growthRaw as $row) $growthMap[(int)$row['period_key']] = (int)$row['total'];
    $growthLabels = array_map('strval', range($trendYear - 5, $trendYear));
    $growthData = array_map(static fn(string $year): int => $growthMap[(int)$year] ?? 0, $growthLabels);
    $growthTitle = 'New hires by year';
    $growthSubtitle = 'Six-year hiring history';
} else {
    $growthRaw = adash_rows($pdo, "SELECT MONTH(date_hired) AS period_key, COUNT(*) AS total FROM employees WHERE date_hired IS NOT NULL AND YEAR(date_hired) = :year GROUP BY MONTH(date_hired) ORDER BY MONTH(date_hired)", [':year' => $trendYear]);
    foreach ($growthRaw as $row) $growthMap[(int)$row['period_key']] = (int)$row['total'];
    $growthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $growthData = [];
    for ($i = 1; $i <= 12; $i++) $growthData[] = $growthMap[$i] ?? 0;
    $growthTitle = 'New hires by month';
    $growthSubtitle = 'Monthly hiring record for ' . $trendYear;
}
$growthTotal = array_sum($growthData);
$growthPeakValue = $growthData ? max($growthData) : 0;
$growthPeakLabel = $growthPeakValue > 0 ? (string)$growthLabels[array_search($growthPeakValue, $growthData, true)] : '';

/* --------------------------------------------------------------------------
 | Workforce composition and departments
 * -------------------------------------------------------------------------- */
$employmentMix = adash_rows($pdo, "SELECT COALESCE(NULLIF(TRIM(employment_type), ''), 'Unspecified') AS label, COUNT(*) AS total FROM employees WHERE employment_status = 'Active' GROUP BY COALESCE(NULLIF(TRIM(employment_type), ''), 'Unspecified') ORDER BY total DESC, label");
$tenureRow = adash_one($pdo, "SELECT SUM(date_hired >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) AS under_one_year, SUM(date_hired < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AND date_hired >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR)) AS one_to_three_years, SUM(date_hired < DATE_SUB(CURDATE(), INTERVAL 3 YEAR)) AS over_three_years FROM employees WHERE employment_status = 'Active'");
$tenureMix = [
    'Under 1 year' => (int)($tenureRow['under_one_year'] ?? 0),
    '1–3 years'    => (int)($tenureRow['one_to_three_years'] ?? 0),
    '3+ years'     => (int)($tenureRow['over_three_years'] ?? 0),
];
$compositionMax = max(1, ...array_map('intval', array_merge(array_column($employmentMix, 'total'), array_values($tenureMix))));

$deptDist = adash_rows($pdo, "SELECT d.name, COUNT(e.id) AS total FROM departments d LEFT JOIN employees e ON e.department_id = d.id AND e.employment_status = 'Active' WHERE d.status = 'Active' GROUP BY d.id, d.name ORDER BY total DESC, d.name LIMIT 8");
$maxDeptCount = max(1, (int)($deptDist[0]['total'] ?? 1));
$totalDepartments = adash_count($pdo, "SELECT COUNT(*) FROM departments WHERE status = 'Active'");

/* --------------------------------------------------------------------------
 | Today at a glance and calendar
 * -------------------------------------------------------------------------- */
$todayLeaves = adash_rows($pdo, "SELECT e.first_name, e.last_name, lt.leave_name, lr.end_date FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.status = 'Approved' AND CURDATE() BETWEEN lr.start_date AND lr.end_date ORDER BY e.last_name, e.first_name LIMIT 5");
$todayBirthdays = adash_rows($pdo, "SELECT e.first_name, e.last_name, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.date_of_birth IS NOT NULL AND MONTH(e.date_of_birth) = MONTH(CURDATE()) AND DAY(e.date_of_birth) = DAY(CURDATE()) AND e.employment_status = 'Active' ORDER BY e.last_name LIMIT 5");
$upcomingHolidays = adash_rows($pdo, "SELECT holiday_name, holiday_date, holiday_type FROM holidays WHERE status = 'Active' AND holiday_date >= CURDATE() AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 45 DAY) ORDER BY holiday_date LIMIT 3");

$holidays = adash_rows($pdo, "SELECT holiday_name, holiday_date, holiday_type FROM holidays WHERE status = 'Active' AND holiday_date BETWEEN :ms AND :me ORDER BY holiday_date", [':ms' => $calMonthStart, ':me' => $calMonthEnd]);
$leaveRaw = adash_rows($pdo, "SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee_name, lt.leave_name AS leave_type, lr.start_date, lr.end_date, lr.number_of_days FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.status = 'Approved' AND lr.start_date <= :me AND lr.end_date >= :ms ORDER BY lr.start_date, employee_name", [':ms' => $calMonthStart, ':me' => $calMonthEnd]);
$monthBirthdays = adash_rows($pdo, "SELECT e.first_name, e.last_name, e.date_of_birth, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.date_of_birth IS NOT NULL AND MONTH(e.date_of_birth) = :month AND e.employment_status = 'Active' ORDER BY DAY(e.date_of_birth)", [':month' => $calMonth]);

$leaveByDay = $holidayByDay = $birthdayByDay = [];
$scheduleEvents = [];
$monthStartTs = strtotime($calMonthStart);
$monthEndTs   = strtotime($calMonthEnd);
foreach ($leaveRaw as $row) {
    $from = max(strtotime((string)$row['start_date']), $monthStartTs);
    $to   = min(strtotime((string)$row['end_date']), $monthEndTs);
    for ($ts = $from; $ts <= $to; $ts = strtotime('+1 day', $ts)) {
        $leaveByDay[(int)date('j', $ts)][] = ['name' => $row['employee_name'], 'leave_type' => $row['leave_type'], 'start' => $row['start_date'], 'end' => $row['end_date'], 'days' => $row['number_of_days']];
    }
    $scheduleEvents[] = ['date' => date('Y-m-d', $from), 'kind' => 'Leave', 'title' => $row['employee_name'], 'detail' => $row['leave_type'] . ' · ' . adash_dec($row['number_of_days']) . ' day(s)'];
}
foreach ($holidays as $holiday) {
    $day = (int)date('j', strtotime((string)$holiday['holiday_date']));
    $holidayByDay[$day][] = ['name' => $holiday['holiday_name'], 'type' => $holiday['holiday_type']];
    $scheduleEvents[] = ['date' => $holiday['holiday_date'], 'kind' => 'Holiday', 'title' => $holiday['holiday_name'], 'detail' => $holiday['holiday_type']];
}
foreach ($monthBirthdays as $birthday) {
    $day = (int)date('j', strtotime((string)$birthday['date_of_birth']));
    $name = trim($birthday['first_name'] . ' ' . $birthday['last_name']);
    $birthdayByDay[$day][] = ['name' => $name, 'dept' => $birthday['department_name'] ?: 'Employee'];
    $scheduleEvents[] = ['date' => sprintf('%04d-%02d-%02d', $calYear, $calMonth, min($day, $calDays)), 'kind' => 'Birthday', 'title' => $name, 'detail' => $birthday['department_name'] ?: 'Employee'];
}
usort($scheduleEvents, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
$agendaVisible = 6;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$leaveByDayJson    = json_encode($leaveByDay, $jsonFlags) ?: '{}';
$holidayByDayJson  = json_encode($holidayByDay, $jsonFlags) ?: '{}';
$birthdayByDayJson = json_encode($birthdayByDay, $jsonFlags) ?: '{}';

/* --------------------------------------------------------------------------
 | Presentation helpers
 * -------------------------------------------------------------------------- */
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$adminName = (string)($_SESSION['username'] ?? 'Administrator');
$hireDelta = $hiredThisMonth - $hiredLastMonth;

$shortcuts = array_filter([
    ['/admin/users.php',            'bi-person-gear',          'Manage users',      true],
    ['/admin/permissions.php',      'bi-shield-lock',          'Permissions',       $canPerms],
    ['/hr/employees.php',           'bi-people',               'Employee records',  $can('employee_records')],
    ['/hr/report.php',              'bi-file-earmark-bar-graph', 'Reports',         $canReports],
    ['/admin/audit_logs.php',       'bi-clipboard-data',       'Audit trail',       $canAudit],
    ['/admin/backup_management.php', 'bi-database-check',      'Backups',           $canBackups],
], static fn(array $s): bool => $s[3]);

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<section class="adash-shell">

    <header class="adash-intro">
        <div>
            <p class="adash-eyebrow"><i class="bi bi-command"></i> Administrator control center</p>
            <h1><?= h($greeting) ?>, <?= h($adminName) ?></h1>
            <p class="adash-subtitle">People, approvals, payroll, security and system resilience at a glance.</p>
        </div>
        <div class="adash-intro-tools">
            <span class="adash-intro-meta"><span class="adash-live-dot"></span> Updated <?= h(date('M j, Y · g:i A')) ?></span>
            <label class="adash-auto" title="Reload this page every 60 seconds"><input type="checkbox" id="autoRefresh"> Auto-refresh</label>
            <a class="adash-btn" href="<?= h(adash_url()) ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
        </div>
    </header>

    <nav class="adash-shortcuts" aria-label="Administrator shortcuts">
        <?php foreach ($shortcuts as [$href, $icon, $label]): ?>
            <a href="<?= h($href) ?>"><i class="bi <?= h($icon) ?>"></i><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
    </nav>

    <!-- Needs attention -->
    <section class="adash-attention <?= !empty($alerts) ? 'has-items' : 'is-clear' ?>" aria-label="Items needing attention">
        <div class="attention-head">
            <div>
                <p class="panel-kicker">Needs attention</p>
                <h2><?= empty($alerts) ? 'Everything looks healthy' : adash_num(count($alerts)) . ' item' . (count($alerts) === 1 ? '' : 's') . ' to review' ?></h2>
            </div>
            <?php if (empty($alerts)): ?><span class="attention-ok"><i class="bi bi-check-circle-fill"></i> No open issues</span><?php endif; ?>
        </div>
        <?php if (!empty($alerts)): ?>
            <div class="attention-list">
                <?php foreach ($alerts as $alert): ?>
                    <a class="attention-item <?= h($alert['level']) ?>" href="<?= h($alert['href']) ?>">
                        <span class="attention-icon"><i class="bi <?= h($alert['icon']) ?>"></i></span>
                        <span class="attention-text"><strong><?= h($alert['title']) ?></strong><small><?= h($alert['detail']) ?></small></span>
                        <i class="bi bi-chevron-right attention-go"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- KPIs -->
    <div class="adash-kpis">
        <a class="adash-kpi kpi-teal" href="/hr/employees.php">
            <span class="kpi-icon"><i class="bi bi-people"></i></span>
            <span class="kpi-label">Active workforce</span>
            <strong><?= adash_num($activeEmployees) ?></strong>
            <small><?= adash_num($totalEmployees) ?> total · <?= $hireDelta >= 0 ? '+' : '−' ?><?= adash_num(abs($hireDelta)) ?> hires vs last month</small>
        </a>
        <a class="adash-kpi kpi-blue" href="/admin/users.php">
            <span class="kpi-icon"><i class="bi bi-person-check"></i></span>
            <span class="kpi-label">Active accounts</span>
            <strong><?= adash_num($activeUsers) ?></strong>
            <small><?= adash_num($onlineUsers) ?> online now · <?= adash_num($pendingSetupUsers) ?> awaiting setup</small>
        </a>
        <a class="adash-kpi kpi-violet" href="/hr/leave_management.php">
            <span class="kpi-icon"><i class="bi bi-inbox"></i></span>
            <span class="kpi-label">Approval queue</span>
            <strong><?= adash_num($totalQueue) ?></strong>
            <small><?= $staleQueue > 0 ? adash_num($staleQueue) . ' waiting over 3 days' : ($totalQueue > 0 ? 'All within 3 days' : 'Nothing pending') ?></small>
        </a>
        <a class="adash-kpi kpi-green" href="/hr/attendance.php">
            <span class="kpi-icon"><i class="bi bi-clock-history"></i></span>
            <span class="kpi-label">Present today</span>
            <strong><?= adash_num($presentToday) ?></strong>
            <small><?= adash_num($attendanceCoverage) ?>% of active workforce</small>
            <span class="kpi-bar"><i style="width:<?= $attendanceCoverage ?>%"></i></span>
        </a>
    </div>

    <div class="adash-main-grid">

        <!-- Workforce today -->
        <article class="adash-panel adash-today-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Today</p>
                    <h2>Workforce &amp; attendance</h2>
                </div>
                <a class="panel-link" href="/hr/attendance.php">Open attendance <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="today-tiles">
                <div class="today-tile green"><strong><?= adash_num($presentToday) ?></strong><span>Present</span></div>
                <div class="today-tile amber"><strong><?= adash_num($lateToday) ?></strong><span>Late</span></div>
                <div class="today-tile violet"><strong><?= adash_num($onLeaveEmployees) ?></strong><span>On approved leave</span></div>
                <div class="today-tile blue"><strong><?= adash_num($notClockedIn) ?></strong><span>Not clocked in</span></div>
                <div class="today-tile danger"><strong><?= adash_num($missingClockOut) ?></strong><span>Missing clock-out</span></div>
            </div>
            <div class="stack-bar" role="img" aria-label="Present <?= $barPresent ?>%, on leave <?= $barLeave ?>%, not clocked in <?= $barAbsent ?>%">
                <i class="seg-green" style="width:<?= $barPresent ?>%"></i><i class="seg-violet" style="width:<?= $barLeave ?>%"></i><i class="seg-muted" style="width:<?= $barAbsent ?>%"></i>
            </div>
            <div class="stack-legend"><span><i class="seg-green"></i> Present <?= $barPresent ?>%</span><span><i class="seg-violet"></i> On leave <?= $barLeave ?>%</span><span><i class="seg-muted"></i> Not clocked in <?= $barAbsent ?>%</span></div>
            <div class="glance-grid">
                <div>
                    <h3><i class="bi bi-calendar2-x"></i> Away today</h3>
                    <?php if (empty($todayLeaves)): ?><p class="adash-empty">No one is on approved leave.</p><?php else: foreach ($todayLeaves as $l): ?>
                            <div class="glance-row"><span><?= h(trim($l['first_name'] . ' ' . $l['last_name'])) ?></span><small><?= h($l['leave_name']) ?> · until <?= h(date('M j', strtotime((string)$l['end_date']))) ?></small></div>
                    <?php endforeach;
                                                                                                            endif; ?>
                </div>
                <div>
                    <h3><i class="bi bi-gift"></i> Birthdays today</h3>
                    <?php if (empty($todayBirthdays)): ?><p class="adash-empty">No birthdays today.</p><?php else: foreach ($todayBirthdays as $b): ?>
                            <div class="glance-row"><span><?= h(trim($b['first_name'] . ' ' . $b['last_name'])) ?></span><small><?= h($b['department_name'] ?: 'Employee') ?></small></div>
                    <?php endforeach;
                                                                                                    endif; ?>
                </div>
                <div>
                    <h3><i class="bi bi-sun"></i> Upcoming holidays</h3>
                    <?php if (empty($upcomingHolidays)): ?><p class="adash-empty">None in the next 45 days.</p><?php else: foreach ($upcomingHolidays as $hd): ?>
                            <div class="glance-row"><span><?= h($hd['holiday_name']) ?></span><small><?= h(date('M j', strtotime((string)$hd['holiday_date']))) ?> · <?= h($hd['holiday_type']) ?></small></div>
                    <?php endforeach;
                                                                                                            endif; ?>
                </div>
            </div>
        </article>

        <!-- Approval queues -->
        <article class="adash-panel adash-queue-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Pending decisions</p>
                    <h2>Approval queues</h2>
                </div>
                <span class="panel-badge"><?= adash_num($totalQueue) ?> open</span>
            </div>
            <div class="queue-list">
                <?php foreach ($queues as $q): ?>
                    <a href="<?= h($q['href']) ?>" class="queue-row">
                        <span class="queue-dot <?= h($q['tone']) ?>"></span>
                        <span class="queue-label"><?= h($q['label']) ?><?php if ($q['note'] !== ''): ?><small><?= h($q['note']) ?></small><?php endif; ?></span>
                        <strong><?= adash_num($q['count']) ?></strong>
                        <span class="queue-bar"><i class="<?= h($q['tone']) ?>" style="width:<?= adash_pct((int)$q['count'], $queueMax) ?>%"></i></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($totalQueue === 0): ?><p class="adash-empty"><i class="bi bi-check2-circle"></i> All tracked approval queues are clear.</p><?php endif; ?>
        </article>

        <!-- Hiring trend -->
        <article class="adash-panel adash-chart-panel" id="adash-trend">
            <div class="panel-heading trend-heading">
                <div>
                    <p class="panel-kicker">Workforce movement</p>
                    <h2><?= h($growthTitle) ?></h2>
                    <span class="panel-note"><?= h($growthSubtitle) ?> · <?= adash_num($growthTotal) ?> hires<?= $growthPeakLabel !== '' ? ' · peak ' . h($growthPeakLabel) . ' (' . adash_num($growthPeakValue) . ')' : '' ?></span>
                </div>
                <form class="trend-controls" method="get" action="?#adash-trend">
                    <?php if (isset($_GET['m']) && is_string($_GET['m'])): ?><input type="hidden" name="m" value="<?= h($_GET['m']) ?>"><?php endif; ?>
                    <select name="trend" aria-label="Hiring trend view" onchange="this.form.submit()">
                        <option value="monthly" <?= $trendMode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="yearly" <?= $trendMode === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                    </select>
                    <select name="year" aria-label="Hiring trend year" onchange="this.form.submit()">
                        <?php foreach ($trendYears as $year): ?><option value="<?= $year ?>" <?= $year === $trendYear ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="trend-apply">Apply</button></noscript>
                </form>
            </div>
            <div class="chart-wrap"><canvas id="growthChart" aria-label="<?= h($growthTitle) ?> chart" role="img"></canvas></div>
            <div class="composition-snapshot">
                <div class="composition-heading"><strong><i class="bi bi-diagram-3"></i> Workforce composition</strong><span><?= adash_num($activeEmployees) ?> active employees</span></div>
                <div class="composition-columns">
                    <div>
                        <small>Employment type</small>
                        <?php if (empty($employmentMix)): ?><p class="adash-empty">No composition data available.</p><?php else: foreach ($employmentMix as $mix): ?>
                                <div class="composition-row"><span><?= h($mix['label']) ?></span>
                                    <div><i style="width:<?= adash_pct((int)$mix['total'], $compositionMax) ?>%"></i></div><b><?= adash_num($mix['total']) ?></b>
                                </div>
                        <?php endforeach;
                                                                                                                    endif; ?>
                    </div>
                    <div>
                        <small>Tenure profile</small>
                        <?php foreach ($tenureMix as $label => $total): ?>
                            <div class="composition-row"><span><?= h($label) ?></span>
                                <div><i style="width:<?= adash_pct($total, $compositionMax) ?>%"></i></div><b><?= adash_num($total) ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </article>

        <!-- Calendar -->
        <article class="adash-panel adash-calendar-panel" id="adash-calendar">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Schedule visibility</p>
                    <h2><?= h($calMonthName) ?></h2>
                </div>
                <a class="panel-link" href="/hr/leave_management.php">Manage leave <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="cal-controls">
                <a href="<?= h($prevHref) ?>" class="cal-nav-btn" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                <select id="calMonthSelect" class="cal-month-select" aria-label="Calendar month" onchange="updateCalendar()">
                    <?php foreach ($monthOptions as $opt): ?><option value="<?= h($opt['val']) ?>" <?= (int)$opt['val'] === $calMonth ? 'selected' : '' ?>><?= h($opt['lbl']) ?></option><?php endforeach; ?>
                </select>
                <select id="calYearSelect" class="cal-month-select" aria-label="Calendar year" onchange="updateCalendar()">
                    <?php foreach ($yearOptions as $year): ?><option value="<?= $year ?>" <?= $year === $calYear ? 'selected' : '' ?>><?= $year ?></option><?php endforeach; ?>
                </select>
                <a href="<?= h($nextHref) ?>" class="cal-nav-btn" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
            </div>
            <div class="cal-grid">
                <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
                <?php for ($blank = 0; $blank < $calFirstDay; $blank++): ?><div></div><?php endfor; ?>
                <?php for ($day = 1; $day <= $calDays; $day++):
                    $hasLeave = isset($leaveByDay[$day]);
                    $hasHoliday = isset($holidayByDay[$day]);
                    $hasBirthday = isset($birthdayByDay[$day]);
                    $hasEvent = $hasLeave || $hasHoliday || $hasBirthday;
                    $classes = 'cal-day' . ($day === $calToday ? ' cal-today' : '') . ($hasEvent ? ' cal-event-day' : '');
                ?>
                    <?php if ($hasEvent): ?>
                        <button type="button" class="<?= $classes ?>" data-day="<?= $day ?>" data-bs-toggle="modal" data-bs-target="#leaveModal" aria-label="View events for <?= h(date('F', mktime(0, 0, 0, $calMonth, 1, $calYear))) ?> <?= $day ?>"><?= $day ?><span class="cal-dots"><?php if ($hasLeave): ?><span class="cal-dot violet"></span><?php endif; ?><?php if ($hasHoliday): ?><span class="cal-dot teal"></span><?php endif; ?><?php if ($hasBirthday): ?><span class="cal-dot amber"></span><?php endif; ?></span></button>
                    <?php else: ?>
                        <div class="<?= $classes ?>"><?= $day ?></div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <div class="cal-legend"><span><i class="cal-dot violet"></i> Leave</span><span><i class="cal-dot teal"></i> Holiday</span><span><i class="cal-dot amber"></i> Birthday</span><span class="cal-today-key">Today</span></div>
            <div class="schedule-agenda">
                <div class="agenda-heading"><strong><i class="bi bi-list-check"></i> Month agenda</strong><span><?= adash_num(count($scheduleEvents)) ?> events</span></div>
                <div class="agenda-list">
                    <?php if (empty($scheduleEvents)): ?>
                        <p class="adash-empty">No leave, holidays, or birthdays are recorded for this month.</p>
                        <?php else: foreach ($scheduleEvents as $index => $event): ?>
                            <div class="agenda-row <?= $index >= $agendaVisible ? 'agenda-extra' : '' ?>" <?= $index >= $agendaVisible ? 'hidden' : '' ?>>
                                <time datetime="<?= h($event['date']) ?>"><b><?= h(date('j', strtotime((string)$event['date']))) ?></b><span><?= h(date('M', strtotime((string)$event['date']))) ?></span></time>
                                <span class="agenda-icon <?= h(strtolower($event['kind'])) ?>"><i class="bi <?= $event['kind'] === 'Holiday' ? 'bi-sun' : ($event['kind'] === 'Birthday' ? 'bi-gift' : 'bi-calendar-check') ?>"></i></span>
                                <div><strong><?= h($event['title']) ?></strong><small><?= h($event['kind']) ?> · <?= h($event['detail']) ?></small></div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
                <?php if (count($scheduleEvents) > $agendaVisible): ?>
                    <button type="button" class="agenda-more" id="agendaToggle" aria-expanded="false">Show all <?= adash_num(count($scheduleEvents)) ?> events</button>
                <?php endif; ?>
            </div>
        </article>

        <!-- Accounts & access -->
        <article class="adash-panel adash-access-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Identity &amp; access</p>
                    <h2>Accounts by role</h2>
                </div>
                <a class="panel-link" href="/admin/users.php">Users <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <?php if (empty($roleMix)): ?>
                <p class="adash-empty">No active accounts found.</p>
            <?php else: ?>
                <div class="stack-bar">
                    <?php foreach ($roleMix as $i => $role): ?><i class="role-<?= $i % 5 ?>" style="width:<?= adash_pct((int)$role['total'], $roleTotal) ?>%" title="<?= h($role['label']) ?>: <?= adash_num($role['total']) ?>"></i><?php endforeach; ?>
                </div>
                <div class="role-legend">
                    <?php foreach ($roleMix as $i => $role): ?><span><i class="role-<?= $i % 5 ?>"></i><?= h($role['label']) ?> <b><?= adash_num($role['total']) ?></b></span><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="stat-list">
                <a href="/admin/users.php" class="stat-row"><span><i class="bi bi-broadcast"></i> Online now</span><strong><?= adash_num($onlineUsers) ?></strong></a>
                <a href="/admin/users.php" class="stat-row"><span><i class="bi bi-person-check"></i> Active in last 24 hours</span><strong><?= adash_num($active24hUsers) ?></strong></a>
                <a href="/admin/users.php" class="stat-row <?= $pendingSetupUsers > 0 ? 'warn' : '' ?>"><span><i class="bi bi-person-lock"></i> Awaiting account setup</span><strong><?= adash_num($pendingSetupUsers) ?></strong></a>
                <a href="/admin/users.php" class="stat-row <?= $expiredInvites > 0 ? 'warn' : '' ?>"><span><i class="bi bi-envelope-exclamation"></i> Expired invitations</span><strong><?= adash_num($expiredInvites) ?></strong></a>
                <a href="/admin/users.php" class="stat-row"><span><i class="bi bi-person-slash"></i> Inactive accounts</span><strong><?= adash_num($inactiveUsers) ?></strong></a>
                <a href="/admin/users.php" class="stat-row"><span><i class="bi bi-shield-check"></i> Active administrators</span><strong><?= adash_num($activeAdmins) ?></strong></a>
            </div>
        </article>

        <!-- Payroll & benefits -->
        <?php if ($canPayroll): ?>
            <article class="adash-panel adash-payroll-panel">
                <div class="panel-heading">
                    <div>
                        <p class="panel-kicker">Compensation operations</p>
                        <h2>Payroll &amp; benefits</h2>
                    </div>
                    <a class="panel-link" href="/hr/payroll.php">Open payroll <i class="bi bi-arrow-up-right"></i></a>
                </div>
                <?php if (empty($latestPeriod)): ?>
                    <p class="adash-empty">No payroll period has been created yet.</p>
                <?php else: ?>
                    <div class="payroll-period">
                        <span>Latest period</span>
                        <strong><?= h($latestPeriod['period_name']) ?></strong>
                        <b class="status-chip <?= h(strtolower((string)$latestPeriod['status'])) ?>"><?= h($latestPeriod['status']) ?></b>
                        <small><?= h(date('M j', strtotime((string)$latestPeriod['start_date']))) ?> – <?= h(date('M j, Y', strtotime((string)$latestPeriod['end_date']))) ?> · pay date <?= h(date('M j', strtotime((string)$latestPeriod['pay_date']))) ?><?php if ($payDays !== null): ?> (<?= $payDays > 0 ? 'in ' . adash_num($payDays) . ' d' : ($payDays === 0 ? 'today' : adash_num(abs($payDays)) . ' d ago') ?>)<?php endif; ?></small>
                    </div>
                    <div class="progress-line">
                        <div><span>Payslips finalized or paid</span><b><?= $payslipDone ?>%</b></div>
                        <div class="progress-track"><i style="width:<?= $payslipDone ?>%"></i></div>
                    </div>
                <?php endif; ?>
                <div class="payroll-stats">
                    <div><strong><?= adash_num($draftPayslips) ?></strong><span>draft</span></div>
                    <div><strong><?= adash_num($finalizedPayslips) ?></strong><span>finalized</span></div>
                    <div><strong><?= adash_num($paidPayslips) ?></strong><span>paid</span></div>
                    <div><strong><?= adash_num($activeBenefits) ?></strong><span>active benefits</span></div>
                </div>
            </article>
        <?php endif; ?>

        <!-- Departments -->
        <article class="adash-panel adash-dept-panel">
            <div class="panel-heading">
                <div>
                    <p class="panel-kicker">Organization structure</p>
                    <h2>Department mix</h2>
                </div>
                <span class="panel-note"><?= adash_num($totalDepartments) ?> active departments</span>
            </div>
            <div class="dept-summary"><strong><?= adash_num($activeEmployees) ?></strong><span>active employees across the organization</span></div>
            <div class="dept-list">
                <?php if (empty($deptDist)): ?><p class="adash-empty">No department data available.</p><?php else: foreach ($deptDist as $dept): $count = (int)$dept['total']; ?>
                        <div class="dept-row"><span title="<?= h($dept['name']) ?>"><?= h($dept['name']) ?></span>
                            <div class="dept-track"><i style="width:<?= adash_pct($count, $maxDeptCount) ?>%"></i></div><strong><?= adash_num($count) ?></strong>
                        </div>
                <?php endforeach;
                                                                                                    endif; ?>
            </div>
        </article>

        <!-- System health -->
        <?php if ($canAudit || $canBackups): ?>
            <article class="adash-panel adash-security-panel">
                <div class="panel-heading">
                    <div>
                        <p class="panel-kicker">Governance &amp; resilience</p>
                        <h2>System health</h2>
                    </div>
                    <?php if ($canBackups): ?><a class="panel-link" href="/admin/backup_management.php">Backups <i class="bi bi-arrow-up-right"></i></a><?php endif; ?>
                </div>
                <div class="health-list">
                    <?php if ($canAudit): ?>
                        <div class="health-row"><span class="health-icon teal"><i class="bi bi-activity"></i></span>
                            <div><strong><?= adash_num($audit24h) ?> audit events</strong><small>recorded in the last 24 hours</small></div><em class="health-ok">Tracked</em>
                        </div>
                        <div class="health-row"><span class="health-icon <?= $failedLogins24h > 0 ? 'danger' : 'green' ?>"><i class="bi bi-shield-exclamation"></i></span>
                            <div><strong><?= adash_num($failedLogins24h) ?> failed sign-ins</strong><small><?= adash_num($failedLogins7d) ?> in the last 7 days</small></div><em class="<?= $failedLogins24h >= 5 ? 'health-warn' : 'health-ok' ?>"><?= $failedLogins24h >= 5 ? 'Review' : 'Normal' ?></em>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasBackupTables): ?>
                        <div class="health-row"><span class="health-icon blue"><i class="bi bi-database-check"></i></span>
                            <div><strong><?= $backupEnabled ? 'Automatic backup · ' . h(ucfirst($backupFrequency)) : 'Backup schedule disabled' ?></strong><small><?= !empty($lastGoodBackup['completed_at']) ? 'Last success ' . h(adash_ago($lastGoodBackup['completed_at'])) . ' · ' . h(adash_bytes($lastGoodBackup['file_size_bytes'] ?? 0)) : 'No successful backup on record' ?></small></div><em class="<?= ($backupEnabled && !$backupStale) ? 'health-ok' : 'health-warn' ?>"><?= ($backupEnabled && !$backupStale) ? 'Ready' : 'Action' ?></em>
                        </div>
                        <?php if (!empty($lastRestore)): ?>
                            <div class="health-row"><span class="health-icon <?= ($lastRestore['status'] ?? '') === 'failed' ? 'danger' : 'green' ?>"><i class="bi bi-arrow-counterclockwise"></i></span>
                                <div><strong>Last restore: <?= h(ucfirst((string)($lastRestore['status'] ?? 'unknown'))) ?></strong><small><?= h(adash_ago($lastRestore['completed_at'] ?? null)) ?></small></div><em class="<?= ($lastRestore['status'] ?? '') === 'failed' ? 'health-warn' : 'health-ok' ?>"><?= ($lastRestore['status'] ?? '') === 'failed' ? 'Review' : 'OK' ?></em>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>

        <!-- Audit activity -->
        <?php if ($canAudit): ?>
            <article class="adash-panel adash-activity-panel">
                <div class="panel-heading">
                    <div>
                        <p class="panel-kicker">Traceability</p>
                        <h2>Audit activity</h2>
                    </div>
                    <a class="panel-link" href="/admin/audit_logs.php">View all <i class="bi bi-arrow-up-right"></i></a>
                </div>
                <div class="audit-tiles">
                    <div><strong><?= adash_num($audit24h) ?></strong><span>events · 24 h</span></div>
                    <div><strong><?= adash_num($logins24h) ?></strong><span>sign-ins · 24 h</span></div>
                    <div class="<?= $failedLogins24h > 0 ? 'is-warn' : '' ?>"><strong><?= adash_num($failedLogins24h) ?></strong><span>failed sign-ins · 24 h</span></div>
                    <div class="<?= $sensitive7d > 0 ? 'is-warn' : '' ?>"><strong><?= adash_num($sensitive7d) ?></strong><span>sensitive actions · 7 d</span></div>
                </div>
                <div class="activity-split">
                    <div class="chart-wrap chart-small"><canvas id="auditChart" aria-label="Audit events over the last seven days" role="img"></canvas></div>
                    <div class="activity-list">
                        <?php if (empty($recentAudit)): ?><p class="adash-empty">No audit activity recorded yet.</p><?php else: foreach ($recentAudit as $entry): $tone = adash_action_tone((string)$entry['action']); ?>
                                <div class="activity-row">
                                    <span class="activity-icon <?= h($tone) ?>"><i class="bi bi-dot"></i></span>
                                    <div><strong><?= h(adash_action_label((string)$entry['action'])) ?></strong><small><?= h($entry['actor'] ?? 'System') ?> · <?= h($entry['entity_type'] ?? 'System') ?></small></div>
                                    <time title="<?= h(date('M j, Y g:i A', strtotime((string)$entry['created_at']))) ?>"><?= h(adash_ago($entry['created_at'])) ?></time>
                                </div>
                        <?php endforeach;
                                                                                                                endif; ?>
                    </div>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>

<div class="modal fade" id="leaveModal" tabindex="-1" aria-labelledby="leaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content adash-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveModalLabel"><i class="bi bi-calendar-event me-2"></i>Calendar events — <span id="leaveModalDate"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="leaveModalBody"></div>
            <div class="modal-footer"><a href="/hr/leave_management.php" class="adash-modal-btn">View all leave requests <i class="bi bi-arrow-up-right"></i></a></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    (function() {
        const css = getComputedStyle(document.documentElement);
        const color = (name, fallback) => (css.getPropertyValue(name) || fallback).trim() || fallback;
        const teal = color('--teal', '#76f5e8'),
            blue = color('--blue', '#3b82f6'),
            danger = color('--danger', '#fb7185'),
            muted = color('--muted', '#9aa6cf'),
            moon = color('--moon', '#aadcff');
        const grid = 'rgba(42,53,102,.55)';

        if (typeof Chart !== 'undefined') {
            Chart.defaults.font.family = 'Inter, Arial, sans-serif';
            Chart.defaults.color = muted;

            const growth = document.getElementById('growthChart');
            if (growth) {
                const ctx = growth.getContext('2d');
                const gradient = ctx.createLinearGradient(0, 0, 0, 240);
                gradient.addColorStop(0, 'rgba(118,245,232,.35)');
                gradient.addColorStop(1, 'rgba(59,130,246,.02)');
                const isLine = <?= json_encode($growthChartType) ?> === 'line';
                new Chart(ctx, {
                    type: <?= json_encode($growthChartType) ?>,
                    data: {
                        labels: <?= json_encode($growthLabels, $jsonFlags) ?>,
                        datasets: [{
                            data: <?= json_encode($growthData) ?>,
                            backgroundColor: isLine ? gradient : 'rgba(118,245,232,.78)',
                            borderColor: teal,
                            pointBackgroundColor: teal,
                            pointRadius: isLine ? 4 : 0,
                            tension: .35,
                            fill: isLine,
                            hoverBackgroundColor: moon,
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
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: grid
                                },
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }

            const audit = document.getElementById('auditChart');
            if (audit) new Chart(audit.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($auditLabels, $jsonFlags) ?>,
                    datasets: [{
                            label: 'Events',
                            data: <?= json_encode($auditTotals) ?>,
                            backgroundColor: 'rgba(59,130,246,.75)',
                            borderRadius: 5,
                            borderSkipped: false
                        },
                        {
                            label: 'Failed sign-ins',
                            data: <?= json_encode($auditFailed) ?>,
                            backgroundColor: danger,
                            borderRadius: 5,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: grid
                            },
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        } else {
            document.querySelectorAll('.chart-wrap').forEach(el => el.insertAdjacentHTML('beforeend', '<p class="adash-empty">Charts could not load. Check your internet connection.</p>'));
        }

        /* Calendar modal */
        const leaveByDay = <?= $leaveByDayJson ?>,
            holidayByDay = <?= $holidayByDayJson ?>,
            birthdayByDay = <?= $birthdayByDayJson ?>,
            monthName = <?= json_encode($calMonthName, $jsonFlags) ?>;
        const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        } [c]));
        const modal = document.getElementById('leaveModal');
        if (modal) modal.addEventListener('show.bs.modal', event => {
            const day = Number(event.relatedTarget?.dataset.day || 0);
            document.getElementById('leaveModalDate').textContent = `${monthName.replace(/\s\d{4}$/, '')} ${day}`;
            let html = '';
            (holidayByDay[day] || []).forEach(x => html += `<div class="adash-modal-row"><span class="modal-avatar teal"><i class="bi bi-sun"></i></span><div><strong>${esc(x.name)}</strong><small>${esc(x.type)}</small></div></div>`);
            (leaveByDay[day] || []).forEach(l => html += `<div class="adash-modal-row"><span class="modal-avatar violet"><i class="bi bi-person"></i></span><div><strong>${esc(l.name)}</strong><small>${esc(l.leave_type)} · ${esc(l.start)} → ${esc(l.end)}</small></div><b>${esc(l.days)} day${Number(l.days) !== 1 ? 's' : ''}</b></div>`);
            (birthdayByDay[day] || []).forEach(b => html += `<div class="adash-modal-row"><span class="modal-avatar amber"><i class="bi bi-gift"></i></span><div><strong>${esc(b.name)}</strong><small>Birthday · ${esc(b.dept)}</small></div></div>`);
            document.getElementById('leaveModalBody').innerHTML = html || '<p class="adash-modal-empty">No calendar records for this day.</p>';
        });

        /* Agenda expand / collapse */
        const agendaToggle = document.getElementById('agendaToggle');
        if (agendaToggle) agendaToggle.addEventListener('click', () => {
            const expand = agendaToggle.getAttribute('aria-expanded') !== 'true';
            document.querySelectorAll('.agenda-extra').forEach(row => row.hidden = !expand);
            agendaToggle.setAttribute('aria-expanded', String(expand));
            agendaToggle.textContent = expand ? 'Show fewer' : `Show all ${document.querySelectorAll('.agenda-row').length} events`;
        });

        /* Optional auto-refresh (remembered per browser) */
        const auto = document.getElementById('autoRefresh');
        let timer = null;
        const arm = () => {
            clearTimeout(timer);
            if (auto && auto.checked) timer = setTimeout(() => {
                if (!document.querySelector('.modal.show')) location.reload();
                else arm();
            }, 60000);
        };
        if (auto) {
            try {
                auto.checked = localStorage.getItem('adashAutoRefresh') === '1';
            } catch (e) {}
            auto.addEventListener('change', () => {
                try {
                    localStorage.setItem('adashAutoRefresh', auto.checked ? '1' : '0');
                } catch (e) {}
                arm();
            });
            arm();
        }
    })();

    function updateCalendar() {
        const m = document.getElementById('calMonthSelect').value,
            y = document.getElementById('calYearSelect').value;
        const params = new URLSearchParams(window.location.search);
        params.set('m', `${y}-${m}`);
        window.location.href = `?${params.toString()}#adash-calendar`;
    }
</script>

</section>
</main>
</body>

</html>