<?php
session_start();

require_once __DIR__ . '/../config/database.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Manila');

// Logged in, account still active, employee portal, allowed by the permission matrix
require_once __DIR__ . '/../authentication/guard.php';
require_access('payslips', 'view', 'own', ['employee']);

$employeeId = $_SESSION['employee_id'] ?? null;
if (!$employeeId) {
    exit('No employee record is linked to this user account.');
}

$payslipId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$payslipId) {
    header('Location: /employee/payslips.php');
    exit;
}

function emp_pv_has_table(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function emp_pv_get_benefits(PDO $pdo, int $employeeId, string $payType = 'Monthly', ?string $refDate = null): array {
    $empty = [
        'items'         => [],
        'monthly_total' => 0.0,
        'cutoff_total'  => 0.0,
    ];

    if ($employeeId <= 0 || !emp_pv_has_table($pdo, 'employee_benefits') || !emp_pv_has_table($pdo, 'benefit_plans')) {
        return $empty;
    }

    try {
        $dateCheck = ($refDate && strtotime($refDate) !== false) ? date('Y-m-d', strtotime($refDate)) : date('Y-m-d');
        $sql = "
            SELECT
                eb.id,
                COALESCE(eb.custom_amount, bp.default_amount) AS amount,
                eb.effective_date,
                eb.notes,
                bp.name AS plan_name,
                bp.category,
                bp.is_taxable
            FROM `employee_benefits` eb
            JOIN `benefit_plans` bp ON bp.id = eb.benefit_plan_id
            WHERE eb.employee_id = :employee_id
              AND LOWER(TRIM(COALESCE(eb.status, 'Active'))) = 'active'
              AND eb.effective_date <= :ref_date
              AND (eb.end_date IS NULL OR eb.end_date >= :ref_date_end)
            ORDER BY bp.category ASC, bp.name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':employee_id'  => $employeeId,
            ':ref_date'     => $dateCheck,
            ':ref_date_end' => $dateCheck,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        $monthlyTotal = 0.0;
        $isMonthly = strtolower(trim($payType)) === 'monthly';

        foreach ($rows as $r) {
            $amt = round((float)($r['amount'] ?? 0), 2);
            $monthlyTotal += $amt;
            $items[] = [
                'plan_name'  => (string)($r['plan_name'] ?? 'Benefit'),
                'category'   => (string)($r['category'] ?? 'Allowance'),
                'amount'     => $amt,
                'cutoff_amt' => $isMonthly ? round($amt / 2, 2) : $amt,
                'is_taxable' => !empty($r['is_taxable']),
            ];
        }

        $monthlyTotal = round($monthlyTotal, 2);
        $cutoffTotal  = $isMonthly ? round($monthlyTotal / 2, 2) : $monthlyTotal;

        return [
            'items'         => $items,
            'monthly_total' => $monthlyTotal,
            'cutoff_total'  => $cutoffTotal,
        ];
    } catch (Throwable $e) {
        error_log('emp_pv_get_benefits error: ' . $e->getMessage());
        return $empty;
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT
            ps.*,
            (ps.days_worked * 8)  AS hours_worked_calc,
            pp.period_name        AS period_name,
            pp.start_date,
            pp.end_date,
            pp.pay_date,
            e.first_name,
            e.last_name,
            e.employee_id         AS emp_code,
            e.email,
            d.name                AS department_name,
            p.title               AS position_title
        FROM payslips ps
        JOIN payroll_periods pp ON pp.id = ps.payroll_period_id
        JOIN employees e        ON e.id  = ps.employee_id
        LEFT JOIN departments d ON d.id  = e.department_id
        LEFT JOIN positions   p ON p.id  = e.position_id
        WHERE ps.id          = :pid
          AND ps.employee_id = :eid
        LIMIT 1
    ");
    $stmt->execute([':pid' => $payslipId, ':eid' => $employeeId]);
    $ps = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Employee payslip view error: ' . $e->getMessage());
    $ps = null;
}

if (!$ps) {
    http_response_code(404);
    exit('Payslip not found or access denied.');
}

$empBenefits = emp_pv_get_benefits(
    $pdo,
    (int)$employeeId,
    (string)($ps['pay_type'] ?? 'Monthly'),
    (string)($ps['end_date'] ?? date('Y-m-d'))
);

$fullName    = trim($ps['first_name'] . ' ' . $ps['last_name']);
$hoursWorked = isset($ps['hours_worked']) && $ps['hours_worked'] !== null
             ? (float) $ps['hours_worked']
             : (float) $ps['hours_worked_calc'];

$badgeClass = match(strtolower(trim($ps['status']))) {
    'paid'      => 'badge-paid',
    'finalized' => 'badge-finalized',
    'draft'     => 'badge-draft',
    default     => 'badge-draft',
};

$title   = 'Payslip — ' . $ps['period_name'];
$pageCss = 'emp_payslip_view';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<style>
  /* ── Bond paper overrides inside the employee portal ── */
  .payslip-page-wrap {
    max-width: 760px;
    margin: 24px auto 48px;
    padding: 0 16px;
  }

  /* Screen action bar */
  .screen-bar {
    background: #1e293b;
    color: #fff;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-radius: 10px;
    margin-bottom: 24px;
    flex-wrap: wrap;
  }
  .screen-bar a {
    color: #93c5fd;
    text-decoration: none;
    font-size: 13px;
  }
  .screen-bar button {
    padding: 7px 14px;
    border: 0;
    border-radius: 6px;
    background: #3b82f6;
    color: #fff;
    cursor: pointer;
    font-size: 13px;
  }
  .screen-bar .spacer { flex: 1; }

  /* Payslip card */
  .slip {
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 36px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
  }

  /* Header */
  .slip-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #1e293b;
  }
  .company-name { font-size: 20px; font-weight: 700; color: #1e293b; }
  .company-sub  { font-size: 12px; color: #6b7280; margin-top: 2px; }
  .slip-title   { text-align: right; }
  .slip-title h2 { font-size: 16px; font-weight: 700; color: #1e293b; }
  .slip-title p  { font-size: 12px; color: #6b7280; margin-top: 2px; }

  /* Employee info grid */
  .emp-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 20px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 14px;
  }
  .emp-row    { display: flex; flex-direction: column; gap: 2px; }
  .emp-label  { font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600; }
  .emp-value  { font-size: 13px; font-weight: 600; color: #111; }

  /* Section title */
  .section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    color: #374151;
    background: #f3f4f6;
    padding: 6px 10px;
    border-radius: 4px;
    margin-bottom: 8px;
    letter-spacing: .5px;
  }

  /* Attendance boxes */
  .attend-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 8px;
    margin-bottom: 20px;
  }
  .attend-box {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 10px;
    text-align: center;
  }
  .attend-box .val { font-size: 18px; font-weight: 700; color: #1e293b; }
  .attend-box .lbl { font-size: 10px; color: #6b7280; text-transform: uppercase; margin-top: 2px; }

  /* Earnings / Deductions / Benefits rows */
  .slip-section { margin-bottom: 16px; }
  .slip-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
  .slip-row  { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 5px 10px; font-size: 13px; }
  .slip-row:nth-child(even) { background: #f9fafb; }
  .slip-row.total { font-weight: 700; border-top: 2px solid #1e293b; margin-top: 4px; padding-top: 8px; }
  .slip-row .label  { color: #374151; }
  .slip-row .amount { font-variant-numeric: tabular-nums; white-space: nowrap; }
  .text-green { color: #065f46; }
  .text-red   { color: #b91c1c; }

  /* Net pay */
  .net-box {
    background: #1e293b;
    color: #fff;
    border-radius: 8px;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 16px;
  }
  .net-box .net-label  { font-size: 13px; opacity: .8; }
  .net-box .net-amount { font-size: 28px; font-weight: 700; }

  /* Remarks */
  .slip-remarks {
    margin-top: 14px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 6px;
    padding: 10px 14px;
    font-size: 12px;
  }

  /* Signatures */
  .slip-footer {
    margin-top: 32px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
  }
  .sign-box { text-align: center; }
  .sign-line {
    border-top: 1px solid #374151;
    margin-top: 44px;
    padding-top: 6px;
    font-size: 11px;
    color: #6b7280;
  }
  .sign-name { font-size: 12px; font-weight: 600; color: #374151; margin-top: 2px; }

  /* Generated note */
  .slip-generated {
    margin-top: 16px;
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
  }

  /* Badge */
  .badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
  }
  .badge-draft     { background: #f3f4f6; color: #374151; }
  .badge-generated { background: #dbeafe; color: #1e40af; }
  .badge-finalized { background: #ede9fe; color: #5b21b6; }
  .badge-paid      { background: #d1fae5; color: #065f46; }

  /* Print */
  @media print {
    .screen-bar { display: none !important; }
    body        { background: #fff; }
    .payslip-page-wrap { margin: 0; padding: 0; max-width: 100%; }
    .slip       { box-shadow: none; border: none; border-radius: 0; padding: 16px; }
  }
</style>

<div class="payslip-page-wrap">

  <!-- ── Screen action bar ───────────────────────────────────── -->
  <div class="screen-bar no-print">
    <a href="/employee/payslips.php">← Back to Payslips</a>
    <span class="spacer"></span>
    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($ps['status'])) ?></span>
    <button onclick="window.print()">🖨️ Print / Save PDF</button>
  </div>

  <!-- ── Bond paper payslip ──────────────────────────────────── -->
  <div class="slip">

    <!-- Header -->
    <div class="slip-header">
      <div>
        <div class="company-name">HRIS Company</div>
        <div class="company-sub">Human Resource Information System</div>
      </div>
      <div class="slip-title">
        <h2>PAYSLIP</h2>
        <p><?= htmlspecialchars($ps['period_name']) ?></p>
        <p><?= htmlspecialchars($ps['start_date']) ?> to <?= htmlspecialchars($ps['end_date']) ?></p>
        <p>Pay Date: <strong><?= htmlspecialchars($ps['pay_date'] ?? '—') ?></strong></p>
      </div>
    </div>

    <!-- Employee info -->
    <div class="emp-grid">
      <div class="emp-row">
        <span class="emp-label">Employee Name</span>
        <span class="emp-value"><?= htmlspecialchars($fullName) ?></span>
      </div>
      <div class="emp-row">
        <span class="emp-label">Employee ID</span>
        <span class="emp-value"><?= htmlspecialchars($ps['emp_code'] ?? '—') ?></span>
      </div>
      <div class="emp-row">
        <span class="emp-label">Department</span>
        <span class="emp-value"><?= htmlspecialchars($ps['department_name'] ?? '—') ?></span>
      </div>
      <div class="emp-row">
        <span class="emp-label">Position</span>
        <span class="emp-value"><?= htmlspecialchars($ps['position_title'] ?? '—') ?></span>
      </div>
      <div class="emp-row">
        <span class="emp-label">Pay Type</span>
        <span class="emp-value"><?= htmlspecialchars($ps['pay_type'] ?? 'Monthly') ?></span>
      </div>
      <div class="emp-row">
        <span class="emp-label">Basic Salary (Monthly)</span>
        <span class="emp-value">₱<?= number_format((float)($ps['basic_salary'] ?? 0), 2) ?></span>
      </div>
    </div>

    <!-- Attendance summary -->
    <div class="section-title">Attendance Summary</div>
    <div class="attend-grid">
      <div class="attend-box">
        <div class="val"><?= (int)($ps['working_days'] ?? 0) ?></div>
        <div class="lbl">Working Days</div>
      </div>
      <div class="attend-box">
        <div class="val"><?= number_format((float)($ps['days_worked'] ?? 0), 1) ?></div>
        <div class="lbl">Days Worked</div>
      </div>
      <div class="attend-box">
        <div class="val" style="color:#b91c1c"><?= number_format((float)($ps['absent_days'] ?? 0), 1) ?></div>
        <div class="lbl">Absent Days</div>
      </div>
      <div class="attend-box">
        <div class="val"><?= (int)($ps['late_minutes'] ?? 0) ?></div>
        <div class="lbl">Late (min)</div>
      </div>
      <div class="attend-box">
        <div class="val"><?= number_format((float)($ps['ot_hours'] ?? 0), 1) ?></div>
        <div class="lbl">OT Hours</div>
      </div>
      <div class="attend-box">
        <div class="val"><?= number_format($hoursWorked, 1) ?></div>
        <div class="lbl">Hours Worked</div>
      </div>
    </div>

    <!-- Earnings + Deductions -->
    <div class="slip-cols">

      <!-- Earnings -->
      <div>
        <div class="section-title">Earnings</div>
        <div class="slip-row">
          <span class="label">Basic Pay</span>
          <span class="amount text-green">₱<?= number_format((float)($ps['basic_pay'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">Allowance</span>
          <span class="amount text-green">₱<?= number_format((float)($ps['allowance'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">Overtime Pay</span>
          <span class="amount text-green">₱<?= number_format((float)($ps['overtime_pay'] ?? 0), 2) ?></span>
        </div>
        <?php if ((float)($ps['other_earnings'] ?? 0) > 0): ?>
        <div class="slip-row">
          <span class="label">Other Earnings</span>
          <span class="amount text-green">₱<?= number_format((float)$ps['other_earnings'], 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="slip-row total">
          <span class="label">Gross Pay</span>
          <span class="amount text-green">₱<?= number_format((float)($ps['gross_pay'] ?? 0), 2) ?></span>
        </div>
      </div>

      <!-- Deductions -->
      <div>
        <div class="section-title">Deductions</div>
        <div class="slip-row">
          <span class="label">SSS</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['sss'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">PhilHealth</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['philhealth'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">Pag-IBIG</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['pagibig'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">Withholding Tax</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['withholding_tax'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">Absences</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['absences_deduction'] ?? 0), 2) ?></span>
        </div>
        <div class="slip-row">
          <span class="label">Late</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['late_deduction'] ?? 0), 2) ?></span>
        </div>
        <?php if ((float)($ps['other_deductions'] ?? 0) > 0): ?>
        <div class="slip-row">
          <span class="label">Other Deductions</span>
          <span class="amount text-red">₱<?= number_format((float)$ps['other_deductions'], 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="slip-row total">
          <span class="label">Total Deductions</span>
          <span class="amount text-red">₱<?= number_format((float)($ps['total_deductions'] ?? 0), 2) ?></span>
        </div>
      </div>

    </div>

    <!-- Assigned Employee Benefits -->
    <div class="slip-section">
      <div class="section-title">Assigned Benefits</div>
      <?php if (empty($empBenefits['items'])): ?>
        <div class="slip-row">
          <span class="label" style="color:#6b7280">No active benefits assigned.</span>
          <span class="amount">₱0.00</span>
        </div>
      <?php else: ?>
        <?php foreach ($empBenefits['items'] as $bItem): ?>
          <div class="slip-row">
            <span class="label">
              <?= htmlspecialchars($bItem['plan_name'], ENT_QUOTES, 'UTF-8') ?>
              <small style="color:#6b7280">
                (<?= htmlspecialchars($bItem['category'], ENT_QUOTES, 'UTF-8') ?>
                • <?= $bItem['is_taxable'] ? 'Taxable' : 'Non-Taxable' ?>
                • Monthly: ₱<?= number_format((float)$bItem['amount'], 2) ?>)
              </small>
            </span>
            <span class="amount text-green">₱<?= number_format((float)$bItem['cutoff_amt'], 2) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="slip-row total">
          <span class="label">
            Benefits (Cut-Off Share)
            <small style="color:#6b7280;font-weight:400">
              — Monthly Total: ₱<?= number_format((float)$empBenefits['monthly_total'], 2) ?>
            </small>
          </span>
          <span class="amount text-green">₱<?= number_format((float)$empBenefits['cutoff_total'], 2) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Net pay -->
    <div class="net-box">
      <div>
        <div class="net-label">NET PAY</div>
        <div style="font-size:11px;opacity:.6;margin-top:2px">
          For period: <?= htmlspecialchars($ps['start_date']) ?> – <?= htmlspecialchars($ps['end_date']) ?>
        </div>
      </div>
      <div class="net-amount">₱<?= number_format((float)($ps['net_pay'] ?? 0), 2) ?></div>
    </div>

    <!-- Remarks -->
    <?php if (!empty($ps['remarks'])): ?>
      <div class="slip-remarks">
        <strong>Remarks:</strong> <?= htmlspecialchars($ps['remarks']) ?>
      </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="slip-footer">
      <div class="sign-box">
        <div class="sign-line">Employee Signature / Date</div>
        <div class="sign-name"><?= htmlspecialchars($fullName) ?></div>
      </div>
      <div class="sign-box">
        <div class="sign-line">HR Officer / Date</div>
        <div class="sign-name">Human Resources</div>
      </div>
      <div class="sign-box">
        <div class="sign-line">Authorized By / Date</div>
        <div class="sign-name">Management</div>
      </div>
    </div>

    <!-- Generated note -->
    <div class="slip-generated">
      Generated: <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp;
      Status: <?= htmlspecialchars(ucfirst($ps['status'])) ?>
    </div>

  </div><!-- /.slip -->
</div><!-- /.payslip-page-wrap -->

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>