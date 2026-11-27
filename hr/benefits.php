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
            `tenure_months` INT NOT NULL DEFAULT 0,
            `effective_date` DATE NOT NULL,
            `end_date` DATE NULL DEFAULT NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'Active',
            `notes` VARCHAR(255) NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_emp_ben_emp` (`employee_id`),
            KEY `idx_emp_ben_plan` (`benefit_plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    if (!ben_has_col($pdo, 'employee_benefits', 'tenure_months')) {
        $pdo->exec("ALTER TABLE `employee_benefits` ADD COLUMN `tenure_months` INT NOT NULL DEFAULT 0 AFTER `custom_amount`");
    }
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

function ben_get_base_allowance_for_employee(PDO $pdo, int $employeeId): array {
    $monthlyAllow = 0.0;
    $payType = 'Monthly';

    foreach (['employee_salaries', 'salaries'] as $tbl) {
        if (!ben_has_table($pdo, $tbl)) continue;
        $empCol = ben_has_col($pdo, $tbl, 'employee_id') ? 'employee_id' : (ben_has_col($pdo, $tbl, 'emp_id') ? 'emp_id' : null);
        if (!$empCol) continue;
        $allowCol = ben_has_col($pdo, $tbl, 'allowance') ? 'allowance' : null;
        $ptCol    = ben_has_col($pdo, $tbl, 'pay_type') ? 'pay_type' : null;
        $orderCol = ben_has_col($pdo, $tbl, 'effective_date') ? "`effective_date` DESC, `id` DESC" : "`id` DESC";

        $cols = [];
        if ($allowCol) $cols[] = "`{$allowCol}` AS allow_amt";
        if ($ptCol)    $cols[] = "`{$ptCol}` AS pay_type";
        if (empty($cols)) continue;

        $stmt = $pdo->prepare("SELECT " . implode(', ', $cols) . " FROM `{$tbl}` WHERE `{$empCol}` = ? ORDER BY {$orderCol} LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($allowCol) $monthlyAllow = round((float)($row['allow_amt'] ?? 0), 2);
            if ($ptCol && !empty($row['pay_type'])) $payType = (string)$row['pay_type'];
            break;
        }
    }

    $cutoffAllow = (strtolower(trim($payType)) === 'monthly') ? round($monthlyAllow / 2, 2) : $monthlyAllow;
    return ['monthly' => $monthlyAllow, 'cutoff' => $cutoffAllow, 'pay_type' => $payType];
}

function ben_get_active_benefits_cutoff_sum(PDO $pdo, int $employeeId, string $payType = 'Monthly', ?string $refDate = null): float {
    if ($employeeId <= 0 || !ben_has_table($pdo, 'employee_benefits') || !ben_has_table($pdo, 'benefit_plans')) {
        return 0.0;
    }
    $dateCheck = ($refDate && strtotime($refDate) !== false) ? date('Y-m-d', strtotime($refDate)) : date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(eb.custom_amount, bp.default_amount)), 0)
        FROM `employee_benefits` eb
        JOIN `benefit_plans` bp ON bp.id = eb.benefit_plan_id
        WHERE eb.employee_id = :eid
          AND LOWER(TRIM(COALESCE(eb.status, 'Active'))) = 'active'
          AND eb.effective_date <= :ref1
          AND (eb.end_date IS NULL OR eb.end_date >= :ref2)
    ");
    $stmt->execute([':eid' => $employeeId, ':ref1' => $dateCheck, ':ref2' => $dateCheck]);
    $monthlyTotal = round((float)($stmt->fetchColumn() ?: 0), 2);
    return (strtolower(trim($payType)) === 'monthly') ? round($monthlyTotal / 2, 2) : $monthlyTotal;
}

/**
 * Real-time sync: updates non-Paid payslip rows for an employee whenever a benefit is added or toggled.
 */
function ben_sync_employee_payslips_realtime(PDO $pdo, int $employeeId): int {
    if ($employeeId <= 0 || !ben_has_table($pdo, 'payslips')) {
        return 0;
    }

    $hasPeriodTbl = ben_has_table($pdo, 'payroll_periods');
    $sql = $hasPeriodTbl
        ? "SELECT ps.*, pp.end_date, pp.status AS period_status
           FROM `payslips` ps
           LEFT JOIN `payroll_periods` pp ON pp.id = ps.payroll_period_id
           WHERE ps.employee_id = :eid
             AND LOWER(TRIM(COALESCE(ps.status, ''))) != 'paid'
             AND LOWER(TRIM(COALESCE(pp.status, ''))) != 'paid'"
        : "SELECT ps.*, CURDATE() AS end_date, '' AS period_status
           FROM `payslips` ps
           WHERE ps.employee_id = :eid
             AND LOWER(TRIM(COALESCE(ps.status, ''))) != 'paid'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':eid' => $employeeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        return 0;
    }

    $baseAllowInfo = ben_get_base_allowance_for_employee($pdo, $employeeId);
    $hasBenAmtCol  = ben_has_col($pdo, 'payslips', 'benefits_amount');
    $hasBenCol     = ben_has_col($pdo, 'payslips', 'benefits');
    $hasGrossCol   = ben_has_col($pdo, 'payslips', 'gross_pay');
    $hasNetCol     = ben_has_col($pdo, 'payslips', 'net_pay');
    $hasAllowCol   = ben_has_col($pdo, 'payslips', 'allowance');

    $updated = 0;
    foreach ($rows as $ps) {
        $psId      = (int)($ps['id'] ?? 0);
        $payType   = (string)($ps['pay_type'] ?? $baseAllowInfo['pay_type'] ?? 'Monthly');
        $refDate   = (string)($ps['end_date'] ?? date('Y-m-d'));
        $benCutoff = ben_get_active_benefits_cutoff_sum($pdo, $employeeId, $payType, $refDate);

        $oldAllow  = (float)($ps['allowance'] ?? 0);
        $newAllow  = round($baseAllowInfo['cutoff'] + $benCutoff, 2);
        $delta     = round($newAllow - $oldAllow, 2);

        $setParts = [];
        $params   = [':id' => $psId];

        if ($hasAllowCol) {
            $setParts[] = "`allowance` = :allow";
            $params[':allow'] = $newAllow;
        }
        if ($hasBenAmtCol) {
            $setParts[] = "`benefits_amount` = :ben_amt";
            $params[':ben_amt'] = $benCutoff;
        } elseif ($hasBenCol) {
            $setParts[] = "`benefits` = :ben_amt";
            $params[':ben_amt'] = $benCutoff;
        }
        if ($hasGrossCol && isset($ps['gross_pay'])) {
            $newGross = round(max(0.0, (float)$ps['gross_pay'] + $delta), 2);
            $setParts[] = "`gross_pay` = :gross";
            $params[':gross'] = $newGross;
        }
        if ($hasNetCol && isset($ps['net_pay'])) {
            $newNet = round(max(0.0, (float)$ps['net_pay'] + $delta), 2);
            $setParts[] = "`net_pay` = :net";
            $params[':net'] = $newNet;
        }

        if (!empty($setParts)) {
            $upd = $pdo->prepare("UPDATE `payslips` SET " . implode(', ', $setParts) . " WHERE `id` = :id");
            $upd->execute($params);
            $updated++;
        }
    }

    return $updated;
}

function ben_compute_years_of_service(string $hireDate, string $endDate): float {
    $h = strtotime($hireDate);
    $e = strtotime($endDate);
    if ($h === false || $e === false || $e <= $h) return 0.0;
    $days = ($e - $h) / 86400.0;
    return round($days / 365.25, 2);
}

function ben_compute_tenure_info(?string $hireDate, ?string $refDate = null): array {
    if (!$hireDate || strtotime($hireDate) === false) {
        return [
            'months'     => 0,
            'years'      => 0.0,
            'label'      => '0 mos',
            'tier'       => '6–11 mos Tenure',
            'multiplier' => 1.00,
        ];
    }
    $start = new DateTime(date('Y-m-d', strtotime($hireDate)));
    $end   = new DateTime(($refDate && strtotime($refDate) !== false) ? date('Y-m-d', strtotime($refDate)) : 'today');
    if ($end < $start) {
        $end = clone $start;
    }
    $diff = $start->diff($end);
    $totalMonths = max(0, ($diff->y * 12) + $diff->m + ($diff->d >= 15 ? 1 : 0));
    $yearsExact  = round($totalMonths / 12, 2);

    if ($totalMonths < 6) {
        $tier = 'Probationary (< 6 mos)';
        $mult = 0.50;
    } elseif ($totalMonths < 12) {
        $tier = '6–11 mos Tenure';
        $mult = 1.00;
    } elseif ($totalMonths < 36) {
        $tier = '1–2 yrs Tenure (+10%)';
        $mult = 1.10;
    } elseif ($totalMonths < 60) {
        $tier = '3–4 yrs Tenure (+25%)';
        $mult = 1.25;
    } else {
        $tier = '5+ yrs Veteran (+50%)';
        $mult = 1.50;
    }

    $parts = [];
    if ($diff->y > 0) $parts[] = $diff->y . ' yr' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) $parts[] = $diff->m . ' mo' . ($diff->m > 1 ? 's' : '');
    if (empty($parts)) $parts[] = $diff->d . ' day' . ($diff->d !== 1 ? 's' : '');

    return [
        'months'     => $totalMonths,
        'years'      => $yearsExact,
        'label'      => implode(' ', $parts),
        'tier'       => $tier,
        'multiplier' => $mult,
    ];
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
            $effectiveDate = trim((string)($_POST['effective_date'] ?? date('Y-m-d')));
            $applyTenure   = !empty($_POST['apply_tenure_rule']);
            $notes         = trim((string)($_POST['notes'] ?? ''));

            if ($empId <= 0 || $planId <= 0) throw new RuntimeException('Please choose both an employee and a benefit plan.');

            $empStmt = $pdo->prepare("SELECT date_hired FROM employees WHERE id = ? LIMIT 1");
            $empStmt->execute([$empId]);
            $empHireDate = (string)($empStmt->fetchColumn() ?: '');

            $planStmt = $pdo->prepare("SELECT default_amount, min_tenure_months FROM benefit_plans WHERE id = ? LIMIT 1");
            $planStmt->execute([$planId]);
            $planRow = $planStmt->fetch(PDO::FETCH_ASSOC) ?: ['default_amount' => 0, 'min_tenure_months' => 0];

            $tenureInfo = ben_compute_tenure_info($empHireDate, $effectiveDate);
            $minReqMonths = (int)($planRow['min_tenure_months'] ?? 0);
            if ($minReqMonths > 0 && $tenureInfo['months'] < $minReqMonths) {
                throw new RuntimeException("Employee has {$tenureInfo['label']} tenure, which is below the plan's {$minReqMonths}-month minimum tenure.");
            }

            if ($customAmtRaw !== '') {
                $finalAmount = max(0, (float)$customAmtRaw);
            } else {
                $baseAmt = (float)($planRow['default_amount'] ?? 0);
                $finalAmount = $applyTenure ? round($baseAmt * $tenureInfo['multiplier'], 2) : $baseAmt;
            }

            $stmt = $pdo->prepare("
                INSERT INTO `employee_benefits` (`employee_id`, `benefit_plan_id`, `custom_amount`, `tenure_months`, `effective_date`, `status`, `notes`)
                VALUES (?, ?, ?, ?, ?, 'Active', ?)
            ");
            $stmt->execute([$empId, $planId, $finalAmount, (int)$tenureInfo['months'], $effectiveDate, $notes !== '' ? $notes : null]);

            $syncedSlips = ben_sync_employee_payslips_realtime($pdo, $empId);
            $flashOk = 'Benefit assigned & activated (₱' . number_format($finalAmount, 2) . ' • Tenure: ' . $tenureInfo['label'] . ')'
                . ($syncedSlips > 0 ? " — synced to {$syncedSlips} payslip(s) in real time." : ' — ready for payslips.');
            $tab = 'active_benefits';
        } elseif ($form === 'toggle_benefit_status') {
            $ebId   = (int)($_POST['eb_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'Active'));
            if (!in_array($status, ['Active', 'Suspended', 'Claimed'], true)) {
                throw new RuntimeException('Invalid benefit status.');
            }

            $empLookup = $pdo->prepare("SELECT employee_id FROM `employee_benefits` WHERE `id` = ? LIMIT 1");
            $empLookup->execute([$ebId]);
            $targetEmpId = (int)($empLookup->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("UPDATE `employee_benefits` SET `status` = ? WHERE `id` = ?");
            $stmt->execute([$status, $ebId]);

            $syncedSlips = $targetEmpId > 0 ? ben_sync_employee_payslips_realtime($pdo, $targetEmpId) : 0;
            $flashOk = 'Benefit status set to ' . $status
                . ($syncedSlips > 0 ? " and synced to {$syncedSlips} payslip(s) in real time." : '.');
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
            $dailyRate   = round(($monthlyRate * 12.0) / 261.0, 2);
            $yrsService  = ben_compute_years_of_service($hireDate, $lastDay);

            $unpaidSalary = round($unpaidDays * $dailyRate, 2);

            if ($ytdBasicPay <= 0) {
                $ytdStart = date('Y-01-01', strtotime($lastDay));
                $monthsElapsed = max(1.0, ((strtotime($lastDay) - strtotime($ytdStart)) / 86400.0) / 30.4375);
                $ytdBasicPay = round($monthlyRate * min(12.0, $monthsElapsed), 2);
            }
            $prorated13th = round($ytdBasicPay / 12.0, 2);
            $leavePay     = round($unusedLeave * $dailyRate, 2);

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

$departments = [];
try {
    if (ben_has_table($pdo, 'departments')) {
        $deptCols = $pdo->query('SHOW COLUMNS FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        $deptColsLower = array_map('strtolower', $deptCols);
        $nameCol = 'name';
        foreach (['department_name', 'name', 'dept_name', 'title'] as $cand) {
            $idx = array_search($cand, $deptColsLower, true);
            if ($idx !== false) {
                $nameCol = $deptCols[$idx];
                break;
            }
        }
        $departments = $pdo->query("SELECT id, `{$nameCol}` AS dept_name FROM departments ORDER BY `{$nameCol}` ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$employees = $pdo->query("
    SELECT e.id,
           COALESCE(e.employee_id, e.id) AS emp_code,
           e.first_name,
           e.last_name,
           e.employment_status,
           e.date_hired,
           e.department_id,
           COALESCE(d.name, '') AS dept_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($departments)) {
    $seenDepts = [];
    foreach ($employees as $e) {
        $did = (int)($e['department_id'] ?? 0);
        $dnm = trim((string)($e['dept_name'] ?? ''));
        if ($did > 0 && $dnm !== '' && !isset($seenDepts[$did])) {
            $seenDepts[$did] = true;
            $departments[] = ['id' => $did, 'dept_name' => $dnm];
        }
    }
}

$empHireMap = [];
foreach ($employees as $empRow) {
    $empHireMap[(int)$empRow['id']] = (string)($empRow['date_hired'] ?? '');
}

$plans = $pdo->query("SELECT * FROM benefit_plans ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

$empBenefits = $pdo->query("
    SELECT eb.*, bp.name AS plan_name, bp.category, bp.default_amount, bp.is_taxable,
           e.first_name, e.last_name, e.date_hired, COALESCE(e.employee_id, e.id) AS emp_code
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

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css">
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
  .hb-pill { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: rgba(59,130,246,0.2); color: #93c5fd; display: inline-block; }
  .hb-pill-active { background: rgba(22,163,74,0.22); color: #4ade80; }
  .hb-pill-hold { background: rgba(217,119,6,0.22); color: #fbbf24; }
  .hb-pill-claimed { background: rgba(59,130,246,0.22); color: #93c5fd; }
  .tenure-box {
    margin-bottom: 12px;
    padding: 10px 14px;
    border-radius: 10px;
    background: rgba(59, 130, 246, 0.12);
    border: 1px solid rgba(96, 165, 250, 0.35);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 12.5px;
    color: #e2e8f0;
  }

  /* Tom Select dark theme integration */
  .ts-wrapper.hb-ts { width: 100%; }
  .ts-wrapper.hb-ts .ts-control,
  .ts-wrapper.hb-ts.single .ts-control,
  .ts-wrapper.hb-ts.single.input-active .ts-control {
    background: #0f172a !important;
    background-color: #0f172a !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
    border-radius: 8px !important;
    padding: 7px 10px !important;
    min-height: 36px;
    font-size: 13px;
    box-shadow: none !important;
  }
  .ts-wrapper.hb-ts .ts-control > .item {
    color: #f8fafc !important;
    background: transparent !important;
  }
  .ts-wrapper.hb-ts .ts-control > input,
  .ts-wrapper.hb-ts .ts-control > input::placeholder {
    color: #94a3b8 !important;
  }
  .ts-wrapper.hb-ts .ts-dropdown,
  .ts-wrapper.hb-ts.single .ts-dropdown {
    background: #0f172a !important;
    background-color: #0f172a !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
    border-radius: 8px !important;
    margin-top: 4px;
    z-index: 9999;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.55) !important;
  }
  .ts-wrapper.hb-ts .ts-dropdown .option {
    padding: 8px 10px !important;
    color: #e2e8f0 !important;
    background: transparent !important;
  }
  .ts-wrapper.hb-ts .ts-dropdown .option:hover,
  .ts-wrapper.hb-ts .ts-dropdown .option.active,
  .ts-wrapper.hb-ts .ts-dropdown .selected {
    background: #2563eb !important;
    color: #ffffff !important;
  }

  .dataTables_wrapper { color: #e2e8f0; font-size: 12.5px; }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: #0f172a;
    color: #f8fafc;
    border: 1px solid #334155;
    border-radius: 7px;
    padding: 4px 8px;
    margin-left: 5px;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate { margin-top: 10px; color: #94a3b8 !important; }
  .dataTables_wrapper .dataTables_paginate .paginate_button { color: #e2e8f0 !important; border-radius: 6px !important; }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #2563eb !important;
    color: #fff !important;
    border-color: transparent !important;
  }
</style>

<div class="hb-wrap">
  <div class="hb-head">
    <div>
      <h1 style="margin:0;font-size:24px;">Benefits &amp; Separation / Retirement Pay</h1>
      <p style="margin:4px 0 0;font-size:13px;color:#94a3b8;">Active benefits automatically sync in real time to HR &amp; Employee payslips when assigned or set to Active.</p>
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
            <label class="hb-lbl">Department</label>
            <select id="ben_dept_select" class="hb-sel">
              <option value="">All Departments</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?= (int)$dept['id'] ?>"><?= htmlspecialchars((string)$dept['dept_name'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Employee (Searchable)</label>
            <select name="employee_id" id="ben_emp_select" class="hb-sel searchable-emp" placeholder="Type name or EMP ID..." required>
              <option value="">-- Select employee --</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>" data-dept-id="<?= (int)($emp['department_id'] ?? 0) ?>">
                  [<?= htmlspecialchars((string)$emp['emp_code'], ENT_QUOTES, 'UTF-8') ?>]
                  <?= htmlspecialchars((string)($emp['last_name'] . ', ' . $emp['first_name']), ENT_QUOTES, 'UTF-8') ?>
                  (<?= htmlspecialchars((string)$emp['employment_status'], ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Benefit Plan</label>
            <select name="benefit_plan_id" id="ben_plan_select" class="hb-sel" required>
              <?php foreach ($plans as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>" data-amount="<?= (float)$pl['default_amount'] ?>" data-min-months="<?= (int)$pl['min_tenure_months'] ?>">
                  <?= htmlspecialchars((string)$pl['name'], ENT_QUOTES, 'UTF-8') ?> — ₱<?= number_format((float)$pl['default_amount'], 2) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Custom Amount (₱, optional)</label>
            <input type="number" step="0.01" min="0" name="custom_amount" id="ben_custom_amount" class="hb-in" placeholder="Uses tenure-adjusted default">
          </div>
          <div>
            <label class="hb-lbl">Effective Date</label>
            <input type="date" name="effective_date" id="ben_effective_date" value="<?= date('Y-m-d') ?>" class="hb-in" required>
          </div>
        </div>

        <div class="tenure-box" id="benTenureBox">
          <div>
            <strong>Length of Service (Tenure):</strong>
            <span id="benTenureSummary">Select an employee to view hire date, tenure, and tenure-adjusted benefit.</span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" name="apply_tenure_rule" id="benApplyTenureRule" value="1" checked>
              <span>Apply Tenure Multiplier</span>
            </label>
            <span class="hb-pill" id="benTenureTier">No Employee</span>
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
        <table class="hb-tbl ben-datatable">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Tenure</th>
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
              <tr><td colspan="9" style="text-align:center;color:#94a3b8;">No benefits assigned yet.</td></tr>
            <?php else: ?>
              <?php foreach ($empBenefits as $eb): ?>
                <?php
                  $amt = $eb['custom_amount'] !== null ? (float)$eb['custom_amount'] : (float)$eb['default_amount'];
                  $tInfo = ben_compute_tenure_info($eb['date_hired'] ?? null, $eb['effective_date'] ?? null);
                  $stVal = (string)($eb['status'] ?? 'Active');
                  $stClass = match (strtolower(trim($stVal))) {
                    'active'    => 'hb-pill-active',
                    'suspended' => 'hb-pill-hold',
                    'claimed'   => 'hb-pill-claimed',
                    default     => '',
                  };
                ?>
                <tr>
                  <td><strong><?= htmlspecialchars((string)($eb['last_name'] . ', ' . $eb['first_name']), ENT_QUOTES, 'UTF-8') ?></strong></td>
                  <td><?= htmlspecialchars($tInfo['label'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)$eb['plan_name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><span class="hb-pill"><?= htmlspecialchars((string)$eb['category'], ENT_QUOTES, 'UTF-8') ?></span></td>
                  <td>₱<?= number_format($amt, 2) ?></td>
                  <td><?= !empty($eb['is_taxable']) ? 'Taxable' : 'Non-Taxable' ?></td>
                  <td><?= htmlspecialchars((string)$eb['effective_date'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><span class="hb-pill <?= $stClass ?>"><?= htmlspecialchars($stVal, ENT_QUOTES, 'UTF-8') ?></span></td>
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
        <table class="hb-tbl ben-datatable">
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
            <label class="hb-lbl">Department</label>
            <select id="sep_dept_select" class="hb-sel">
              <option value="">All Departments</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?= (int)$dept['id'] ?>"><?= htmlspecialchars((string)$dept['dept_name'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="hb-lbl">Employee (Searchable)</label>
            <select name="employee_id" id="sep_emp_select" class="hb-sel searchable-emp" placeholder="Type name or EMP ID..." required>
              <option value="">-- Select employee --</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>" data-dept-id="<?= (int)($emp['department_id'] ?? 0) ?>">
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
            <input type="date" name="hire_date" id="sep_hire_date" value="<?= date('Y-01-01', strtotime('-5 years')) ?>" class="hb-in" required>
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
        <table class="hb-tbl ben-datatable">
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
(function () {
  const empHireMap = <?= json_encode($empHireMap, JSON_UNESCAPED_UNICODE) ?>;

  function calcTenure(hireStr, refStr) {
    if (!hireStr) {
      return { months: 0, years: 0, label: 'No hire date', tier: '6–11 mos Tenure', mult: 1.0 };
    }
    const start = new Date(hireStr.slice(0, 10) + 'T00:00:00');
    const end   = refStr ? new Date(refStr.slice(0, 10) + 'T00:00:00') : new Date();
    if (Number.isNaN(start.getTime())) {
      return { months: 0, years: 0, label: 'Invalid hire date', tier: 'Standard', mult: 1.0 };
    }
    const safeEnd = Number.isNaN(end.getTime()) || end < start ? start : end;

    let years = safeEnd.getFullYear() - start.getFullYear();
    let months = safeEnd.getMonth() - start.getMonth();
    let days = safeEnd.getDate() - start.getDate();

    if (days < 0) {
      months -= 1;
      days += new Date(safeEnd.getFullYear(), safeEnd.getMonth(), 0).getDate();
    }
    if (months < 0) {
      years -= 1;
      months += 12;
    }

    const totalMonths = Math.max(0, years * 12 + months + (days >= 15 ? 1 : 0));
    const exactYears  = Number((totalMonths / 12).toFixed(2));

    let tier = '6–11 mos Tenure';
    let mult = 1.00;
    if (totalMonths < 6) {
      tier = 'Probationary (< 6 mos)';
      mult = 0.50;
    } else if (totalMonths < 12) {
      tier = '6–11 mos Tenure';
      mult = 1.00;
    } else if (totalMonths < 36) {
      tier = '1–2 yrs Tenure (+10%)';
      mult = 1.10;
    } else if (totalMonths < 60) {
      tier = '3–4 yrs Tenure (+25%)';
      mult = 1.25;
    } else {
      tier = '5+ yrs Veteran (+50%)';
      mult = 1.50;
    }

    const parts = [];
    if (years > 0) parts.push(`${years} yr${years > 1 ? 's' : ''}`);
    if (months > 0) parts.push(`${months} mo${months > 1 ? 's' : ''}`);
    if (!parts.length) parts.push(`${days} day${days !== 1 ? 's' : ''}`);

    return { months: totalMonths, years: exactYears, label: parts.join(' '), tier, mult };
  }

  function updateBenefitTenurePreview() {
    const empSel = document.getElementById('ben_emp_select');
    const planSel = document.getElementById('ben_plan_select');
    const effInput = document.getElementById('ben_effective_date');
    const customInput = document.getElementById('ben_custom_amount');
    const applyChk = document.getElementById('benApplyTenureRule');
    const summaryEl = document.getElementById('benTenureSummary');
    const tierEl = document.getElementById('benTenureTier');

    if (!empSel || !planSel || !summaryEl || !tierEl) return;

    const empId = Number(empSel.value || 0);
    if (!empId) {
      summaryEl.textContent = 'Select an employee to view hire date, tenure, and tenure-adjusted benefit.';
      tierEl.textContent = 'No Employee';
      return;
    }

    const hireDate = empHireMap[empId] || '';
    const refDate = effInput ? effInput.value : '';
    const t = calcTenure(hireDate, refDate);
    const opt = planSel.selectedOptions[0];
    const baseAmt = opt ? parseFloat(opt.getAttribute('data-amount') || '0') : 0;
    const minMonths = opt ? parseInt(opt.getAttribute('data-min-months') || '0', 10) : 0;
    const mult = (!applyChk || applyChk.checked) ? t.mult : 1.0;
    const computedAmt = (baseAmt * mult).toFixed(2);

    tierEl.textContent = t.tier;
    let extra = ` • Base: ₱${baseAmt.toLocaleString('en-PH', { minimumFractionDigits: 2 })} → Tenure Amount: ₱${Number(computedAmt).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
    if (minMonths > 0 && t.months < minMonths) {
      extra += ` (Requires ${minMonths} mos)`;
    }
    if (customInput && !customInput.value) {
      customInput.placeholder = `Auto: ₱${computedAmt}`;
    }

    summaryEl.textContent = `Hired: ${hireDate ? hireDate.slice(0, 10) : 'N/A'} • Tenure: ${t.label} (${t.years} yrs)${extra}`;
  }

  function initSearchableEmployeeWithDept(deptSelectId, empSelectId, onEmployeeChange) {
    const deptEl = document.getElementById(deptSelectId);
    const empEl  = document.getElementById(empSelectId);
    if (!empEl || typeof TomSelect === 'undefined') return null;

    const allOptions = Array.from(empEl.querySelectorAll('option'))
      .filter(opt => opt.value !== '')
      .map(opt => ({
        value: String(opt.value),
        text: opt.textContent.trim(),
        deptId: String(opt.getAttribute('data-dept-id') || '')
      }));

    const ts = new TomSelect(empEl, {
      create: false,
      allowEmptyOption: true,
      maxOptions: 500,
      placeholder: 'Search employee by name or ID...',
      searchField: ['text'],
      onChange: function (val) {
        if (deptEl && val) {
          const matched = allOptions.find(o => o.value === String(val));
          if (matched && matched.deptId && deptEl.value !== matched.deptId) {
            deptEl.value = matched.deptId;
          }
        }
        if (typeof onEmployeeChange === 'function') onEmployeeChange(val);
      }
    });

    ts.wrapper.classList.add('hb-ts');

    if (deptEl) {
      deptEl.addEventListener('change', function () {
        const selectedDept = String(this.value || '');
        const currentVal = String(ts.getValue() || '');

        ts.clear(true);
        ts.clearOptions();

        const filtered = selectedDept === ''
          ? allOptions
          : allOptions.filter(o => o.deptId === selectedDept);

        ts.addOption(filtered);
        ts.refreshOptions(false);

        if (currentVal && filtered.some(o => o.value === currentVal)) {
          ts.setValue(currentVal, true);
        }
        if (typeof onEmployeeChange === 'function') onEmployeeChange(ts.getValue());
      });
    }

    return ts;
  }

  $(function () {
    initSearchableEmployeeWithDept('ben_dept_select', 'ben_emp_select', updateBenefitTenurePreview);
    initSearchableEmployeeWithDept('sep_dept_select', 'sep_emp_select', function (val) {
      const hireDate = empHireMap[Number(val || 0)] || '';
      const sepHire = document.getElementById('sep_hire_date');
      if (sepHire && hireDate) {
        sepHire.value = hireDate.slice(0, 10);
      }
    });

    $('#ben_plan_select, #ben_effective_date, #benApplyTenureRule').on('change', updateBenefitTenurePreview);
    updateBenefitTenurePreview();

    $('table.ben-datatable').each(function () {
      const $tbl = $(this);
      const hasEmptyColspan = $tbl.find('tbody tr').length === 1 && $tbl.find('tbody tr td[colspan]').length === 1;
      if (!hasEmptyColspan) {
        const colCount = $tbl.find('thead th').length;
        $tbl.DataTable({
          pageLength: 10,
          lengthMenu: [10, 25, 50],
          order: [],
          columnDefs: colCount > 1 ? [{ orderable: false, targets: colCount - 1 }] : [],
          language: {
            search: 'Filter table:',
            lengthMenu: 'Show _MENU_ entries'
          }
        });
      }
    });
  });
})();
</script>

<?php
$hrFooter = __DIR__ . '/../templates/hrTemplates/footer.php';
if (is_file($hrFooter)) {
    require_once $hrFooter;
}