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

function emp_ps_has_table(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function emp_ps_get_benefits(PDO $pdo, int $employeeId, ?string $refDate = null): array {
    $empty = [
        'items'         => [],
        'monthly_total' => 0.0,
        'cutoff_total'  => 0.0,
        'summary_text'  => '',
    ];

    if ($employeeId <= 0 || !emp_ps_has_table($pdo, 'employee_benefits') || !emp_ps_has_table($pdo, 'benefit_plans')) {
        return $empty;
    }

    try {
        $dateCheck = ($refDate && strtotime($refDate) !== false) ? date('Y-m-d', strtotime($refDate)) : date('Y-m-d');
        $sql = "
            SELECT
                COALESCE(eb.custom_amount, bp.default_amount) AS amount,
                bp.name AS plan_name
            FROM `employee_benefits` eb
            JOIN `benefit_plans` bp ON bp.id = eb.benefit_plan_id
            WHERE eb.employee_id = :employee_id
              AND LOWER(TRIM(COALESCE(eb.status, 'Active'))) = 'active'
              AND eb.effective_date <= :ref_date
              AND (eb.end_date IS NULL OR eb.end_date >= :ref_date_end)
            ORDER BY bp.name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':employee_id'  => $employeeId,
            ':ref_date'     => $dateCheck,
            ':ref_date_end' => $dateCheck,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $monthlyTotal = 0.0;
        $names = [];
        foreach ($rows as $r) {
            $amt = round((float)($r['amount'] ?? 0), 2);
            $monthlyTotal += $amt;
            $names[] = (string)($r['plan_name'] ?? 'Benefit');
        }

        $monthlyTotal = round($monthlyTotal, 2);
        $cutoffTotal  = round($monthlyTotal / 2, 2);

        return [
            'items'         => $rows,
            'monthly_total' => $monthlyTotal,
            'cutoff_total'  => $cutoffTotal,
            'summary_text'  => implode(', ', $names),
        ];
    } catch (Throwable $e) {
        error_log('emp_ps_get_benefits error: ' . $e->getMessage());
        return $empty;
    }
}

$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name
    FROM employees
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $employeeId]);
$employeeData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employeeData) {
    exit('Employee record not found.');
}

$userEmail = $employeeData['email'] ?? '';
$fullName  = trim(($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''));

$user = [
    'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    'email'     => htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'),
    'avatar'    => 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=4763ff&color=fff',
];

$payslips = [];
try {
    $psStmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.gross_pay,
            ps.total_deductions,
            ps.net_pay,
            ps.days_worked,
            (ps.days_worked * 8) AS hours_worked,
            ps.late_minutes,
            CASE
                WHEN pp.status = 'Paid' THEN 'Paid'
                ELSE ps.status
            END AS status,
            pp.period_name AS period_label,
            pp.start_date AS period_start,
            pp.end_date   AS period_end
        FROM payslips ps
        JOIN payroll_periods pp ON pp.id = ps.payroll_period_id
        WHERE ps.employee_id = :eid
        ORDER BY pp.start_date DESC
    ");
    $psStmt->execute([':eid' => $employeeId]);
    $payslips = $psStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payslips as &$psItem) {
        $bData = emp_ps_get_benefits($pdo, (int)$employeeId, (string)($psItem['period_end'] ?? date('Y-m-d')));
        $psItem['benefits_cutoff']  = $bData['cutoff_total'];
        $psItem['benefits_monthly'] = $bData['monthly_total'];
        $psItem['benefits_names']   = $bData['summary_text'];
    }
    unset($psItem);
} catch (Throwable $e) {
    error_log('Payslip list query failed: ' . $e->getMessage());
}

$latest        = $payslips[0] ?? null;
$latestHours   = $latest ? (float) $latest['hours_worked'] : 0.0;
$latestGross   = $latest ? (float) $latest['gross_pay']    : 0.0;
$latestNet     = $latest ? (float) $latest['net_pay']      : 0.0;
$latestBenefit = $latest ? (float) ($latest['benefits_cutoff'] ?? 0.0) : 0.0;
$latestLabel   = $latest
    ? htmlspecialchars($latest['period_label'], ENT_QUOTES, 'UTF-8')
    : date('M Y');
$latestStatus = $latest ? strtolower((string) $latest['status']) : 'pending';
if ($latestStatus === 'draft') {
    $latestStatus = 'pending';
}
$hasPayroll  = !empty($payslips);

function payslipPill(string $status): array {
    return match (strtolower(trim($status))) {
        'paid'      => ['pill-active',  'Paid'],
        'finalized' => ['pill-warning', 'Finalized'],
        'draft'     => ['pill-info',    'Draft'],
        default     => ['pill-neutral', 'Pending'],
    };
}

$title    = 'Payslips';
$eyebrow  = 'PAYROLL';
$subtitle = 'Your pay periods and earnings summary';
$pageCss  = 'emp_payslips';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">PAYROLL</p>
    <h1>Payslips</h1>
    <p>Your computed earnings and assigned benefits for each semi-monthly pay period.</p>
  </div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">◷</span><span class="metric-chip">Latest period</span></div>
    <span class="metric-title">Hours Worked</span>
    <strong class="metric-number teal"><?= number_format($latestHours, 1) ?></strong>
    <span class="metric-description"><?= $latestLabel ?></span>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">₱</span><span class="metric-chip">Gross</span></div>
    <span class="metric-title">Gross Pay</span>
    <strong class="metric-number blue">₱<?= number_format($latestGross, 2) ?></strong>
    <span class="metric-description">Includes ₱<?= number_format($latestBenefit, 2) ?> cut-off benefits</span>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span><span class="metric-chip">Net</span></div>
    <span class="metric-title">Net Pay</span>
    <strong class="metric-number green">₱<?= number_format($latestNet, 2) ?></strong>
    <span class="metric-description">Take-home amount</span>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">▦</span><span class="metric-chip">Status</span></div>
    <span class="metric-title">Latest Payslip</span>
    <strong class="metric-number purple" style="font-size:20px;margin-top:14px">
      <?php
          [$pillClass, $pillLabel] = payslipPill($latestStatus);
          echo $pillLabel;
      ?>
    </strong>
  </article>
</div>

<?php if (!$hasPayroll): ?>
  <div class="panel">
    <div class="panel-body notice-row">
      <span class="notice-ico">ℹ</span>
      <div>
        <strong>No payslips released yet.</strong>
        <p class="muted" style="margin:4px 0 0">
          Once HR generates and finalises a pay period that covers your records,
          your payslips will appear here automatically.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Pay History</h2>
    <span class="muted"><?= count($payslips) ?> payslip<?= count($payslips) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Pay Period</th>
        <th>Inclusive Dates</th>
        <th>Hours</th>
        <th>Benefits (Cut-Off)</th>
        <th>Gross Pay</th>
        <th>Deductions</th>
        <th>Net Pay</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($payslips)): ?>
        <tr>
          <td colspan="9" style="text-align:center;padding:32px;" class="muted">
            No payslip records found.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($payslips as $ps):
            [$pillClass, $pillLabel] = payslipPill($ps['status']);
            $dateRange = date('M j', strtotime($ps['period_start']))
                       . ' – '
                       . date('M j, Y', strtotime($ps['period_end']));
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($ps['period_label'], ENT_QUOTES, 'UTF-8') ?></strong></td>
            <td class="muted"><?= htmlspecialchars($dateRange) ?></td>
            <td><?= number_format((float) $ps['hours_worked'], 1) ?> hrs</td>
            <td>
              ₱<?= number_format((float) ($ps['benefits_cutoff'] ?? 0), 2) ?>
              <?php if (!empty($ps['benefits_names'])): ?>
                <div class="muted" style="font-size:11px;"><?= htmlspecialchars((string)$ps['benefits_names'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>
            <td>₱<?= number_format((float) $ps['gross_pay'], 2) ?></td>
            <td class="muted">– ₱<?= number_format((float) $ps['total_deductions'], 2) ?></td>
            <td><strong class="col-net">₱<?= number_format((float) $ps['net_pay'], 2) ?></strong></td>
            <td><span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span></td>
            <td>
              <a href="/employee/payslip_view.php?id=<?= (int) $ps['id'] ?>"
                 class="btn btn-sm btn-outline">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>