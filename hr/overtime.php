<?php
// hr/overtime.php

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/overtime_logic.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$role = strtolower(trim((string)($_SESSION['role'] ?? 'employee')));
if (!in_array($role, ['hr', 'admin', 'manager'], true)) {
    header('Location: /employee/dashboard.php');
    exit;
}

$reviewerName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? strtoupper($role)));

function hot_ensure_table(PDO $pdo): void {
    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `overtime_requests` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `work_date` DATE NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `requested_minutes` INT NOT NULL DEFAULT 0,
            `approved_minutes` INT NOT NULL DEFAULT 0,
            `ot_type` VARCHAR(40) NOT NULL DEFAULT 'Regular Day',
            `reason` TEXT NOT NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'Pending',
            `reviewed_by` VARCHAR(120) NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_ot_emp_date` (`employee_id`, `work_date`),
            KEY `idx_ot_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function hot_handle_action(PDO $pdo, array $input, string $reviewerName): string {
    $act = (string)($input['hr_ot_action'] ?? '');
    if ($act !== 'approve' && $act !== 'reject') {
        throw new RuntimeException('Invalid overtime review action.');
    }

    $reqId = (int)($_POST['request_id'] ?? $input['request_id'] ?? 0);
    $rStmt = $pdo->prepare("SELECT * FROM `overtime_requests` WHERE `id` = ? LIMIT 1");
    $rStmt->execute([$reqId]);
    $req = $rStmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        throw new RuntimeException('Overtime request not found.');
    }

    $empId    = (int)$req['employee_id'];
    $workDate = (string)$req['work_date'];

    if ($act === 'approve') {
        $approvedMins = max(0, min(720, (int)($input['approved_minutes'] ?? $req['requested_minutes'])));
        $upd = $pdo->prepare("
            UPDATE `overtime_requests`
            SET `status` = 'Approved', `approved_minutes` = ?, `reviewed_by` = ?, `reviewed_at` = NOW()
            WHERE `id` = ?
        ");
        $upd->execute([$approvedMins, $reviewerName, $reqId]);
        $totalCredited = ot_sync_approved_to_attendance($pdo, $empId, $workDate);
        return 'Overtime approved (' . $approvedMins . ' mins). Total credited OT for ' . $workDate . ': ' . $totalCredited . ' mins.';
    }

    $upd = $pdo->prepare("
        UPDATE `overtime_requests`
        SET `status` = 'Rejected', `approved_minutes` = 0, `reviewed_by` = ?, `reviewed_at` = NOW()
        WHERE `id` = ?
    ");
    $upd->execute([$reviewerName, $reqId]);
    ot_sync_approved_to_attendance($pdo, $empId, $workDate);
    return 'Overtime request rejected (0 mins added to worked hours or pay).';
}

function hot_load_state(PDO $pdo, string $dateFrom, string $dateTo, string $deptF, string $statusF): array {
    $hasEmp     = ot_logic_has_table($pdo, 'employees');
    $hasDeptTbl = ot_logic_has_table($pdo, 'departments');
    $hasDeptId  = $hasEmp && ot_logic_has_col($pdo, 'employees', 'department_id');
    $hasDeptStr = $hasEmp && ot_logic_has_col($pdo, 'employees', 'department');
    $hasCode    = $hasEmp && ot_logic_has_col($pdo, 'employees', 'employee_code');

    $deptCol = 'name';
    if ($hasDeptTbl && ot_logic_has_col($pdo, 'departments', 'department_name')) {
        $deptCol = 'department_name';
    }

    $joinDept = ($hasEmp && $hasDeptTbl && $hasDeptId) ? "LEFT JOIN `departments` d ON d.`id` = e.`department_id`" : "";
    $deptExpr = ($joinDept !== '')
        ? "COALESCE(NULLIF(TRIM(d.`{$deptCol}`), ''), 'Unassigned')"
        : ($hasDeptStr ? "COALESCE(NULLIF(TRIM(e.`department`), ''), 'Unassigned')" : "'Unassigned'");
    $nameExpr = $hasEmp
        ? "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(e.`first_name`,''), ' ', COALESCE(e.`last_name`,''))), ''), CONCAT('Employee #', ot.`employee_id`))"
        : "CONCAT('Employee #', ot.`employee_id`)";
    $codeExpr = $hasCode ? "COALESCE(e.`employee_code`, '')" : "''";

    $sql = "
        SELECT ot.*, {$nameExpr} AS emp_name, {$codeExpr} AS employee_code, {$deptExpr} AS department_name
        FROM `overtime_requests` ot
        " . ($hasEmp ? "LEFT JOIN `employees` e ON e.`id` = ot.`employee_id` {$joinDept}" : "") . "
        WHERE ot.`work_date` BETWEEN :f_d AND :t_d
        ORDER BY FIELD(ot.`status`, 'Pending', 'Approved', 'Rejected', 'Cancelled'), ot.`work_date` DESC, ot.`id` DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':f_d' => $dateFrom, ':t_d' => $dateTo]);
    $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $departments = [];
    foreach ($allLogs as $row) {
        $dn = (string)($row['department_name'] ?? 'Unassigned');
        $departments[$dn] = $dn;
    }
    ksort($departments);

    $incidentLogs = array_values(array_filter($allLogs, static function (array $r) use ($statusF, $deptF): bool {
        if ($statusF !== '' && strcasecmp($statusF, 'all') !== 0 && strcasecmp((string)$r['status'], $statusF) !== 0) {
            return false;
        }
        if ($deptF !== '' && strcasecmp((string)($r['department_name'] ?? 'Unassigned'), $deptF) !== 0) {
            return false;
        }
        return true;
    }));

    $multipliers = [
        'Regular Day'                 => 1.25,
        'Rest Day'                    => 1.30,
        'Special Non-Working Holiday' => 1.30,
        'Regular Holiday'             => 2.00,
    ];

    $rateCache        = [];
    $summaryMap       = [];
    $kpiPendingCount  = 0;
    $kpiApprovedMins  = 0;
    $kpiSpecialOtMins = 0;
    $kpiTotalOtPay    = 0.0;

    foreach ($incidentLogs as &$r) {
        $eid = (int)$r['employee_id'];
        if (!isset($rateCache[$eid])) {
            $rateCache[$eid] = ot_get_employee_hourly_rate($pdo, $eid);
        }
        $hrRate = $rateCache[$eid];
        $mult   = $multipliers[(string)$r['ot_type']] ?? 1.25;
        $calcMins = $r['status'] === 'Approved' ? (int)$r['approved_minutes'] : (int)$r['requested_minutes'];
        $r['est_ot_pay'] = round(($calcMins / 60.0) * $hrRate * $mult, 2);

        if (!isset($summaryMap[$eid])) {
            $payCalc = ot_calculate_payroll_hours($pdo, $eid, $dateFrom, $dateTo, $hrRate);
            $summaryMap[$eid] = [
                'employee_id'        => $eid,
                'employee_code'      => (string)$r['employee_code'],
                'emp_name'           => (string)$r['emp_name'],
                'department_name'    => (string)$r['department_name'],
                'sessions'           => 0,
                'reg_mins'           => 0,
                'spec_mins'          => 0,
                'pend_mins'          => 0,
                'regular_hours'      => $payCalc['regular_hours'],
                'total_worked_hours' => $payCalc['total_worked_hours'],
                'overtime_pay'       => $payCalc['overtime_pay'],
                'total_pay'          => $payCalc['total_pay'],
            ];
        }

        if ($r['status'] === 'Pending') {
            $kpiPendingCount++;
            $summaryMap[$eid]['pend_mins'] += (int)$r['requested_minutes'];
        } elseif ($r['status'] === 'Approved') {
            $mins = (int)$r['approved_minutes'];
            $kpiApprovedMins += $mins;
            $kpiTotalOtPay   += $r['est_ot_pay'];
            $summaryMap[$eid]['sessions']++;
            if ($r['ot_type'] === 'Regular Day') {
                $summaryMap[$eid]['reg_mins'] += $mins;
            } else {
                $summaryMap[$eid]['spec_mins'] += $mins;
                $kpiSpecialOtMins += $mins;
            }
        }
    }
    unset($r);

    $employeeSummary = array_values($summaryMap);
    usort($employeeSummary, static function (array $a, array $b): int {
        return ($b['reg_mins'] + $b['spec_mins']) <=> ($a['reg_mins'] + $a['spec_mins']);
    });

    return [
        'server_time' => date('H:i:s'),
        'departments' => array_values($departments),
        'kpis' => [
            'pending_count'  => $kpiPendingCount,
            'approved_hrs'   => number_format($kpiApprovedMins / 60, 2),
            'approved_mins'  => $kpiApprovedMins,
            'special_hrs'    => number_format($kpiSpecialOtMins / 60, 2),
            'total_ot_pay'   => number_format($kpiTotalOtPay, 2),
            'employee_count' => count($employeeSummary),
        ],
        'incidents' => $incidentLogs,
        'summary'   => $employeeSummary,
    ];
}

$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-t');
$statusF  = trim((string)($_GET['status'] ?? 'all'));
$deptF    = trim((string)($_GET['dept'] ?? ''));

try {
    hot_ensure_table($pdo);
} catch (Throwable $e) {}

if (isset($_GET['export']) && $_GET['export'] === 'summary_csv') {
    $snap = hot_load_state($pdo, $dateFrom, $dateTo, $deptF, $statusF);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="overtime_payroll_summary_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee Code', 'Employee Name', 'Department', 'Regular Hours', 'Approved OT (hrs)', 'Total Worked Hours', 'Overtime Pay (PHP)', 'Total Gross Pay (PHP)']);
    foreach ($snap['summary'] as $item) {
        $totOtHrs = ((int)$item['reg_mins'] + (int)$item['spec_mins']) / 60.0;
        fputcsv($out, [
            $item['employee_code'],
            $item['emp_name'],
            $item['department_name'],
            number_format((float)$item['regular_hours'], 2),
            number_format($totOtHrs, 2),
            number_format((float)$item['total_worked_hours'], 2),
            number_format((float)$item['overtime_pay'], 2),
            number_format((float)$item['total_pay'], 2),
        ]);
    }
    fclose($out);
    exit;
}

$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === 'live_state')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $msg = hot_handle_action($pdo, $_POST, $reviewerName);
        }
        $payload = hot_load_state($pdo, $dateFrom, $dateTo, $deptF, $statusF);
        $payload['ok'] = true;
        $payload['message'] = $msg;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$flashOk  = '';
$flashErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $flashOk = hot_handle_action($pdo, $_POST, $reviewerName);
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

$initState = hot_load_state($pdo, $dateFrom, $dateTo, $deptF, $statusF);

$hrHeader = __DIR__ . '/../templates/hrTemplates/header.php';
if (is_file($hrHeader)) {
    require_once $hrHeader;
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<style>
  .hot-wrap { max-width: 1320px; margin: 20px auto 56px; padding: 0 20px; color: #f8fafc; }
  .hot-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .hot-title { margin: 0; font-size: 24px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; }
  .hot-sub { margin: 4px 0 0; font-size: 13px; color: #94a3b8; }
  .hot-live-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.16); color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .hot-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }
  .hot-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
  .hot-kpi { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 14px 16px; }
  .hot-kpi-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; }
  .hot-kpi-val { font-size: 24px; font-weight: 700; color: #fff; margin-top: 6px; }
  .hot-kpi-sub { font-size: 11.5px; color: #64748b; margin-top: 2px; }
  .hot-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; }
  .hot-card h2 { margin: 0 0 12px; font-size: 16px; color: #fff; }
  .hot-input, .hot-select {
    padding: 7px 10px; border-radius: 8px; border: 1px solid #334155;
    background: #0f172a; color: #f8fafc; font-size: 13px;
  }
  .hot-btn {
    padding: 7px 14px; border-radius: 8px; border: none; background: #2563eb;
    color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
  }
  .hot-btn:hover { background: #1d4ed8; }
  .hot-btn-export { background: #059669; }
  .hot-btn-export:hover { background: #047857; }
  .hot-btn-ok { padding: 5px 10px; border-radius: 6px; border: none; background: #16a34a; color: #fff; font-size: 11.5px; font-weight: 600; cursor: pointer; }
  .hot-btn-no { padding: 5px 10px; border-radius: 6px; border: none; background: #dc2626; color: #fff; font-size: 11.5px; font-weight: 600; cursor: pointer; }
  .hot-table-wrap { overflow-x: auto; }
  .hot-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .hot-table th, .hot-table td { padding: 10px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .hot-table th { font-size: 11px; text-transform: uppercase; color: #94a3b8; background: #0f172a; }
  .hot-badge { padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-block; }
  .b-app { background: rgba(22,163,74,0.2); color: #4ade80; }
  .b-pen { background: rgba(245,158,11,0.2); color: #fbbf24; }
  .b-rej { background: rgba(239,68,68,0.2); color: #f87171; }
  .b-can { background: rgba(148,163,184,0.2); color: #cbd5e1; }
  .hot-alert-ok { background: rgba(22,163,74,0.18); border: 1px solid rgba(74,222,128,0.4); color: #bbf7d0; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .hot-alert-err { background: rgba(239,68,68,0.18); border: 1px solid rgba(248,113,113,0.4); color: #fecaca; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }

  .dataTables_wrapper { color: #e2e8f0; font-size: 12.5px; margin-top: 8px; }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: #0f172a; color: #f8fafc; border: 1px solid #334155; border-radius: 7px; padding: 4px 8px; margin: 0 4px;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate {
    color: #94a3b8 !important; padding-top: 10px;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #e2e8f0 !important; border: 1px solid rgba(148,163,184,0.2) !important;
    background: rgba(15,23,42,0.75) !important; border-radius: 6px !important; margin-left: 3px;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #2563eb !important; border-color: #2563eb !important; color: #fff !important;
  }
  table.dataTable.no-footer { border-bottom: 1px solid rgba(148,163,184,0.18) !important; }
</style>

<div class="hot-wrap">
  <div class="hot-top">
    <div>
      <h1 class="hot-title">
        <span>Overtime Management</span>
        <span class="hot-live-pill"><span class="hot-live-dot"></span> <span id="hotLiveText">LIVE SYNC</span></span>
      </h1>
      <p class="hot-sub">Approved overtime automatically adds to employee worked hours and overtime pay.</p>
    </div>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
      <input type="date" name="from" id="hotFrom" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="hot-input">
      <span style="color:#64748b;">to</span>
      <input type="date" name="to" id="hotTo" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="hot-input">
      <select name="dept" id="hotDept" class="hot-select">
        <option value="">All Departments</option>
        <?php foreach ($initState['departments'] as $d): ?>
          <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>" <?= $deptF === $d ? 'selected' : '' ?>>
            <?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="status" id="hotStatus" class="hot-select">
        <option value="all" <?= $statusF === 'all' ? 'selected' : '' ?>>All Statuses</option>
        <option value="Pending" <?= $statusF === 'Pending' ? 'selected' : '' ?>>Pending</option>
        <option value="Approved" <?= $statusF === 'Approved' ? 'selected' : '' ?>>Approved</option>
        <option value="Rejected" <?= $statusF === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
      <button type="submit" class="hot-btn">Filter</button>
      <a href="?from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>&dept=<?= urlencode($deptF) ?>&status=<?= urlencode($statusF) ?>&export=summary_csv" class="hot-btn hot-btn-export">Export Payroll OT CSV</a>
    </form>
  </div>

  <div id="hotAlertBox">
    <?php if ($flashOk !== ''): ?>
      <div class="hot-alert-ok"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="hot-alert-err"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>

  <div class="hot-kpis">
    <div class="hot-kpi">
      <div class="hot-kpi-lbl">Pending OT Requests</div>
      <div class="hot-kpi-val" id="hotKpiPending"><?= (int)$initState['kpis']['pending_count'] ?></div>
      <div class="hot-kpi-sub">Awaiting HR approval</div>
    </div>
    <div class="hot-kpi">
      <div class="hot-kpi-lbl">Total Approved OT</div>
      <div class="hot-kpi-val" id="hotKpiApproved"><?= htmlspecialchars($initState['kpis']['approved_hrs'], ENT_QUOTES, 'UTF-8') ?> hrs</div>
      <div class="hot-kpi-sub" id="hotKpiApprovedMins"><?= (int)$initState['kpis']['approved_mins'] ?> credited minutes</div>
    </div>
    <div class="hot-kpi">
      <div class="hot-kpi-lbl">Approved OT Pay Impact</div>
      <div class="hot-kpi-val" id="hotKpiOtPay">₱<?= htmlspecialchars($initState['kpis']['total_ot_pay'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="hot-kpi-sub">Added to employee pay</div>
    </div>
    <div class="hot-kpi">
      <div class="hot-kpi-lbl">Employees With OT</div>
      <div class="hot-kpi-val" id="hotKpiEmpCount"><?= (int)$initState['kpis']['employee_count'] ?></div>
      <div class="hot-kpi-sub" id="hotSyncClock">Synced at <?= htmlspecialchars($initState['server_time'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <div class="hot-card">
    <h2>Overtime Incident &amp; Approval Logs (Per Request)</h2>
    <div class="hot-table-wrap">
      <table class="hot-table display" id="otIncidentTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Employee</th>
            <th>Department</th>
            <th>Window</th>
            <th>Type</th>
            <th>Requested</th>
            <th>Approved Mins</th>
            <th>OT Pay Impact</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Review</th>
          </tr>
        </thead>
        <tbody id="otIncidentTbody"></tbody>
      </table>
    </div>
  </div>

  <div class="hot-card">
    <h2>Employee Overtime &amp; Pay Summary (Aggregated Totals)</h2>
    <div class="hot-table-wrap">
      <table class="hot-table display" id="otSummaryTable">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Department</th>
            <th>Regular Hours</th>
            <th>Approved OT</th>
            <th>Total Worked Hours</th>
            <th>Overtime Pay</th>
            <th>Total Pay (Reg + OT)</th>
          </tr>
        </thead>
        <tbody id="otSummaryTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  let stateData = <?= json_encode($initState, JSON_UNESCAPED_UNICODE) ?>;
  let incDt = null;
  let sumDt = null;
  let isEditingInput = false;

  function esc(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showNotice(type, text) {
    const box = document.getElementById('hotAlertBox');
    if (!box || !text) return;
    const cls = type === 'err' ? 'hot-alert-err' : 'hot-alert-ok';
    box.innerHTML = `<div class="${cls}">${esc(text)}</div>`;
  }

  function buildIncidentRows(rows) {
    return (rows || []).map(r => {
      const st = String(r.status || 'Pending');
      const bCls = st === 'Approved' ? 'b-app' : (st === 'Rejected' ? 'b-rej' : (st === 'Cancelled' ? 'b-can' : 'b-pen'));
      const empCell = r.employee_code
        ? `<strong>${esc(r.emp_name)}</strong><div style="font-size:11px;color:#94a3b8;">${esc(r.employee_code)}</div>`
        : `<strong>${esc(r.emp_name)}</strong>`;
      const win = `${String(r.start_time || '').slice(0, 5)} - ${String(r.end_time || '').slice(0, 5)}`;

      const appCell = st === 'Pending'
        ? `<input type="number" class="hot-input hot-app-mins" data-id="${Number(r.id)}" value="${Number(r.requested_minutes || 0)}" min="0" max="720" style="width:74px;padding:4px 6px;">`
        : `${Number(r.approved_minutes || 0)}m`;

      const payVal = Number(r.est_ot_pay || 0).toFixed(2);
      const payCell = st === 'Approved'
        ? `<strong style="color:#4ade80;">+₱${payVal}</strong>`
        : (st === 'Pending' ? `<span style="color:#fbbf24;">Est. ₱${payVal}</span>` : '<span style="color:#64748b;">₱0.00</span>');

      const reviewCell = st === 'Pending'
        ? `<div style="display:flex;gap:5px;">
             <button type="button" class="hot-btn-ok" data-review="approve" data-id="${Number(r.id)}">Approve</button>
             <button type="button" class="hot-btn-no" data-review="reject" data-id="${Number(r.id)}">Reject</button>
           </div>`
        : `<span style="font-size:11.5px;color:#94a3b8;">${esc(r.reviewed_by || '—')}</span>`;

      return [
        esc(r.work_date),
        empCell,
        esc(r.department_name || 'Unassigned'),
        esc(win),
        esc(r.ot_type),
        `${Number(r.requested_minutes || 0)}m`,
        appCell,
        payCell,
        esc(r.reason),
        `<span class="hot-badge ${bCls}">${esc(st)}</span>`,
        reviewCell
      ];
    });
  }

  function buildSummaryRows(rows) {
    return (rows || []).map(s => {
      const regHrs   = Number(s.regular_hours || 0).toFixed(2);
      const otMins   = Number(s.reg_mins || 0) + Number(s.spec_mins || 0);
      const otHrs    = (otMins / 60).toFixed(2);
      const totHrs   = Number(s.total_worked_hours || 0).toFixed(2);
      const otPay    = Number(s.overtime_pay || 0).toFixed(2);
      const totalPay = Number(s.total_pay || 0).toFixed(2);

      const empCell = s.employee_code
        ? `<strong>${esc(s.emp_name)}</strong><div style="font-size:11px;color:#94a3b8;">${esc(s.employee_code)}</div>`
        : `<strong>${esc(s.emp_name)}</strong>`;

      return [
        empCell,
        esc(s.department_name || 'Unassigned'),
        `${regHrs} hrs`,
        `<span style="color:#93c5fd;">+${otHrs} hrs (${otMins}m)</span>`,
        `<strong>${totHrs} hrs</strong>`,
        `<strong style="color:#4ade80;">+₱${otPay}</strong>`,
        `<strong>₱${totalPay}</strong>`
      ];
    });
  }

  function renderTables(payload) {
    if (!window.jQuery || !$.fn || !$.fn.DataTable) return;
    const incData = buildIncidentRows(payload.incidents || []);
    const sumData = buildSummaryRows(payload.summary || []);

    if (!incDt) {
      incDt = $('#otIncidentTable').DataTable({
        data: incData,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        order: [[0, 'desc']],
        autoWidth: false,
        columnDefs: [
          { orderable: false, targets: [6, 10] }
        ],
        language: {
          search: 'Search:',
          lengthMenu: 'Show _MENU_ entries',
          emptyTable: 'No overtime requests found for this period.'
        }
      });
    } else {
      incDt.clear().rows.add(incData).draw(false);
    }

    if (!sumDt) {
      sumDt = $('#otSummaryTable').DataTable({
        data: sumData,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        order: [[4, 'desc']],
        autoWidth: false,
        language: {
          search: 'Search:',
          lengthMenu: 'Show _MENU_ entries',
          emptyTable: 'No employee overtime totals for this period.'
        }
      });
    } else {
      sumDt.clear().rows.add(sumData).draw(false);
    }
  }

  function applyState(payload, skipTableRedraw) {
    if (!payload || !payload.ok) return;
    stateData = payload;
    const kpis = payload.kpis || {};
    const setText = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = String(val);
    };

    setText('hotKpiPending', kpis.pending_count ?? 0);
    setText('hotKpiApproved', `${kpis.approved_hrs ?? '0.00'} hrs`);
    setText('hotKpiApprovedMins', `${kpis.approved_mins ?? 0} credited minutes`);
    setText('hotKpiOtPay', `₱${kpis.total_ot_pay ?? '0.00'}`);
    setText('hotKpiEmpCount', kpis.employee_count ?? 0);
    if (payload.server_time) {
      setText('hotSyncClock', `Synced at ${payload.server_time}`);
    }

    if (!skipTableRedraw) {
      renderTables(payload);
    }
  }

  function queryParams() {
    const fromVal = document.getElementById('hotFrom')?.value || '';
    const toVal   = document.getElementById('hotTo')?.value || '';
    const deptVal = document.getElementById('hotDept')?.value || '';
    const stVal   = document.getElementById('hotStatus')?.value || 'all';
    return `from=${encodeURIComponent(fromVal)}&to=${encodeURIComponent(toVal)}&dept=${encodeURIComponent(deptVal)}&status=${encodeURIComponent(stVal)}`;
  }

  async function pollState() {
    if (isEditingInput) return;
    try {
      const res = await fetch(`?ajax=live_state&${queryParams()}&_t=${Date.now()}`, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      applyState(data, false);
    } catch (_) {}
  }

  document.addEventListener('focusin', (e) => {
    if (e.target && e.target.classList && e.target.classList.contains('hot-app-mins')) {
      isEditingInput = true;
    }
  });
  document.addEventListener('focusout', (e) => {
    if (e.target && e.target.classList && e.target.classList.contains('hot-app-mins')) {
      isEditingInput = false;
    }
  });

  const incTbody = document.getElementById('otIncidentTbody');
  if (incTbody) {
    incTbody.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-review]');
      if (!btn) return;
      const action = btn.getAttribute('data-review');
      const reqId  = btn.getAttribute('data-id');
      const rowEl  = btn.closest('tr');
      const minsEl = rowEl ? rowEl.querySelector('.hot-app-mins') : null;
      const approvedMins = minsEl ? minsEl.value : '0';

      isEditingInput = false;
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('hr_ot_action', action);
        fd.append('request_id', reqId);
        fd.append('approved_minutes', approvedMins);

        const res = await fetch(`${window.location.pathname}?${queryParams()}`, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          showNotice('err', (data && data.error) || 'Could not update overtime request.');
        } else {
          showNotice('ok', data.message || 'Overtime updated.');
          applyState(data, false);
        }
      } catch (_) {
        showNotice('err', 'Network error while updating overtime request.');
      } finally {
        btn.disabled = false;
      }
    });
  }

  window.addEventListener('load', () => {
    renderTables(stateData);
    pollState();
    setInterval(pollState, 3000);

    (async function attachWs() {
      const liveText = document.getElementById('hotLiveText');
      try {
        for (const u of ['/api/realtime_token.php', '../api/realtime_token.php']) {
          const r = await fetch(u, { cache: 'no-store' });
          if (!r.ok) continue;
          const j = await r.json();
          const token = j.token || (j.data && j.data.token) || '';
          if (!token) continue;
          const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
          const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
          ws.onopen = () => { if (liveText) liveText.textContent = 'LIVE • WS'; };
          ws.onmessage = (ev) => {
            try {
              const pkt = JSON.parse(ev.data);
              const t = (pkt && pkt.event && pkt.event.type) || '';
              if (t.startsWith('overtime.') || t.startsWith('attendance.')) {
                pollState();
              }
            } catch (_) {}
          };
          ws.onclose = () => { if (liveText) liveText.textContent = 'LIVE POLL'; };
          break;
        }
      } catch (_) {}
    })();
  });
})();
</script>