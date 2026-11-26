<?php
// hr/benefits.php

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$role = strtolower(trim((string)($_SESSION['role'] ?? 'employee')));
if (!in_array($role, ['hr', 'admin', 'manager'], true)) {
    header('Location: /employee/dashboard.php');
    exit;
}

$reviewerName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? strtoupper($role)));

function ben_has_table(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ben_has_col(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

function benefits_ensure_schema(PDO $pdo): void {
    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `benefit_plans` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(120) NOT NULL,
            `category` VARCHAR(40) NOT NULL DEFAULT 'Allowance',
            `default_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `is_taxable` TINYINT(1) NOT NULL DEFAULT 0,
            `min_tenure_months` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_benefit_plan_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_benefits` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `benefit_plan_id` INT UNSIGNED NOT NULL,
            `custom_amount` DECIMAL(12,2) NULL DEFAULT NULL,
            `effective_date` DATE NOT NULL,
            `end_date` DATE NULL DEFAULT NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'Active',
            `notes` VARCHAR(255) NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_emp_ben_emp` (`employee_id`),
            KEY `idx_emp_ben_plan` (`benefit_plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `separation_settlements` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `separation_type` VARCHAR(40) NOT NULL DEFAULT 'Resignation',
            `hire_date` DATE NOT NULL,
            `last_working_day` DATE NOT NULL,
            `years_of_service` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `unpaid_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `prorated_13th_month` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `leave_conversion_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `retirement_gratuity_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `tax_refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `deductions_or_accountability` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `net_final_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `notes` TEXT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'Pending_Clearance',
            `reviewed_by` VARCHAR(120) NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_sep_emp` (`employee_id`),
            KEY `idx_sep_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        INSERT IGNORE INTO `benefit_plans` (`name`, `category`, `default_amount`, `is_taxable`, `min_tenure_months`, `is_active`) VALUES
          ('Rice Subsidy (De Minimis)', 'Allowance', 2000.00, 0, 0, 1),
          ('Transportation Allowance', 'Allowance', 1500.00, 0, 0, 1),
          ('Meal Allowance', 'Allowance', 1500.00, 0, 0, 1),
          ('HMO Medical Coverage', 'Health', 2500.00, 0, 6, 1),
          ('Senior / Loyalty Gratuity Top-Up', 'Retirement', 5000.00, 0, 60, 1)
    ");
}

function ben_get_employee_monthly_rate(PDO $pdo, int $employeeId): float {
    foreach (['employee_salaries', 'salaries'] as $tbl) {
        if (!ben_has_table($pdo, $tbl)) continue;
        $empCol = ben_has_col($pdo, $tbl, 'employee_id') ? 'employee_id' : (ben_has_col($pdo, $tbl, 'emp_id') ? 'emp_id' : null);
        if (!$empCol) continue;
        $amtCol = ben_has_col($pdo, $tbl, 'basic_salary') ? 'basic_salary' : (ben_has_col($pdo, $tbl, 'monthly_salary') ? 'monthly_salary' : (ben_has_col($pdo, $tbl, 'salary') ? 'salary' : null));
        if (!$amtCol) continue;

        $orderCol = ben_has_col($pdo, $tbl, 'effective_date') ? "`effective_date` DESC, `id` DESC" : "`id` DESC";
        $stmt = $pdo->prepare("SELECT `{$amtCol}` FROM `{$tbl}` WHERE `{$empCol}` = ? ORDER BY {$orderCol} LIMIT 1");
        $stmt->execute([$employeeId]);
        $val = (float)($stmt->fetchColumn() ?: 0);
        if ($val > 0) {
            if (ben_has_col($pdo, $tbl, 'pay_type')) {
                $ptStmt = $pdo->prepare("SELECT `pay_type` FROM `{$tbl}` WHERE `{$empCol}` = ? ORDER BY {$orderCol} LIMIT 1");
                $ptStmt->execute([$employeeId]);
                $payType = strtolower(trim((string)$ptStmt->fetchColumn()));
                if ($payType === 'daily') return round($val * 22, 2);
                if ($payType === 'hourly') return round($val * 8 * 22, 2);
            }
            return round($val, 2);
        }
    }
    return 25000.00;
}

function ben_compute_years_of_service(string $hireDate, string $endDate): float {
    $h = strtotime($hireDate);
    $e = strtotime($endDate);
    if ($h === false || $e === false || $e <= $h) return 0.0;
    $days = ($e - $h) / 86400.0;
    return round($days / 365.25, 2);
}

try {
    benefits_ensure_schema($pdo);
} catch (Throwable $e) {}

$tab = $_GET['tab'] ?? 'active_benefits';
$allowedTabs = ['active_benefits', 'plans', 'separations'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'active_benefits';

$flashOk  = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = trim((string)($_POST['form'] ?? ''));

    try {
        if ($form === 'create_plan') {
            $name       = trim((string)($_POST['plan_name'] ?? ''));
            $category   = trim((string)($_POST['category'] ?? 'Allowance'));
            $amount     = max(0, (float)($_POST['default_amount'] ?? 0));
            $isTaxable  = !empty($_POST['is_taxable']) ? 1 : 0;
            $minTenure  = max(0, (int)($_POST['min_tenure_months'] ?? 0));

            if ($name === '') throw new RuntimeException('Benefit plan name is required.');
            $stmt = $pdo->prepare("
                INSERT INTO `benefit_plans` (`name`, `category`, `default_amount`, `is_taxable`, `min_tenure_months`, `is_active`)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    `category` = VALUES(`category`),
                    `default_amount` = VALUES(`default_amount`),
                    `is_taxable` = VALUES(`is_taxable`),
                    `min_tenure_months` = VALUES(`min_tenure_months`),
                    `is_active` = 1
            ");
            $stmt->execute([$name, $category, $amount, $isTaxable, $minTenure]);
            $flashOk = 'Benefit plan saved.';
            $tab = 'plans';
        } elseif ($form === 'assign_benefit') {
            $empId         = (int)($_POST['employee_id'] ?? 0);
            $planId        = (int)($_POST['benefit_plan_id'] ?? 0);
            $customAmtRaw  = trim((string)($_POST['custom_amount'] ?? ''));
            $customAmount  = $customAmtRaw === '' ? null : max(0, (float)$customAmtRaw);
            $effectiveDate = trim((string)($_POST['effective_date'] ?? date('Y-m-d')));
            $notes         = trim((string)($_POST['notes'] ?? ''));

            if ($empId <= 0 || $planId <= 0) throw new RuntimeException('Please choose both an employee and a benefit plan.');
            $stmt = $pdo->prepare("
                INSERT INTO `employee_benefits` (`employee_id`, `benefit_plan_id`, `custom_amount`, `effective_date`, `status`, `notes`)
                VALUES (?, ?, ?, ?, 'Active', ?)
            ");
            $stmt->execute([$empId, $planId, $customAmount, $effectiveDate, $notes !== '' ? $notes : null]);
            $flashOk = 'Benefit assigned to employee.';
            $tab = 'active_benefits';
        } elseif ($form === 'toggle_benefit_status') {
            $ebId   = (int)($_POST['eb_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'Active'));
            if (!in_array($status, ['Active', 'Suspended', 'Claimed'], true)) {
                throw new RuntimeException('Invalid benefit status.');
            }
            $stmt = $pdo->prepare("UPDATE `employee_benefits` SET `status` = ? WHERE `id` = ?");
            $stmt->execute([$status, $ebId]);
            $flashOk = 'Employee benefit status updated to ' . $status . '.';
            $tab = 'active_benefits';
        } elseif ($form === 'compute_separation') {
            $empId         = (int)($_POST['employee_id'] ?? 0);
            $sepType       = trim((string)($_POST['separation_type'] ?? 'Resignation'));
            $hireDate      = trim((string)($_POST['hire_date'] ?? date('Y-m-01')));
            $lastDay       = trim((string)($_POST['last_working_day'] ?? date('Y-m-d')));
            $unpaidDays    = max(0, (float)($_POST['unpaid_days'] ?? 0));
            $unusedLeave   = max(0, (float)($_POST['unused_leave_days'] ?? 0));
            $ytdBasicPay   = max(0, (float)($_POST['ytd_basic_pay'] ?? 0));
            $extraGratuity = max(0, (float)($_POST['extra_gratuity'] ?? 0));
            $taxRefund     = max(0, (float)($_POST['tax_refund'] ?? 0));
            $deductions    = max(0, (float)($_POST['deductions'] ?? 0));
            $notes         = trim((string)($_POST['notes'] ?? ''));

            if ($empId <= 0) throw new RuntimeException('Please select an employee.');
            $allowedSep = ['Resignation', 'Retirement_Age', 'End_of_Contract'];
            if (!in_array($sepType, $allowedSep, true)) $sepType = 'Resignation';

            $monthlyRate = ben_get_employee_monthly_rate($pdo, $empId);
            $dailyRate   = round(($monthlyRate * 12.0) / 261.0, 2); // Standard PH 261 working days
            $yrsService  = ben_compute_years_of_service($hireDate, $lastDay);

            $unpaidSalary = round($unpaidDays * $dailyRate, 2);

            if ($ytdBasicPay <= 0) {
                $ytdStart = date('Y-01-01', strtotime($lastDay));
                $monthsElapsed = max(1.0, ((strtotime($lastDay) - strtotime($ytdStart)) / 86400.0) / 30.4375);
                $ytdBasicPay = round($monthlyRate * min(12.0, $monthsElapsed), 2);
            }
            $prorated13th = round($ytdBasicPay / 12.0, 2);
            $leavePay     = round($unusedLeave * $dailyRate, 2);

            // RA 7641 Retirement Pay: 22.5 days per year of service if age-related retirement & >= 5 years
            $retirementPay = $extraGratuity;
            if ($sepType === 'Retirement_Age' && $yrsService >= 5.0) {
                $retirementPay = round((22.5 * $dailyRate * $yrsService) + $extraGratuity, 2);
            }

            $grossFinal = $unpaidSalary + $prorated13th + $leavePay + $retirementPay + $taxRefund;
            $netFinal   = round(max(0.0, $grossFinal - $deductions), 2);

            $stmt = $pdo->prepare("
                INSERT INTO `separation_settlements` (
                    `employee_id`, `separation_type`, `hire_date`, `last_working_day`,
                    `years_of_service`, `unpaid_salary`, `prorated_13th_month`,
                    `leave_conversion_pay`, `retirement_gratuity_pay`, `tax_refund_amount`,
                    `deductions_or_accountability`, `net_final_pay`, `notes`, `status`, `reviewed_by`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending_Clearance', ?)
            ");
            $stmt->execute([
                $empId, $sepType, $hireDate, $lastDay,
                $yrsService, $unpaidSalary, $prorated13th,
                $leavePay, $retirementPay, $taxRefund,
                $deductions, $netFinal, $notes !== '' ? $notes : null, $reviewerName
            ]);

            $flashOk = 'Final pay / retirement settlement computed: ₱' . number_format($netFinal, 2) . ' (' . $yrsService . ' yrs of service).';
            $tab = 'separations';
        } elseif ($form === 'update_settlement_status') {
            $sepId  = (int)($_POST['sep_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'Pending_Clearance'));
            if (!in_array($status, ['Pending_Clearance', 'Approved', 'Released'], true)) {
                throw new RuntimeException('Invalid settlement status.');
            }
            $stmt = $pdo->prepare("UPDATE `separation_settlements` SET `status` = ?, `reviewed_by` = ? WHERE `id` = ?");
            $stmt->execute([$status, $reviewerName, $sepId]);
            $flashOk = 'Settlement marked as ' . str_replace('_', ' ', $status) . '.';
            $tab = 'separations';
        }
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

$employees = $pdo->query("
    SELECT id, COALESCE(employee_id, id) AS emp_code, first_name, last_name, employment_status
    FROM employees
    ORDER BY last_name, first_name
")->fetchAll(PDO::FETCH_ASSOC);

$plans = $pdo->query("SELECT * FROM benefit_plans ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

$empBenefits = $pdo->query("
    SELECT eb.*, bp.name AS plan_name, bp.category, bp.default_amount, bp.is_taxable,
           e.first_name, e.last_name, COALESCE(e.employee_id, e.id) AS emp_code
    FROM employee_benefits eb
    JOIN benefit_plans bp ON bp.id = eb.benefit_plan_id
    LEFT JOIN employees e ON e.id = eb.employee_id
    ORDER BY eb.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$settlements = $pdo->query("
    SELECT ss.*, e.first_name, e.last_name, COALESCE(e.employee_id, e.id) AS emp_code
    FROM separation_settlements ss
    LEFT JOIN employees e ON e.id = ss.employee_id
    ORDER BY ss.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$hrHeader = __DIR__ . '/../templates/hrTemplates/header.php';
if (is_file($hrHeader)) {
    require_once $hrHeader;
}
?>

<style>
  .hb-wrap { max-width: 1320px; margin: 20px auto 56px; padding: 0 20px; color: #f8fafc; }
  .hb-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
  .hb-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
  .hb-tab { padding: 8px 14px; border-radius: 9px; text-decoration: none; font-size: 13px; font-weight: 600; background: #1e293b; color: #cbd5e1; border: 1px solid rgba(148,163,184,0.2); }
  .hb-tab.active { background: #2563eb; color: #fff; border-color: #2563eb; }
  .hb-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; }
  .hb-card h2, .hb-card h3 { margin: 0 0 12px; font-size: 16px; color: #fff; }
  .hb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 12px; }
  .hb-lbl { display: block; font-size: 11.5px; color: #94a3b8; margin-bottom: 4px; font-weight: 600; }
  .hb-in, .hb-sel { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #f8fafc; font-size: 13px; box-sizing: border-box; }
  .hb-btn { padding: 8px 14px; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; }
  .hb-btn-sm { padding: 4px 9px; font-size: 11.5px; border-radius: 6px; }
  .hb-btn-ok { background: #16a34a; color: #fff; border: none; cursor: pointer; }
  .hb-btn-warn { background: #d97706; color: #fff; border: none; cursor: pointer; }
  .hb-tbl-wrap { overflow-x: auto; }
  .hb-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .hb-tbl th, .hb-tbl td { padding: 9px 10px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .hb-tbl th { font-size: 11px; text-transform: uppercase; color: #94a3b8; background: #0f172a; }
  .hb-ok { background: rgba(22,163,74,0.18); border: 1px solid rgba(74,222,128,0.4); color: #bbf7d0; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .hb-err { background: rgba(239,68,68,0.18); border: 1px solid rgba(248,113,113,0.4); color: #fecaca; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .hb-pill { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: rgba(59,130,246,0.2); color: #93c5fd; }
</style>

<div class="hb-wrap">
  <div class="hb-head">
    <div>
      <h1 style="margin:0;font-size:24px;">Benefits &amp; Separation / Retirement Pay</h1>
      <p style="margin:4px 0 0;font-size:13px;color:#94a3b8;">Manage active employee benefits, voluntary resignations, and RA 7641 age-related retirement settlements.</p>
    </div>
    <a href="/hr/payroll.php" class="hb-btn" style="text-decoration:none;">← Back to Payroll</a>
  </div>

  <div class="hb-tabs">
    <a href="?tab=active_benefits" class="hb-tab <?= $tab === 'active_benefits' ? 'active' : '' ?>">Active Employee Benefits</a>
    <a href="?tab=plans" class="hb-tab <?= $tab === 'plans' ? 'active' : '' ?>">Benefit Plans</a>
    <a href="?tab=separations" class="hb-tab <?= $tab === 'separations' ? 'active' : '' ?>">Resignation &amp; Retirement Final Pay</a>
  </div>

  <?php if ($flashOk !== ''): ?>
    <div class="hb-ok"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($flashErr !== ''): ?>
    <div class="hb-err"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($tab === 'active_benefits'): ?>
    <div class="hb-card">
      <h3>Assign Benefit to Employee</h3>
      <form method="post">
        <input type="hidden" name="form" value="assign_benefit">
        <div class="hb-grid">
          <div>
            <label class="hb-lbl">Employee</label>
            <select name="employee_id" class="hb-sel" required>
              <option value="">-- Select employee --</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>">
                  [<?= htmlspecialchars((string)$emp['emp_code'], ENT_QUOTES, 'UTF-8') ?>]
                  <?= htmlspecialchars((string)($emp['last_name'] . ', ' . $emp['first_name']), ENT_QUOTES, 'UTF-8') ?>
                  (<?= htmlspecialchars((string)$emp['employment_status'], ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Benefit Plan</label>
            <select name="benefit_plan_id" class="hb-sel" required>
              <?php foreach ($plans as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>">
                  <?= htmlspecialchars((string)$pl['name'], ENT_QUOTES, 'UTF-8') ?> — ₱<?= number_format((float)$pl['default_amount'], 2) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Custom Amount (₱, optional)</label>
            <input type="number" step="0.01" min="0" name="custom_amount" class="hb-in" placeholder="Uses plan default">
          </div>
          <div>
            <label class="hb-lbl">Effective Date</label>
            <input type="date" name="effective_date" value="<?= date('Y-m-d') ?>" class="hb-in" required>
          </div>
        </div>
        <div style="margin-bottom:12px;">
          <label class="hb-lbl">Notes</label>
          <input type="text" name="notes" class="hb-in" placeholder="e.g. Dependent slot 1, Senior medical support...">
        </div>
        <button type="submit" class="hb-btn">Assign Benefit</button>
      </form>
    </div>

    <div class="hb-card">
      <h3>Assigned Employee Benefits</h3>
      <div class="hb-tbl-wrap">
        <table class="hb-tbl">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Benefit Plan</th>
              <th>Category</th>
              <th>Amount</th>
              <th>Taxable</th>
              <th>Effective</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($empBenefits)): ?>
              <tr><td colspan="8" style="text-align:center;color:#94a3b8;">No benefits assigned yet.</td></tr>
            <?php else: ?>
              <?php foreach ($empBenefits as $eb): ?>
                <?php $amt = $eb['custom_amount'] !== null ? (float)$eb['custom_amount'] : (float)$eb['default_amount']; ?>
                <tr>
                  <td><strong><?= htmlspecialchars((string)($eb['last_name'] . ', ' . $eb['first_name']), ENT_QUOTES, 'UTF-8') ?></strong></td>
                  <td><?= htmlspecialchars((string)$eb['plan_name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><span class="hb-pill"><?= htmlspecialchars((string)$eb['category'], ENT_QUOTES, 'UTF-8') ?></span></td>
                  <td>₱<?= number_format($amt, 2) ?></td>
                  <td><?= !empty($eb['is_taxable']) ? 'Taxable' : 'Non-Taxable' ?></td>
                  <td><?= htmlspecialchars((string)$eb['effective_date'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)$eb['status'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <form method="post" style="display:inline-flex;gap:4px;">
                      <input type="hidden" name="form" value="toggle_benefit_status">
                      <input type="hidden" name="eb_id" value="<?= (int)$eb['id'] ?>">
                      <button type="submit" name="status" value="Active" class="hb-btn hb-btn-sm hb-btn-ok">Active</button>
                      <button type="submit" name="status" value="Suspended" class="hb-btn hb-btn-sm hb-btn-warn">Hold</button>
                      <button type="submit" name="status" value="Claimed" class="hb-btn hb-btn-sm">Claimed</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($tab === 'plans'): ?>
    <div class="hb-card">
      <h3>Create / Update Benefit Plan</h3>
      <form method="post">
        <input type="hidden" name="form" value="create_plan">
        <div class="hb-grid">
          <div>
            <label class="hb-lbl">Plan Name</label>
            <input type="text" name="plan_name" class="hb-in" required placeholder="e.g. Senior Retirement Gratuity">
          </div>
          <div>
            <label class="hb-lbl">Category</label>
            <select name="category" class="hb-sel">
              <option value="Allowance">Allowance</option>
              <option value="Health">Health</option>
              <option value="Leave">Leave</option>
              <option value="Retirement">Retirement</option>
              <option value="Statutory">Statutory</option>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Default Amount (₱)</label>
            <input type="number" step="0.01" min="0" name="default_amount" value="0.00" class="hb-in" required>
          </div>
          <div>
            <label class="hb-lbl">Min Tenure (Months)</label>
            <input type="number" min="0" name="min_tenure_months" value="0" class="hb-in">
          </div>
        </div>
        <div style="margin-bottom:12px;">
          <label style="font-size:13px;color:#cbd5e1;">
            <input type="checkbox" name="is_taxable" value="1"> Taxable benefit
          </label>
        </div>
        <button type="submit" class="hb-btn">Save Plan</button>
      </form>
    </div>

    <div class="hb-card">
      <h3>Benefit Plans Catalog</h3>
      <div class="hb-tbl-wrap">
        <table class="hb-tbl">
          <thead>
            <tr>
              <th>Plan Name</th>
              <th>Category</th>
              <th>Default Amount</th>
              <th>Min Tenure</th>
              <th>Tax Treatment</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plans as $pl): ?>
              <tr>
                <td><strong><?= htmlspecialchars((string)$pl['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars((string)$pl['category'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>₱<?= number_format((float)$pl['default_amount'], 2) ?></td>
                <td><?= (int)$pl['min_tenure_months'] ?> mos</td>
                <td><?= !empty($pl['is_taxable']) ? 'Taxable' : 'Non-Taxable / Exempt' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($tab === 'separations'): ?>
    <div class="hb-card">
      <h3>Compute Final Pay (Resignation &amp; Age-Related Retirement)</h3>
      <p style="font-size:12.5px;color:#94a3b8;margin:0 0 14px;">
        For <strong>Retirement_Age</strong> (e.g., older employees retiring due to age with ≥ 5 years of service), automatically computes <strong>22.5 days × daily rate × years of service</strong> (RA 7641) plus pro-rated 13th month, leave conversion, and optional senior gratuity top-up.
      </p>
      <form method="post">
        <input type="hidden" name="form" value="compute_separation">
        <div class="hb-grid">
          <div>
            <label class="hb-lbl">Employee</label>
            <select name="employee_id" class="hb-sel" required>
              <option value="">-- Select employee --</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>">
                  [<?= htmlspecialchars((string)$emp['emp_code'], ENT_QUOTES, 'UTF-8') ?>]
                  <?= htmlspecialchars((string)($emp['last_name'] . ', ' . $emp['first_name']), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Separation Type</label>
            <select name="separation_type" class="hb-sel">
              <option value="Resignation">Resignation (Voluntary)</option>
              <option value="Retirement_Age">Retirement — Age / Senior (RA 7641)</option>
              <option value="End_of_Contract">End of Contract</option>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Hire Date</label>
            <input type="date" name="hire_date" value="<?= date('Y-01-01', strtotime('-5 years')) ?>" class="hb-in" required>
          </div>
          <div>
            <label class="hb-lbl">Last Working Day</label>
            <input type="date" name="last_working_day" value="<?= date('Y-m-d') ?>" class="hb-in" required>
          </div>
        </div>

        <div class="hb-grid">
          <div>
            <label class="hb-lbl">Unpaid Salary Days</label>
            <input type="number" step="0.5" min="0" name="unpaid_days" value="0" class="hb-in">
          </div>
          <div>
            <label class="hb-lbl">Unused Convertible Leave Days</label>
            <input type="number" step="0.5" min="0" name="unused_leave_days" value="0" class="hb-in">
          </div>
          <div>
            <label class="hb-lbl">YTD Basic Pay Earned (₱)</label>
            <input type="number" step="0.01" min="0" name="ytd_basic_pay" value="0" class="hb-in" placeholder="Auto-estimates if 0">
          </div>
          <div>
            <label class="hb-lbl">Extra Senior / Loyalty Gratuity (₱)</label>
            <input type="number" step="0.01" min="0" name="extra_gratuity" value="0" class="hb-in">
          </div>
          <div>
            <label class="hb-lbl">Tax Refund (₱)</label>
            <input type="number" step="0.01" min="0" name="tax_refund" value="0" class="hb-in">
          </div>
          <div>
            <label class="hb-lbl">Accountability / Deductions (₱)</label>
            <input type="number" step="0.01" min="0" name="deductions" value="0" class="hb-in">
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <label class="hb-lbl">Separation / Clearance Notes</label>
          <input type="text" name="notes" class="hb-in" placeholder="e.g. Retired due to age (60+), HMO bridge & SSS retirement endorsement">
        </div>
        <button type="submit" class="hb-btn">Calculate &amp; Save Settlement</button>
      </form>
    </div>

    <div class="hb-card">
      <h3>Final Pay &amp; Retirement Settlements</h3>
      <div class="hb-tbl-wrap">
        <table class="hb-tbl">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Type</th>
              <th>Tenure</th>
              <th>Unpaid Salary</th>
              <th>13th Month</th>
              <th>Leave Conv.</th>
              <th>Retirement / Gratuity</th>
              <th>Deductions</th>
              <th>Net Final Pay</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($settlements)): ?>
              <tr><td colspan="11" style="text-align:center;color:#94a3b8;">No final pay settlements recorded yet.</td></tr>
            <?php else: ?>
              <?php foreach ($settlements as $ss): ?>
                <tr>
                  <td><strong><?= htmlspecialchars((string)($ss['last_name'] . ', ' . $ss['first_name']), ENT_QUOTES, 'UTF-8') ?></strong></td>
                  <td><?= htmlspecialchars((string)$ss['separation_type'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= number_format((float)$ss['years_of_service'], 2) ?> yrs</td>
                  <td>₱<?= number_format((float)$ss['unpaid_salary'], 2) ?></td>
                  <td>₱<?= number_format((float)$ss['prorated_13th_month'], 2) ?></td>
                  <td>₱<?= number_format((float)$ss['leave_conversion_pay'], 2) ?></td>
                  <td style="color:#4ade80;">₱<?= number_format((float)$ss['retirement_gratuity_pay'], 2) ?></td>
                  <td style="color:#f87171;">-₱<?= number_format((float)$ss['deductions_or_accountability'], 2) ?></td>
                  <td><strong>₱<?= number_format((float)$ss['net_final_pay'], 2) ?></strong></td>
                  <td><?= htmlspecialchars((string)$ss['status'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <form method="post" style="display:inline-flex;gap:4px;">
                      <input type="hidden" name="form" value="update_settlement_status">
                      <input type="hidden" name="sep_id" value="<?= (int)$ss['id'] ?>">
                      <button type="submit" name="status" value="Approved" class="hb-btn hb-btn-sm hb-btn-warn">Approve</button>
                      <button type="submit" name="status" value="Released" class="hb-btn hb-btn-sm hb-btn-ok">Release</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php
$hrFooter = __DIR__ . '/../templates/hrTemplates/footer.php';
if (is_file($hrFooter)) {
    require_once $hrFooter;
}