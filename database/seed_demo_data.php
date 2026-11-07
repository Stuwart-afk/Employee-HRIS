<?php
/**
 * HRIS DEMO DATA SEEDER
 * Purpose: Demonstrate all 7 DSA implementations for professor review
 *
 * DSA Coverage:
 *   Linear Search     — searchable employees across 3 departments
 *   Bubble Sort       — employees sortable by name/department/status
 *   Merge Sort        — departments with varied headcount
 *   Quick Sort        — payslips sortable by employee name
 *   Binary Search     — late minute brackets in payroll
 *   Stack             — leave requests with multi-step status history
 *   Queue             — multiple pending requests in FIFO order
 *   Linked List       — 4 payroll periods chained prev/next
 *   BST               — employee lookup by EMP-001 format
 *   Hash Table        — department/position cache
 *
 * Run:
 *   docker exec -it hris_app php database/seed_demo_data.php
 */

date_default_timezone_set('Asia/Manila');

$host   = getenv('DB_HOST') ?: 'localhost';
$port   = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'hris';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$pdo->exec("SET time_zone = '+08:00'");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

try {
    $pdo->beginTransaction();

    // =========================================================
    // PART 1: ADD 2 MORE EMPLOYEES (HR department)
    // So we have employees across all 3 departments:
    //   IT      — Brandon (EMP-001), Kaiser (EMP-002)
    //   Finance — Donald  (EMP-003)
    //   HR      — Maria   (EMP-004), Jose   (EMP-005) ← new
    // =========================================================

    $newEmployees = [
        [
            'employee_id' => 'EMP-004',
            'first_name'  => 'Maria',
            'middle_name' => 'Santos',
            'last_name'   => 'Cruz',
            'dob'         => '1994-03-22',
            'gender'      => 'Female',
            'phone'       => '09171234504',
            'email'       => 'maria@example.com',
            'address'     => 'Cebu City, Cebu',
            'dept'        => 1,
            'pos'         => 1,
            'hired'       => '2021-01-15',
            'salary'      => 35000,
            'allowance'   => 3000,
            'username'    => 'maria',
            'password'    => 'maria123',
        ],
        [
            'employee_id' => 'EMP-005',
            'first_name'  => 'Jose',
            'middle_name' => 'Rivera',
            'last_name'   => 'Aquino',
            'dob'         => '1990-07-10',
            'gender'      => 'Male',
            'phone'       => '09171234505',
            'email'       => 'jose@example.com',
            'address'     => 'Mandaue City, Cebu',
            'dept'        => 1,
            'pos'         => 5,
            'hired'       => '2020-06-01',
            'salary'      => 40000,
            'allowance'   => 3500,
            'username'    => 'jose',
            'password'    => 'jose123',
        ],
    ];

    $allEmployeeIds = [1, 2, 3];
    $newIds         = [];

    foreach ($newEmployees as $e) {
        $chk = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ? LIMIT 1");
        $chk->execute([$e['employee_id']]);
        $existing = $chk->fetchColumn();

        if ($existing) {
            $pdo->prepare("
                UPDATE employees SET
                    first_name=?, middle_name=?, last_name=?,
                    date_of_birth=?, gender=?, phone=?, email=?,
                    address=?, department_id=?, position_id=?,
                    date_hired=?, employment_type='Full-time',
                    employment_status='Active'
                WHERE id=?
            ")->execute([
                $e['first_name'], $e['middle_name'], $e['last_name'],
                $e['dob'],        $e['gender'],       $e['phone'],
                $e['email'],      $e['address'],      $e['dept'],
                $e['pos'],        $e['hired'],         $existing,
            ]);
            $newIds[$e['username']] = (int)$existing;
            echo "Updated employee: {$e['employee_id']}\n";
        } else {
            $pdo->prepare("
                INSERT INTO employees (
                    employee_id, first_name, middle_name, last_name,
                    date_of_birth, gender, phone, email, address,
                    department_id, position_id, date_hired,
                    employment_type, employment_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Full-time', 'Active')
            ")->execute([
                $e['employee_id'], $e['first_name'], $e['middle_name'],
                $e['last_name'],   $e['dob'],        $e['gender'],
                $e['phone'],       $e['email'],      $e['address'],
                $e['dept'],        $e['pos'],         $e['hired'],
            ]);
            $newIds[$e['username']] = (int)$pdo->lastInsertId();
            echo "Inserted employee: {$e['employee_id']} — id={$newIds[$e['username']]}\n";
        }

        $allEmployeeIds[] = $newIds[$e['username']];
    }

    $empMap = [
        1 => ['email' => 'brandon@example.com', 'salary' => 28000, 'allowance' => 2000, 'username' => 'brandon'],
        2 => ['email' => 'kaiser@example.com',  'salary' => 30000, 'allowance' => 2000, 'username' => 'kaiser'],
        3 => ['email' => 'donald@example.com',  'salary' => 32000, 'allowance' => 2500, 'username' => 'donald'],
        $newIds['maria'] => ['email' => 'maria@example.com', 'salary' => 35000, 'allowance' => 3000, 'username' => 'maria'],
        $newIds['jose']  => ['email' => 'jose@example.com',  'salary' => 40000, 'allowance' => 3500, 'username' => 'jose'],
    ];

    // =========================================================
    // PART 2: USER ACCOUNTS FOR NEW EMPLOYEES
    // =========================================================

    foreach ($newEmployees as $e) {
        $eid  = $newIds[$e['username']];
        $hash = password_hash($e['password'], PASSWORD_DEFAULT);

        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$e['username']]);
        $existing = $chk->fetch();

        if ($existing) {
            $pdo->prepare("
                UPDATE users SET employee_id=?, password=?, role='employee', status='Active'
                WHERE id=?
            ")->execute([$eid, $hash, $existing['id']]);
            echo "Updated user: {$e['username']}\n";
        } else {
            $pdo->prepare("
                INSERT INTO users (employee_id, username, password, role, status)
                VALUES (?, ?, ?, 'employee', 'Active')
            ")->execute([$eid, $e['username'], $hash]);
            echo "Created user: {$e['username']}\n";
        }
    }

    // Also fix existing employee users to ensure correct employee_id
    $existingFixes = [
        ['username' => 'brandon', 'employee_id' => 1],
        ['username' => 'kaiser',  'employee_id' => 2],
        ['username' => 'donald',  'employee_id' => 3],
    ];
    foreach ($existingFixes as $fix) {
        $pdo->prepare("UPDATE users SET employee_id=? WHERE username=?")
            ->execute([$fix['employee_id'], $fix['username']]);
    }
    echo "Fixed employee_id for brandon, kaiser, donald.\n";

    // =========================================================
    // PART 3: LEAVE BALANCES FOR ALL 5 EMPLOYEES
    // =========================================================

    $leaveTypes = [1 => 12, 2 => 6, 3 => 3];

    foreach ($empMap as $eid => $emp) {
        $chk = $pdo->prepare("SELECT id FROM `leave` WHERE email = ? LIMIT 1");
        $chk->execute([$emp['email']]);
        if ($chk->fetch()) {
            $pdo->prepare("
                UPDATE `leave` SET annual_leave=12, sick_leave=6, personal_leave=3 WHERE email=?
            ")->execute([$emp['email']]);
        } else {
            $pdo->prepare("
                INSERT INTO `leave` (email, annual_leave, sick_leave, personal_leave)
                VALUES (?, 12, 6, 3)
            ")->execute([$emp['email']]);
        }

        foreach ($leaveTypes as $typeId => $days) {
            $pdo->prepare("
                INSERT INTO leave_balances (
                    employee_id, leave_type_id, year,
                    allocated_days, used_days, remaining_days
                ) VALUES (?, ?, 2026, ?, 0, ?)
                ON DUPLICATE KEY UPDATE
                    allocated_days = VALUES(allocated_days),
                    remaining_days = VALUES(allocated_days) - used_days
            ")->execute([$eid, $typeId, $days, $days]);
        }
    }

    echo "Part 3 done: Leave balances set for all 5 employees.\n";

    // =========================================================
    // PART 4: ATTENDANCE — AUGUST TO OCTOBER 2026
    // Varied late minutes to demonstrate Binary Search brackets:
    //   0–30 min late  = 0.0 deduction days
    //   31–60 min late = 0.5 deduction days
    //   61+  min late  = 1.0 deduction day
    // =========================================================

    $latePatterns = [
        1               => [0, 0, 10, 0, 35, 0, 0, 65, 0, 0],
        2               => [0, 15, 0, 0, 0, 45, 0, 0, 70, 0],
        3               => [5, 0, 0, 20, 0, 0, 55, 0, 0, 80],
        $newIds['maria'] => [0, 0, 0, 10, 0, 30, 0, 0, 0, 0],
        $newIds['jose']  => [0, 25, 0, 0, 60, 0, 0, 90, 0, 0],
    ];

    $attCount = 0;

    foreach ($empMap as $eid => $emp) {
        $start = new DateTime('2026-08-01');
        $end   = new DateTime('2026-11-01');
        $dayNo = 0;

        for ($d = clone $start; $d < $end; $d->modify('+1 day')) {
            if ((int)$d->format('N') > 5) continue;

            $pattern     = $latePatterns[$eid] ?? [0];
            $lateMinutes = $pattern[$dayNo % count($pattern)];
            $dayNo++;

            $chk = $pdo->prepare("
                SELECT id FROM attendance
                WHERE employee_id = ? AND DATE(time_in) = ?
                LIMIT 1
            ");
            $chk->execute([$eid, $d->format('Y-m-d')]);
            if ($chk->fetch()) continue;

            $timeIn    = clone $d;
            $timeIn->setTime(8, $lateMinutes, 0);
            $timeOut   = clone $d;
            $timeOut->setTime(17, $lateMinutes, 0);
            $breakTime = clone $d;
            $breakTime->setTime(12, 0, 0);

            $pdo->prepare("
                INSERT INTO attendance (
                    employee_id, email, created_at,
                    time_in, time_out, workhours, Break_Time
                ) VALUES (?, ?, ?, ?, ?, 8.00, ?)
            ")->execute([
                $eid,
                $emp['email'],
                $timeIn->format('Y-m-d H:i:s'),
                $timeIn->format('Y-m-d H:i:s'),
                $timeOut->format('Y-m-d H:i:s'),
                $breakTime->format('Y-m-d H:i:s'),
            ]);

            $attCount++;
        }
    }

    echo "Part 4 done: $attCount new attendance records inserted.\n";

    // =========================================================
    // PART 5: EMPLOYEE SALARIES FOR ALL 5
    // =========================================================

    foreach ($empMap as $eid => $emp) {
        $chk = $pdo->prepare("
            SELECT id FROM employee_salaries
            WHERE employee_id = ? AND status = 'Active'
            LIMIT 1
        ");
        $chk->execute([$eid]);
        $existing = $chk->fetch();

        if ($existing) {
            $pdo->prepare("
                UPDATE employee_salaries SET
                    pay_type='Monthly', basic_salary=?,
                    allowance=?, effective_date='2026-08-01'
                WHERE id=?
            ")->execute([$emp['salary'], $emp['allowance'], $existing['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO employee_salaries (
                    employee_id, pay_type, basic_salary,
                    allowance, effective_date, status
                ) VALUES (?, 'Monthly', ?, ?, '2026-08-01', 'Active')
            ")->execute([$eid, $emp['salary'], $emp['allowance']]);
        }
    }

    echo "Part 5 done: Salaries set for all 5 employees.\n";

    // =========================================================
    // PART 6: PAYROLL PERIODS — 4 PERIODS (Aug–Oct)
    // Linked List DSA: head=Aug, tail=Oct 16-31
    //   Aug 2026       (monthly)
    //   Sep 2026       (monthly)
    //   Oct 1–15 2026  (semi-monthly 1st cut-off)
    //   Oct 16–31 2026 (semi-monthly 2nd cut-off)
    // =========================================================

    $adminId = $pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn();
    if (!$adminId) throw new Exception("Admin user not found.");

    $periods = [
        [
            'name'     => 'August 2026 Payroll',
            'start'    => '2026-08-01',
            'end'      => '2026-08-31',
            'pay_date' => '2026-09-05',
        ],
        [
            'name'     => 'September 2026 Payroll',
            'start'    => '2026-09-01',
            'end'      => '2026-09-30',
            'pay_date' => '2026-10-05',
        ],
        [
            'name'     => 'October 2026 1st Cut-off',
            'start'    => '2026-10-01',
            'end'      => '2026-10-15',
            'pay_date' => '2026-10-20',
        ],
        [
            'name'     => 'October 2026 2nd Cut-off',
            'start'    => '2026-10-16',
            'end'      => '2026-10-31',
            'pay_date' => '2026-11-05',
        ],
    ];

    $periodIds   = [];
    $workDays    = [23, 22, 11, 11];

    foreach ($periods as $p) {
        $chk = $pdo->prepare("
            SELECT id FROM payroll_periods
            WHERE start_date = ? AND end_date = ?
            LIMIT 1
        ");
        $chk->execute([$p['start'], $p['end']]);
        $existing = $chk->fetchColumn();

        if ($existing) {
            $pdo->prepare("
                UPDATE payroll_periods SET
                    period_name=?, pay_date=?,
                    status='Processed', created_by=?
                WHERE id=?
            ")->execute([$p['name'], $p['pay_date'], $adminId, $existing]);
            $periodIds[] = (int)$existing;
            echo "Updated period: {$p['name']}\n";
        } else {
            $pdo->prepare("
                INSERT INTO payroll_periods (
                    period_name, start_date, end_date,
                    pay_date, status, created_by
                ) VALUES (?, ?, ?, ?, 'Processed', ?)
            ")->execute([$p['name'], $p['start'], $p['end'], $p['pay_date'], $adminId]);
            $periodIds[] = (int)$pdo->lastInsertId();
            echo "Created period: {$p['name']}\n";
        }
    }

    // =========================================================
    // PART 7: PAYSLIPS — 5 employees × 4 periods = 20 payslips
    // Binary Search DSA: late minutes → deduction bracket
    //   0–30  → 0.0 deduction days
    //   31–60 → 0.5 deduction days
    //   61+   → 1.0 deduction day
    // Quick Sort DSA: payslips sorted by last_name in HR view
    // =========================================================

    $avgLate = [
        1               => 11,
        2               => 13,
        3               => 16,
        $newIds['maria'] => 4,
        $newIds['jose']  => 17,
    ];

    foreach ($empMap as $eid => $emp) {
        $basic     = (float)$emp['salary'];
        $allowance = (float)$emp['allowance'];

        foreach ($periodIds as $idx => $periodId) {
            $wdays      = $workDays[$idx];
            $late       = $avgLate[$eid] ?? 0;
            $isHalf     = $idx >= 2;
            $basicPay   = $isHalf ? round($basic / 2, 2) : $basic;
            $periodAllow = $isHalf ? round($allowance / 2, 2) : $allowance;

            // Binary Search bracket logic
            $brackets          = [0, 30, 60, PHP_INT_MAX];
            $deductDaysBracket = [0.0, 0.5, 1.0];
            $low = 0; $high = count($brackets) - 1; $bIdx = 0;
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                if ($brackets[$mid] <= $late) {
                    $bIdx = $mid;
                    $low  = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            $lateDeductDays = $deductDaysBracket[min($bIdx, count($deductDaysBracket) - 1)];
            $dailyRate      = $basicPay / $wdays;
            $lateDeduct     = round($lateDeductDays * $dailyRate, 2);

            $grossPay        = $basicPay + $periodAllow;
            $sss             = round(($isHalf ? $basic / 2 : $basic) * 0.045, 2);
            $philhealth      = round(($isHalf ? $basic / 2 : $basic) * 0.025, 2);
            $pagibig         = $isHalf ? 50.00 : 100.00;
            $totalDeductions = $sss + $philhealth + $pagibig + $lateDeduct;
            $netPay          = round($grossPay - $totalDeductions, 2);

            $chk = $pdo->prepare("
                SELECT id FROM payslips
                WHERE payroll_period_id = ? AND employee_id = ?
                LIMIT 1
            ");
            $chk->execute([$periodId, $eid]);
            $existing = $chk->fetch();

            $params = [
                $basic, $wdays, $wdays, 0, $late, 0,
                $basicPay, $periodAllow, $grossPay,
                $sss, $philhealth, $pagibig, 0, 0,
                $lateDeduct, 0, $totalDeductions, $netPay,
                "Binary Search bracket: {$lateDeductDays} day(s) deducted for {$late} late minutes",
                'paid',
            ];

            if ($existing) {
                $pdo->prepare("
                    UPDATE payslips SET
                        pay_type='Monthly', basic_salary=?, working_days=?,
                        days_worked=?, absent_days=?, late_minutes=?, ot_hours=?,
                        basic_pay=?, allowance=?, gross_pay=?,
                        sss=?, philhealth=?, pagibig=?, withholding_tax=?,
                        absences_deduction=?, late_deduction=?, other_deductions=?,
                        total_deductions=?, net_pay=?, remarks=?, status=?
                    WHERE payroll_period_id=? AND employee_id=?
                ")->execute(array_merge($params, [$periodId, $eid]));
            } else {
                $pdo->prepare("
                    INSERT INTO payslips (
                        payroll_period_id, employee_id, pay_type,
                        basic_salary, working_days, days_worked, absent_days,
                        late_minutes, ot_hours, basic_pay, allowance, gross_pay,
                        sss, philhealth, pagibig, withholding_tax,
                        absences_deduction, late_deduction, other_deductions,
                        total_deductions, net_pay, remarks, status
                    ) VALUES (?, ?, 'Monthly', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute(array_merge([$periodId, $eid], $params));
            }
        }
    }

    echo "Part 7 done: 20 payslips generated.\n";

    // =========================================================
    // PART 8: LEAVE REQUESTS + STATUS LOGS
    // All dates within Aug–Oct 2026 only
    // Stack DSA:  multi-step history per request
    // Queue DSA:  3 pending requests FIFO (oldest first)
    // =========================================================

    $hrUserId = $pdo->query("SELECT id FROM users WHERE username = 'hr' LIMIT 1")->fetchColumn();

    $leaveRequests = [
        // ── Approved (Stack: (none)→Pending → Pending→Approved) ──
        [
            'employee_id'   => 1,
            'leave_type_id' => 2,
            'start_date'    => '2026-09-10',
            'end_date'      => '2026-09-11',
            'days'          => 2,
            'reason'        => 'Fever and flu symptoms',
            'status'        => 'Approved',
            'created_at'    => '2026-09-08 09:00:00',
            'logs'          => [
                ['old' => null,      'new' => 'Pending',  'at' => '2026-09-08 09:00:00'],
                ['old' => 'Pending', 'new' => 'Approved', 'at' => '2026-09-09 10:30:00'],
            ],
        ],
        [
            'employee_id'   => 2,
            'leave_type_id' => 1,
            'start_date'    => '2026-09-22',
            'end_date'      => '2026-09-26',
            'days'          => 5,
            'reason'        => 'Family vacation',
            'status'        => 'Approved',
            'created_at'    => '2026-09-15 08:00:00',
            'logs'          => [
                ['old' => null,      'new' => 'Pending',  'at' => '2026-09-15 08:00:00'],
                ['old' => 'Pending', 'new' => 'Approved', 'at' => '2026-09-16 14:00:00'],
            ],
        ],
        // ── Rejected (Stack: (none)→Pending → Pending→Rejected) ──
        [
            'employee_id'   => 3,
            'leave_type_id' => 3,
            'start_date'    => '2026-10-05',
            'end_date'      => '2026-10-05',
            'days'          => 1,
            'reason'        => 'Personal errand',
            'status'        => 'Rejected',
            'created_at'    => '2026-10-01 10:00:00',
            'logs'          => [
                ['old' => null,      'new' => 'Pending',  'at' => '2026-10-01 10:00:00'],
                ['old' => 'Pending', 'new' => 'Rejected', 'at' => '2026-10-02 09:00:00'],
            ],
        ],
        // ── Re-filed after reject → Approved ──
        [
            'employee_id'   => 3,
            'leave_type_id' => 3,
            'start_date'    => '2026-10-12',
            'end_date'      => '2026-10-12',
            'days'          => 1,
            'reason'        => 'Personal errand rescheduled',
            'status'        => 'Approved',
            'created_at'    => '2026-10-06 08:00:00',
            'logs'          => [
                ['old' => null,      'new' => 'Pending',  'at' => '2026-10-06 08:00:00'],
                ['old' => 'Pending', 'new' => 'Approved', 'at' => '2026-10-07 11:00:00'],
            ],
        ],
        // ── 3 PENDING in FIFO order (Queue DSA) ──────────────────
        // Maria filed first → Jose second → Brandon third
        [
            'employee_id'   => $newIds['maria'],
            'leave_type_id' => 1,
            'start_date'    => '2026-10-19',
            'end_date'      => '2026-10-23',
            'days'          => 5,
            'reason'        => 'Annual vacation leave',
            'status'        => 'Pending',
            'created_at'    => '2026-10-14 08:00:00',
            'logs'          => [
                ['old' => null, 'new' => 'Pending', 'at' => '2026-10-14 08:00:00'],
            ],
        ],
        [
            'employee_id'   => $newIds['jose'],
            'leave_type_id' => 2,
            'start_date'    => '2026-10-26',
            'end_date'      => '2026-10-27',
            'days'          => 2,
            'reason'        => 'Medical check-up',
            'status'        => 'Pending',
            'created_at'    => '2026-10-15 09:30:00',
            'logs'          => [
                ['old' => null, 'new' => 'Pending', 'at' => '2026-10-15 09:30:00'],
            ],
        ],
        [
            'employee_id'   => 1,
            'leave_type_id' => 3,
            'start_date'    => '2026-10-28',
            'end_date'      => '2026-10-28',
            'days'          => 1,
            'reason'        => 'Personal appointment',
            'status'        => 'Pending',
            'created_at'    => '2026-10-16 10:00:00',
            'logs'          => [
                ['old' => null, 'new' => 'Pending', 'at' => '2026-10-16 10:00:00'],
            ],
        ],
    ];

    foreach ($leaveRequests as $req) {
        $chk = $pdo->prepare("
            SELECT id FROM leave_requests
            WHERE employee_id = ? AND start_date = ?
            LIMIT 1
        ");
        $chk->execute([$req['employee_id'], $req['start_date']]);
        $existingId = $chk->fetchColumn();

        if ($existingId) {
            $reqId = (int)$existingId;
            $pdo->prepare("UPDATE leave_requests SET status=? WHERE id=?")
                ->execute([$req['status'], $reqId]);
        } else {
            $pdo->prepare("
                INSERT INTO leave_requests (
                    employee_id, leave_type_id, start_date, end_date,
                    number_of_days, reason, status, approved_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $req['employee_id'],
                $req['leave_type_id'],
                $req['start_date'],
                $req['end_date'],
                $req['days'],
                $req['reason'],
                $req['status'],
                in_array($req['status'], ['Approved', 'Rejected']) ? $hrUserId : null,
                $req['created_at'],
            ]);
            $reqId = (int)$pdo->lastInsertId();
        }

        // Clear and re-insert logs (Stack entries)
        $pdo->prepare("DELETE FROM leave_request_logs WHERE leave_request_id=?")->execute([$reqId]);

        foreach ($req['logs'] as $log) {
            $pdo->prepare("
                INSERT INTO leave_request_logs (
                    leave_request_id, old_status, new_status,
                    changed_by, changed_at
                ) VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $reqId,
                $log['old'],
                $log['new'],
                $hrUserId,
                $log['at'],
            ]);
        }
    }

    echo "Part 8 done: " . count($leaveRequests) . " leave requests + logs inserted.\n";

    // =========================================================
    // FINALIZE
    // =========================================================

    $pdo->commit();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n════════════════════════════════════════\n";
    echo "DEMO DATA SEEDED SUCCESSFULLY!\n";
    echo "════════════════════════════════════════\n";

    echo "\nEMPLOYEES (5 total across 3 departments):\n";
    echo "  IT Dept  : Brandon (EMP-001), Kaiser (EMP-002)\n";
    echo "  Finance  : Donald  (EMP-003)\n";
    echo "  HR Dept  : Maria   (EMP-004), Jose   (EMP-005)\n";

    echo "\nLOGIN ACCOUNTS:\n";
    echo "  admin   / admin123\n";
    echo "  hr      / hr123\n";
    echo "  brandon / brandon123\n";
    echo "  kaiser  / kaiser123\n";
    echo "  donald  / donald123\n";
    echo "  maria   / maria123\n";
    echo "  jose    / jose123\n";

    echo "\nDSA DEMONSTRATED:\n";
    echo "  Linear Search  — search employees by name/email/ID\n";
    echo "  Bubble Sort    — sort employee list by any column\n";
    echo "  Merge Sort     — sort departments by headcount\n";
    echo "  Quick Sort     — sort payslips by employee last name\n";
    echo "  Binary Search  — late minute bracket in payslip remarks\n";
    echo "  Stack          — leave status history (4 requests with logs)\n";
    echo "  Queue          — 3 pending requests FIFO: Maria→Jose→Brandon\n";
    echo "  Linked List    — 4 payroll periods with prev/next links\n";
    echo "  BST            — employee lookup by EMP-001 format\n";
    echo "  Hash Table     — department/position O(1) cache\n";

    echo "\nSAMPLE DATA RANGE: August – October 2026 only\n";
    echo "  Attendance : Aug 1 – Oct 31 (varied late minutes)\n";
    echo "  Payslips   : 20 total (5 employees × 4 periods)\n";
    echo "  Payroll    : Aug | Sep | Oct 1-15 | Oct 16-31\n";
    echo "  Leave logs : 7 requests, 11 status log entries\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "\nSEEDING FAILED!\n";
    echo $e->getMessage() . "\n";
    exit(1);
}