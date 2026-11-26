<?php
// Include-only: requesting this file by URL gives a 404.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $stats = [
        'active'          => 0,
        'inactive'        => 0,
        'depts'           => 0,
        'pending_leaves'  => 0,
        'new_hires_month' => 0,
        'new_hires_30d'   => 0,
    ];
    $headcount        = [];
    $monthlyHires     = ['labels' => [], 'counts' => []];
    $newHiresByDept   = [];
    $empTypeBreakdown = [];
    $sortBy           = 'department_name';
    $dir              = 'asc';
    $queryError       = 'No database connection available.';
    return;
}

$REPORT_ALLOW_EXPORT = $REPORT_ALLOW_EXPORT ?? true;

require_once __DIR__ . '/../algorithms/merge_sort.php';

// ─── Bubble Sort (DSA) ───────────────────────────────────────────────────────
if (!function_exists('bubble_sort_rows')) {
    function bubble_sort_rows(array $rows, string $key, string $dir = 'asc'): array {
        $n   = count($rows);
        $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = 0; $j < $n - $i - 1; $j++) {
                $a = $rows[$j][$key]     ?? null;
                $b = $rows[$j + 1][$key] ?? null;
                if (is_numeric($a) && is_numeric($b)) {
                    $cmp = ((float)$a) <=> ((float)$b);
                } else {
                    $cmp = mb_strtolower((string)$a, 'UTF-8') <=> mb_strtolower((string)$b, 'UTF-8');
                }
                if (($dir === 'asc' && $cmp > 0) || ($dir === 'desc' && $cmp < 0)) {
                    [$rows[$j], $rows[$j + 1]] = [$rows[$j + 1], $rows[$j]];
                }
            }
        }
        return $rows;
    }
}

if (!function_exists('detect_dept_name_column')) {
    function detect_dept_name_column(PDO $pdo): string {
        $cols = $pdo->query('SHOW COLUMNS FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        $colsLower = array_map('strtolower', $cols);
        foreach (['department_name', 'name', 'dept_name', 'title'] as $want) {
            $idx = array_search($want, $colsLower, true);
            if ($idx !== false) return $cols[$idx];
        }
        foreach ($cols as $c) {
            if (strtolower($c) !== 'id') return $c;
        }
        return 'name';
    }
}

if (!function_exists('detect_status_column')) {
    function detect_status_column(PDO $pdo): ?string {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM employees')->fetchAll(PDO::FETCH_COLUMN);
            $colsLower = array_map('strtolower', $cols);
            foreach (['employment_status', 'status', 'emp_status'] as $want) {
                $idx = array_search($want, $colsLower, true);
                if ($idx !== false) return $cols[$idx];
            }
        } catch (Throwable $e) {}
        return null;
    }
}

if (!function_exists('detect_hire_date_column')) {
    function detect_hire_date_column(array $empColsLower, array $empCols): ?string {
        foreach (['date_hired', 'hire_date', 'hired_at', 'created_at'] as $want) {
            $idx = array_search($want, $empColsLower, true);
            if ($idx !== false) return $empCols[$idx];
        }
        return null;
    }
}

// ─── Inputs ──────────────────────────────────────────────────────────────────
$allowedSort = ['department_name', 'active_count', 'inactive_count', 'new_hires_month', 'new_hires_6m', 'total'];
$sortBy      = in_array($_GET['sort_by'] ?? '', $allowedSort, true) ? $_GET['sort_by'] : 'department_name';
$dir         = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$export      = $_GET['export'] ?? '';
$queryError  = '';

// ─── Quick Stats & New Hires ─────────────────────────────────────────────────
$stats = [
    'active'          => 0,
    'inactive'        => 0,
    'depts'           => 0,
    'pending_leaves'  => 0,
    'new_hires_month' => 0,
    'new_hires_30d'   => 0,
];
$monthlyHires     = ['labels' => [], 'counts' => []];
$newHiresByDept   = [];
$empTypeBreakdown = [];

$statusCol    = null;
$hireDateCol  = null;
$empCols      = [];
$empColsLower = [];
$hasDeptFk    = false;

try {
    $empCols      = $pdo->query('SHOW COLUMNS FROM employees')->fetchAll(PDO::FETCH_COLUMN);
    $empColsLower = array_map('strtolower', $empCols);
    $hasDeptFk    = in_array('department_id', $empColsLower, true);
    $statusCol    = detect_status_column($pdo);
    $hireDateCol  = detect_hire_date_column($empColsLower, $empCols);

    $stats['depts'] = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();

    if ($statusCol) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE LOWER(`$statusCol`) = 'active'");
        $st->execute();
        $stats['active'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE LOWER(`$statusCol`) = 'inactive'");
        $st->execute();
        $stats['inactive'] = (int)$st->fetchColumn();
    } else {
        $stats['active'] = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    }

    try {
        $stats['pending_leaves'] = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'")->fetchColumn();
    } catch (Throwable $e) {
        $stats['pending_leaves'] = 0;
    }

    // Past 6 months buckets
    $buckets = [];
    for ($i = 5; $i >= 0; $i--) {
        $dt = new DateTime("first day of -{$i} month");
        $ym = $dt->format('Y-m');
        $buckets[$ym] = ['label' => $dt->format('M Y'), 'count' => 0];
    }

    if ($hireDateCol) {
        $stMonth = $pdo->prepare("
            SELECT COUNT(*)
            FROM employees
            WHERE `{$hireDateCol}` IS NOT NULL
              AND DATE_FORMAT(`{$hireDateCol}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ");
        $stMonth->execute();
        $stats['new_hires_month'] = (int)$stMonth->fetchColumn();

        $st30 = $pdo->prepare("
            SELECT COUNT(*)
            FROM employees
            WHERE `{$hireDateCol}` IS NOT NULL
              AND `{$hireDateCol}` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $st30->execute();
        $stats['new_hires_30d'] = (int)$st30->fetchColumn();

        $stTrend = $pdo->prepare("
            SELECT DATE_FORMAT(`{$hireDateCol}`, '%Y-%m') AS ym, COUNT(*) AS hired_count
            FROM employees
            WHERE `{$hireDateCol}` IS NOT NULL
              AND `{$hireDateCol}` >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
            GROUP BY ym
            ORDER BY ym ASC
        ");
        $stTrend->execute();
        foreach ($stTrend->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ym = (string)($r['ym'] ?? '');
            if (isset($buckets[$ym])) {
                $buckets[$ym]['count'] = (int)$r['hired_count'];
            }
        }

        if ($hasDeptFk) {
            $stDeptHires = $pdo->prepare("
                SELECT
                    COALESCE(department_id, 0) AS dept_id,
                    SUM(CASE WHEN DATE_FORMAT(`{$hireDateCol}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS hires_month,
                    SUM(CASE WHEN `{$hireDateCol}` >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH) THEN 1 ELSE 0 END) AS hires_6m
                FROM employees
                WHERE `{$hireDateCol}` IS NOT NULL
                GROUP BY COALESCE(department_id, 0)
            ");
            $stDeptHires->execute();
            foreach ($stDeptHires->fetchAll(PDO::FETCH_ASSOC) as $dh) {
                $newHiresByDept[(int)$dh['dept_id']] = [
                    'month' => (int)$dh['hires_month'],
                    '6m'    => (int)$dh['hires_6m'],
                ];
            }
        }
    }

    foreach ($buckets as $b) {
        $monthlyHires['labels'][] = $b['label'];
        $monthlyHires['counts'][] = $b['count'];
    }

    if (in_array('employment_type', $empColsLower, true)) {
        $stTypes = $pdo->query("
            SELECT COALESCE(NULLIF(TRIM(employment_type), ''), 'Unspecified') AS label, COUNT(*) AS cnt
            FROM employees
            GROUP BY label
            ORDER BY cnt DESC
        ");
        foreach ($stTypes->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $empTypeBreakdown[$t['label']] = (int)$t['cnt'];
        }
    }
} catch (Throwable $e) {
    $queryError = 'Stats error: ' . $e->getMessage();
}

// ─── Headcount by Department ─────────────────────────────────────────────────
$headcount = [];
try {
    $deptNameCol = detect_dept_name_column($pdo);

    if ($hasDeptFk && $statusCol) {
        $sql = "
            SELECT
                d.id,
                d.`{$deptNameCol}` AS department_name,
                COALESCE(SUM(CASE WHEN LOWER(e.`{$statusCol}`) = 'active'   THEN 1 ELSE 0 END), 0) AS active_count,
                COALESCE(SUM(CASE WHEN LOWER(e.`{$statusCol}`) = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
                COUNT(e.id) AS total
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id
            GROUP BY d.id, d.`{$deptNameCol}`
            ORDER BY d.`{$deptNameCol}` ASC
        ";
    } elseif ($hasDeptFk) {
        $sql = "
            SELECT
                d.id,
                d.`{$deptNameCol}` AS department_name,
                COUNT(e.id) AS active_count,
                0 AS inactive_count,
                COUNT(e.id) AS total
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id
            GROUP BY d.id, d.`{$deptNameCol}`
            ORDER BY d.`{$deptNameCol}` ASC
        ";
    } else {
        $sql = "
            SELECT
                d.id,
                d.`{$deptNameCol}` AS department_name,
                0 AS active_count,
                0 AS inactive_count,
                0 AS total
            FROM departments d
            ORDER BY d.`{$deptNameCol}` ASC
        ";
    }

    $headcount = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($headcount as &$row) {
        $did = (int)($row['id'] ?? 0);
        $row['new_hires_month'] = $newHiresByDept[$did]['month'] ?? 0;
        $row['new_hires_6m']    = $newHiresByDept[$did]['6m'] ?? 0;
    }
    unset($row);
} catch (Throwable $e) {
    $headcount = [];
    $queryError = ($queryError ? $queryError . ' | ' : '') . 'Headcount error: ' . $e->getMessage();
}

$headcount = bubble_sort_rows($headcount, $sortBy, $dir);

// ─── Live JSON Endpoint (No Page Reload) ────────────────────────────────────
$isLiveRequest = (isset($_GET['live_report']) && $_GET['live_report'] === '1')
    || (isset($_SERVER['HTTP_X_LIVE_REPORT']) && $_SERVER['HTTP_X_LIVE_REPORT'] === '1');

if ($isLiveRequest) {
    $deptChartLabels = [];
    $deptChartMonth  = [];
    $deptChart6M     = [];
    foreach ($headcount as $hc) {
        $deptChartLabels[] = (string)($hc['department_name'] ?? 'Department');
        $deptChartMonth[]  = (int)($hc['new_hires_month'] ?? 0);
        $deptChart6M[]     = (int)($hc['new_hires_6m'] ?? 0);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'ok'               => true,
        'updated_at'       => date('g:i:s A'),
        'stats'            => $stats,
        'headcount'        => $headcount,
        'monthlyHires'     => $monthlyHires,
        'deptChart'        => [
            'labels' => $deptChartLabels,
            'month'  => $deptChartMonth,
            'sixM'   => $deptChart6M,
        ],
        'empTypeBreakdown' => $empTypeBreakdown,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── CSV Export ──────────────────────────────────────────────────────────────
if ($REPORT_ALLOW_EXPORT && $export === 'headcount_csv') {
    $fname = 'headcount_by_department_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Department', 'Active', 'Inactive', 'New Hires (This Month)', 'New Hires (Past 6M)', 'Total']);
    foreach ($headcount as $r) {
        fputcsv($out, [
            $r['department_name'] ?? '',
            $r['active_count']    ?? 0,
            $r['inactive_count']  ?? 0,
            $r['new_hires_month'] ?? 0,
            $r['new_hires_6m']    ?? 0,
            $r['total']           ?? 0,
        ]);
    }
    fclose($out);
    exit;
}