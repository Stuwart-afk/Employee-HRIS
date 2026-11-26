<?php
// includes/overtime_logic.php

date_default_timezone_set('Asia/Manila');

function ot_logic_has_table(PDO $pdo, string $table): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ot_logic_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) return false;
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resolves the employee's active HR schedule (shift_start, shift_end) for a given date.
 */
function ot_get_employee_shift(PDO $pdo, int $employeeId, string $workDate): array {
    if (function_exists('sched_get_for_employee_date')) {
        try {
            $s = sched_get_for_employee_date($pdo, $employeeId, $workDate);
            if (!empty($s['shift_start']) && !empty($s['shift_end'])) {
                return [
                    'shift_start' => (string)$s['shift_start'],
                    'shift_end'   => (string)$s['shift_end'],
                ];
            }
        } catch (Throwable $e) {}
    }

    // Fallback: check employee_schedules -> schedules if present
    if (ot_logic_has_table($pdo, 'employee_schedules') && ot_logic_has_table($pdo, 'schedules')) {
        try {
            $stmt = $pdo->prepare("
                SELECT s.`start_time` AS shift_start, s.`end_time` AS shift_end
                FROM `employee_schedules` es
                INNER JOIN `schedules` s ON s.`id` = es.`schedule_id`
                WHERE es.`employee_id` = :eid
                  AND (es.`effective_date` IS NULL OR es.`effective_date` <= :d)
                ORDER BY es.`effective_date` DESC, es.`id` DESC
                LIMIT 1
            ");
            $stmt->execute([':eid' => $employeeId, ':d' => $workDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['shift_start']) && !empty($row['shift_end'])) {
                return [
                    'shift_start' => (string)$row['shift_start'],
                    'shift_end'   => (string)$row['shift_end'],
                ];
            }
        } catch (Throwable $e) {}
    }

    return [
        'shift_start' => '08:00:00',
        'shift_end'   => '17:00:00',
    ];
}

/**
 * Auto times out open attendance records once current time passes the employee's scheduled_end.
 */
function ot_apply_auto_timeout(PDO $pdo, string $targetDate): void {
    if (!ot_logic_has_table($pdo, 'attendance_records')) {
        return;
    }

    $nowTs = time();
    $today = date('Y-m-d');

    if ($targetDate > $today) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT `id`, `employee_id`, `work_date`, `time_in`, `scheduled_start`, `scheduled_end`, `status`, `notes`
        FROM `attendance_records`
        WHERE `work_date` = :d
          AND `time_in` IS NOT NULL
          AND `time_in` <> ''
          AND `time_in` <> '00:00:00'
          AND (`time_out` IS NULL OR `time_out` = '' OR `time_out` = '00:00:00')
          AND `status` <> 'Absent'
    ");
    $stmt->execute([':d' => $targetDate]);
    $openRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($openRows as $row) {
        $empId      = (int)$row['employee_id'];
        $shift      = ot_get_employee_shift($pdo, $empId, $targetDate);
        $schedStart = !empty($row['scheduled_start']) ? (string)$row['scheduled_start'] : $shift['shift_start'];
        $schedEnd   = !empty($row['scheduled_end']) ? (string)$row['scheduled_end'] : $shift['shift_end'];

        $startTs = strtotime($targetDate . ' ' . $schedStart);
        $endTs   = strtotime($targetDate . ' ' . $schedEnd);
        if ($endTs !== false && $startTs !== false && $endTs <= $startTs) {
            // Night-shift rollover past midnight
            $endTs = strtotime('+1 day', $endTs);
        }

        if ($targetDate < $today || ($endTs !== false && $nowTs >= $endTs)) {
            $existingNotes = trim((string)($row['notes'] ?? ''));
            $autoTag = 'Auto timed out at scheduled shift end (' . substr($schedEnd, 0, 5) . ')';
            $newNotes = $existingNotes === ''
                ? $autoTag
                : (stripos($existingNotes, 'Auto timed out') !== false ? $existingNotes : $existingNotes . ' | ' . $autoTag);

            $upd = $pdo->prepare("
                UPDATE `attendance_records`
                SET `time_out` = :tout,
                    `scheduled_start` = :sstart,
                    `scheduled_end` = :send,
                    `status` = CASE WHEN `status` = 'On Break' THEN 'Present' ELSE `status` END,
                    `notes` = :notes
                WHERE `id` = :id
            ");
            $upd->execute([
                ':tout'   => $schedEnd,
                ':sstart' => $schedStart,
                ':send'   => $schedEnd,
                ':notes'  => $newNotes,
                ':id'     => (int)$row['id'],
            ]);
        }
    }
}

/**
 * Syncs approved overtime minutes into attendance_records so worked hours & payroll include approved OT.
 */
function ot_sync_approved_to_attendance(PDO $pdo, int $employeeId, string $workDate): int {
    if (!ot_logic_has_table($pdo, 'overtime_requests')) {
        return 0;
    }

    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(`approved_minutes`), 0)
        FROM `overtime_requests`
        WHERE `employee_id` = ? AND `work_date` = ? AND `status` = 'Approved'
    ");
    $sumStmt->execute([$employeeId, $workDate]);
    $totalApproved = max(0, (int)$sumStmt->fetchColumn());

    if (ot_logic_has_table($pdo, 'attendance_records')) {
        $shift = ot_get_employee_shift($pdo, $employeeId, $workDate);

        // Ensure an attendance row exists if OT was approved on a Rest Day / Holiday
        $chk = $pdo->prepare("SELECT `id`, `time_in`, `status` FROM `attendance_records` WHERE `employee_id` = ? AND `work_date` = ? LIMIT 1");
        $chk->execute([$employeeId, $workDate]);
        $attRow = $chk->fetch(PDO::FETCH_ASSOC);

        if ($attRow) {
            $upd = $pdo->prepare("
                UPDATE `attendance_records`
                SET `overtime_minutes` = ?
                WHERE `id` = ?
            ");
            $upd->execute([$totalApproved, (int)$attRow['id']]);
        } elseif ($totalApproved > 0) {
            $ins = $pdo->prepare("
                INSERT INTO `attendance_records`
                    (`employee_id`, `work_date`, `scheduled_start`, `scheduled_end`, `late_minutes`, `overtime_minutes`, `status`, `notes`)
                VALUES (?, ?, ?, ?, 0, ?, 'Rest Day OT', 'Credited from approved overtime')
            ");
            $ins->execute([$employeeId, $workDate, $shift['shift_start'], $shift['shift_end'], $totalApproved]);
        }
    }

    return $totalApproved;
}

/**
 * Fetches an employee's hourly rate from employees table (supports hourly_rate, daily_rate, or monthly basic_salary).
 */
function ot_get_employee_hourly_rate(PDO $pdo, int $employeeId): float {
    if (!ot_logic_has_table($pdo, 'employees')) {
        return 0.0;
    }

    $cols = [];
    foreach (['hourly_rate', 'daily_rate', 'basic_salary', 'salary', 'monthly_salary'] as $c) {
        if (ot_logic_has_col($pdo, 'employees', $c)) {
            $cols[] = "`{$c}`";
        }
    }
    if (!$cols) {
        return 0.0;
    }

    try {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $cols) . " FROM `employees` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!empty($r['hourly_rate']) && (float)$r['hourly_rate'] > 0) {
            return (float)$r['hourly_rate'];
        }
        if (!empty($r['daily_rate']) && (float)$r['daily_rate'] > 0) {
            return (float)$r['daily_rate'] / 8.0;
        }
        $monthly = (float)($r['basic_salary'] ?? $r['monthly_salary'] ?? $r['salary'] ?? 0);
        if ($monthly > 0) {
            // Standard 22 working days * 8 hours = 176 hours/month
            return $monthly / 176.0;
        }
    } catch (Throwable $e) {}

    return 0.0;
}

/**
 * Computes regular worked minutes, approved OT minutes, total worked hours,
 * and overtime pay impact for an employee over a date range.
 */
function ot_calculate_payroll_hours(PDO $pdo, int $employeeId, string $dateFrom, string $dateTo, ?float $hourlyRateOverride = null): array {
    $hourlyRate = $hourlyRateOverride !== null ? $hourlyRateOverride : ot_get_employee_hourly_rate($pdo, $employeeId);

    $attRows = [];
    if (ot_logic_has_table($pdo, 'attendance_records')) {
        $attStmt = $pdo->prepare("
            SELECT `work_date`, `time_in`, `time_out`, `break_start`, `break_end`, `scheduled_start`, `scheduled_end`, `overtime_minutes`, `status`
            FROM `attendance_records`
            WHERE `employee_id` = :eid
              AND `work_date` BETWEEN :f_d AND :t_d
        ");
        $attStmt->execute([':eid' => $employeeId, ':f_d' => $dateFrom, ':t_d' => $dateTo]);
        $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $approvedOtRows = [];
    if (ot_logic_has_table($pdo, 'overtime_requests')) {
        $otStmt = $pdo->prepare("
            SELECT `work_date`, `ot_type`, `approved_minutes`
            FROM `overtime_requests`
            WHERE `employee_id` = :eid
              AND `work_date` BETWEEN :f_d AND :t_d
              AND `status` = 'Approved'
        ");
        $otStmt->execute([':eid' => $employeeId, ':f_d' => $dateFrom, ':t_d' => $dateTo]);
        $approvedOtRows = $otStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $multipliers = [
        'Regular Day'                 => 1.25,
        'Rest Day'                    => 1.30,
        'Special Non-Working Holiday' => 1.30,
        'Regular Holiday'             => 2.00,
    ];

    $approvedMinsByDate = [];
    $overtimePay        = 0.0;
    $approvedOtMinutes  = 0;

    foreach ($approvedOtRows as $ot) {
        $d    = (string)$ot['work_date'];
        $mins = max(0, (int)$ot['approved_minutes']);
        $type = (string)($ot['ot_type'] ?? 'Regular Day');
        $mult = $multipliers[$type] ?? 1.25;

        $approvedMinsByDate[$d] = ($approvedMinsByDate[$d] ?? 0) + $mins;
        $approvedOtMinutes += $mins;
        $overtimePay += ($mins / 60.0) * $hourlyRate * $mult;
    }

    $regularMinutes = 0;
    foreach ($attRows as $r) {
        if (strcasecmp((string)$r['status'], 'Absent') === 0 || empty($r['time_in'])) {
            continue;
        }
        $d     = (string)$r['work_date'];
        $inTs  = strtotime($d . ' ' . $r['time_in']);
        $outStr = !empty($r['time_out']) ? (string)$r['time_out'] : (string)($r['scheduled_end'] ?? '17:00:00');
        $outTs = strtotime($d . ' ' . $outStr);
        if ($outTs !== false && $inTs !== false && $outTs <= $inTs) {
            $outTs = strtotime('+1 day', $outTs);
        }

        if ($inTs !== false && $outTs !== false && $outTs > $inTs) {
            $workedMins = (int)floor(($outTs - $inTs) / 60);

            // Deduct recorded break duration if both break_start and break_end exist
            if (!empty($r['break_start']) && !empty($r['break_end'])) {
                $bStart = strtotime($d . ' ' . $r['break_start']);
                $bEnd   = strtotime($d . ' ' . $r['break_end']);
                if ($bStart !== false && $bEnd !== false && $bEnd > $bStart) {
                    $workedMins = max(0, $workedMins - (int)floor(($bEnd - $bStart) / 60));
                }
            }

            // Cap regular shift at scheduled_end so unapproved extra time is excluded and approved OT isn't double-counted
            $schedStartTs = strtotime($d . ' ' . ($r['scheduled_start'] ?? '08:00:00'));
            $schedEndTs   = strtotime($d . ' ' . ($r['scheduled_end'] ?? '17:00:00'));
            if ($schedStartTs !== false && $schedEndTs !== false && $schedEndTs > $schedStartTs) {
                $maxSchedMins = (int)floor(($schedEndTs - $schedStartTs) / 60);
                $workedMins   = min($workedMins, $maxSchedMins);
            }

            $regularMinutes += $workedMins;
        }
    }

    $totalWorkedMinutes = $regularMinutes + $approvedOtMinutes;
    $regularPay         = ($regularMinutes / 60.0) * $hourlyRate;
    $totalPay           = $regularPay + $overtimePay;

    return [
        'hourly_rate'          => round($hourlyRate, 2),
        'regular_minutes'      => $regularMinutes,
        'regular_hours'        => round($regularMinutes / 60.0, 2),
        'approved_ot_minutes'  => $approvedOtMinutes,
        'approved_ot_hours'    => round($approvedOtMinutes / 60.0, 2),
        'total_worked_minutes' => $totalWorkedMinutes,
        'total_worked_hours'   => round($totalWorkedMinutes / 60.0, 2),
        'regular_pay'          => round($regularPay, 2),
        'overtime_pay'         => round($overtimePay, 2),
        'total_pay'            => round($totalPay, 2),
    ];
}