<?php
declare(strict_types=1);
// php database/seeders/DatabaseSeeder.php
/**
 * HRIS DEMO DATA SEEDER
 * Development use only.
 *
 * Usage:
 *   php database/seeders/DatabaseSeeder.php
 *
 * Requirements:
 *   - config/database.php must create a PDO instance named $pdo.
 *   - Run against a DEVELOPMENT database, never production.
 *
 * This seeder is additive and repeatable. It does not truncate application
 * tables. A small registry table prevents duplicate generated demo records.
 */

require_once dirname(__DIR__, 2) . '/config/database.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    throw new RuntimeException(
        'config/database.php must expose a PDO instance in the variable $pdo.'
    );
}

$seeder = new HrisDemoSeeder($pdo);
$seeder->run();

final class HrisDemoSeeder
{
    private PDO $db;
    private array $departmentIds = [];
    private array $positionIds = [];
    private array $roleIds = [];
    private array $moduleIds = [];
    private array $permissionIds = [];
    private array $employeeIds = [];
    private array $userIds = [];
    private array $employees = [];
    private array $leaveTypeIds = [];
    private array $benefitPlanIds = [];
    private array $periodIds = [];

    private const START_DATE = '2026-09-01';
    private const END_DATE = '2026-11-30';
    private const DEMO_PASSWORD = 'ChangeMe123!';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function run(): void
    {
        $this->assertDevelopmentDatabase();
        $this->createRegistry();

        $this->db->beginTransaction();

        try {
            $this->seedFoundation();
            $this->seedEmployeesAndUsers();
            $this->seedLeaveBalances();
            $this->seedAttendance();
            $this->seedLeaveRequests();
            $this->seedOvertimeRequests();
            $this->seedPayroll();
            $this->seedPromotions();
            $this->seedBenefits();
            $this->seedSeparation();
            $this->seedAuditLogs();

            $this->db->commit();

            echo PHP_EOL . "HRIS demo seeding completed." . PHP_EOL;
            echo "Timeline: " . self::START_DATE . " to " . self::END_DATE . PHP_EOL;
            echo "Demo password for generated accounts: " . self::DEMO_PASSWORD . PHP_EOL;
            echo "Change all demo passwords before exposing this system to a network." . PHP_EOL;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function assertDevelopmentDatabase(): void
    {
        $dbName = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();

        if ($dbName === '' || preg_match('/prod|production|live/i', $dbName)) {
            throw new RuntimeException(
                "Refusing to seed database '{$dbName}'. Use a clearly named development database."
            );
        }
    }

    private function createRegistry(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS hris_demo_seed_registry (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                seed_key VARCHAR(190) NOT NULL,
                record_id BIGINT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_demo_seed_record (table_name, seed_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function insertOnce(string $table, string $key, array $data): int
    {
        $check = $this->db->prepare(
            'SELECT record_id FROM hris_demo_seed_registry WHERE table_name = ? AND seed_key = ?'
        );
        $check->execute([$table, $key]);
        $existing = $check->fetchColumn();

        if ($existing !== false) {
            return (int) $existing;
        }

        $columns = array_keys($data);
        $quoted = array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $columns);
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES ('
             . implode(',', array_fill(0, count($columns), '?')) . ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        $id = (int) $this->db->lastInsertId();

        $registry = $this->db->prepare(
            'INSERT INTO hris_demo_seed_registry (table_name, seed_key, record_id) VALUES (?, ?, ?)'
        );
        $registry->execute([$table, $key, $id]);

        return $id;
    }

    private function insertOrGet(string $table, string $uniqueColumn, string $uniqueValue, array $data): int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM `{$table}` WHERE `{$uniqueColumn}` = ? LIMIT 1"
        );
        $stmt->execute([$uniqueValue]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $columns = array_keys($data);
        $quoted = array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $columns);
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES ('
             . implode(',', array_fill(0, count($columns), '?')) . ')';
        $insert = $this->db->prepare($sql);
        $insert->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    private function seedFoundation(): void
    {
        $departments = [
            ['HRD', 'Human Resources', 'Recruitment, employee relations and leave administration'],
            ['IT', 'Information Technology', 'Software development, systems and technical support'],
            ['FIN', 'Finance', 'Accounting, payroll and budgeting'],
        ];

        foreach ($departments as [$code, $name, $description]) {
            $this->departmentIds[$code] = $this->insertOrGet('departments', 'code', $code, [
                'code' => $code, 'name' => $name, 'description' => $description, 'status' => 'Active'
            ]);
        }

        $positions = [
            ['HRO', 'HR Officer', 'Handles employee records and HR operations'],
            ['DEV', 'Software Developer', 'Builds and maintains internal applications'],
            ['ACC', 'Accountant', 'Handles accounting and payroll'],
            ['ITS', 'IT Support Specialist', 'Provides technical support'],
            ['SYS', 'System Administrator', 'Manages infrastructure and security'],
        ];

        foreach ($positions as [$code, $title, $description]) {
            $this->positionIds[$code] = $this->insertOrGet('positions', 'code', $code, [
                'code' => $code, 'title' => $title, 'description' => $description, 'status' => 'Active'
            ]);
        }

        foreach ([
            ['admin', 'Administrator', 1],
            ['hr', 'HR Staff', 1],
            ['employee', 'Employee', 0],
        ] as [$key, $name, $protected]) {
            $this->roleIds[$key] = $this->insertOrGet('roles', 'role_key', $key, [
                'role_key' => $key, 'role_name' => $name, 'is_protected' => $protected
            ]);
        }

        $modules = [
            'dashboard' => 'Dashboard',
            'employee_records' => 'Employee Records',
            'employee_onboarding' => 'Employee Onboarding',
            'recruitment' => 'Recruitment',
            'attendance' => 'Attendance',
            'leave_requests' => 'Leave Requests',
            'payroll' => 'Payroll',
            'payslips' => 'Payslips',
            'promotion_management' => 'Promotion Management',
            'user_management' => 'User Management',
            'audit_logs' => 'Audit Logs',
            'reports' => 'Reports',
            'system_settings' => 'System Settings',
            'backup_management' => 'Backup Management',
        ];

        $sort = 1;
        foreach ($modules as $key => $name) {
            $this->moduleIds[$key] = $this->insertOrGet('modules', 'module_key', $key, [
                'module_key' => $key, 'module_name' => $name, 'is_active' => 1, 'sort_order' => $sort++
            ]);
        }

        foreach (['view', 'create', 'edit', 'delete', 'approve', 'submit'] as $key) {
            $this->permissionIds[$key] = $this->insertOrGet('permissions', 'permission_key', $key, [
                'permission_key' => $key, 'permission_name' => ucfirst($key)
            ]);
        }

        $this->seedRolePermissions();

        $leaveTypes = [
            ['Annual Leave', 'Vacation and personal recreation', 12],
            ['Sick Leave', 'Illness and medical recovery', 6],
            ['Personal Leave', 'Urgent personal matters', 3],
            ['Maternity Leave', 'Childbirth and postpartum recovery', 105],
            ['Paternity Leave', 'Leave following child delivery', 7],
            ['Bereavement Leave', 'Death of an immediate family member', 3],
            ['Emergency Leave', 'Unforeseen emergencies', 3],
        ];

        foreach ($leaveTypes as [$name, $description, $days]) {
            $this->leaveTypeIds[$name] = $this->insertOrGet('leave_types', 'leave_name', $name, [
                'leave_name' => $name, 'description' => $description,
                'default_days' => $days, 'status' => 'Active'
            ]);
        }

        $rates = [
            ['SSS', 'Percentage', 4.5, 'Basic'],
            ['PhilHealth', 'Percentage', 2.5, 'Basic'],
            ['Pag-IBIG', 'Fixed', 100, 'Basic'],
            ['Withholding Tax', 'Percentage', 0, 'Gross'],
        ];
        foreach ($rates as [$name, $type, $rate, $applies]) {
            $stmt = $this->db->prepare("
                INSERT INTO deduction_rates (name, type, rate, applies_to)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name)
            ");
            $stmt->execute([$name, $type, $rate, $applies]);
        }

        $benefits = [
            ['Rice Subsidy (De Minimis)', 'Allowance', 2000, 0, 0],
            ['Transportation Allowance', 'Allowance', 1500, 0, 0],
            ['Meal Allowance', 'Allowance', 1500, 0, 0],
            ['HMO Medical Coverage', 'Health', 2500, 0, 6],
            ['Senior / Loyalty Gratuity Top-Up', 'Retirement', 5000, 0, 60],
        ];

        foreach ($benefits as [$name, $category, $amount, $taxable, $tenure]) {
            $this->benefitPlanIds[$name] = $this->insertOrGet('benefit_plans', 'name', $name, [
                'name' => $name, 'category' => $category, 'default_amount' => $amount,
                'is_taxable' => $taxable, 'min_tenure_months' => $tenure, 'is_active' => 1
            ]);
        }

        if ($this->tableExists('backup_settings')) {
            $this->db->exec("
                INSERT INTO backup_settings
                    (id, enabled, frequency, backup_minute, backup_time, backup_day, retention_days, timezone)
                VALUES (1, 1, 'daily', 0, '02:00:00', 1, 30, 'Asia/Manila')
                ON DUPLICATE KEY UPDATE id = id
            ");
        }
    }

    private function seedRolePermissions(): void
    {
        $allModules = array_keys($this->moduleIds);
        $allPermissions = array_keys($this->permissionIds);

        foreach ($allModules as $module) {
            foreach ($allPermissions as $permission) {
                $this->upsertRolePermission('admin', $module, $permission, 'all');
            }
        }

        $hrRules = [
            'dashboard' => ['view'],
            'employee_records' => ['view', 'create', 'edit', 'delete'],
            'employee_onboarding' => ['view', 'create', 'edit', 'delete', 'approve'],
            'recruitment' => ['view', 'create', 'edit', 'delete', 'approve'],
            'attendance' => ['view', 'create', 'edit'],
            'leave_requests' => ['view', 'create', 'edit', 'approve'],
            'payroll' => ['view', 'create', 'edit', 'approve'],
            'payslips' => ['view', 'create', 'edit'],
            'promotion_management' => ['view', 'create', 'edit', 'approve'],
            'user_management' => ['view', 'create', 'edit'],
            'audit_logs' => ['view'],
            'reports' => ['view'],
            'system_settings' => ['view'],
        ];

        foreach ($hrRules as $module => $permissions) {
            foreach ($permissions as $permission) {
                $this->upsertRolePermission('hr', $module, $permission, 'all');
            }
        }

        $employeeRules = [
            'dashboard' => ['view'], 'employee_records' => ['view'],
            'attendance' => ['view', 'submit'], 'leave_requests' => ['view', 'submit'],
            'payslips' => ['view'], 'promotion_management' => ['view', 'submit'],
        ];

        foreach ($employeeRules as $module => $permissions) {
            foreach ($permissions as $permission) {
                $this->upsertRolePermission('employee', $module, $permission, 'own');
            }
        }

        $rankRules = [
            'manager' => [
                'dashboard' => ['view'], 'employee_records' => ['view', 'create', 'edit'],
                'attendance' => ['view', 'edit'], 'leave_requests' => ['view', 'approve'],
                'promotion_management' => ['view', 'approve'], 'reports' => ['view'],
            ],
            'team_leader' => [
                'dashboard' => ['view'], 'employee_records' => ['view'],
                'attendance' => ['view', 'edit'], 'leave_requests' => ['view', 'approve'],
                'promotion_management' => ['view'], 'reports' => ['view'],
            ],
        ];

        foreach ($rankRules as $rank => $rules) {
            foreach ($rules as $module => $permissions) {
                foreach ($permissions as $permission) {
                    $stmt = $this->db->prepare("
                        INSERT INTO rank_permissions
                            (rank_key, module_id, permission_id, effect, data_scope)
                        VALUES (?, ?, ?, 'allow', ?)
                        ON DUPLICATE KEY UPDATE effect = VALUES(effect), data_scope = VALUES(data_scope)
                    ");
                    $stmt->execute([
                        $rank, $this->moduleIds[$module], $this->permissionIds[$permission],
                        $rank === 'manager' ? 'department' : 'team'
                    ]);
                }
            }
        }
    }

    private function upsertRolePermission(string $role, string $module, string $permission, string $scope): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO role_permissions (role_id, module_id, permission_id, data_scope)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE data_scope = VALUES(data_scope)
        ");
        $stmt->execute([
            $this->roleIds[$role], $this->moduleIds[$module],
            $this->permissionIds[$permission], $scope
        ]);
    }

    private function seedEmployeesAndUsers(): void
    {
        // Managers are inserted first so subordinate manager_id values are valid.
        $people = [
            ['ST-1001','Adrian','M.','Reyes','HRD','HRO','manager',null, '2024-03-11', 'Active', 52000],
            ['ST-1002','Bianca','L.','Santos','IT','SYS','manager',null, '2023-08-21', 'Active', 68000],
            ['ST-1003','Carlos','D.','Mendoza','FIN','ACC','manager',null, '2022-06-06', 'Active', 61000],
            ['ST-1004','Diana','P.','Cruz','HRD','HRO','team_leader','ST-1001','2025-01-13','Active',39000],
            ['ST-1005','Ethan','R.','Garcia','IT','DEV','team_leader','ST-1002','2025-02-03','Active',48000],
            ['ST-1006','Faith','A.','Torres','FIN','ACC','team_leader','ST-1003','2025-04-14','Active',42000],
            ['ST-1007','Gabriel',null,'Flores','IT','DEV','employee','ST-1005','2026-09-08','Active',34000],
            ['ST-1008','Hannah','C.','Villanueva','IT','DEV','employee','ST-1005','2026-09-15','Active',36000],
            ['ST-1009','Isaac',null,'Ramos','IT','ITS','employee','ST-1002','2026-09-22','Active',29000],
            ['ST-1010','Julia','M.','Aquino','HRD','HRO','employee','ST-1004','2026-10-01','Active',31000],
            ['ST-1011','Kevin','T.','Castillo','FIN','ACC','employee','ST-1006','2026-10-06','Active',32000],
            ['ST-1012','Lara',null,'Navarro','IT','DEV','employee','ST-1005','2026-10-13','Active',35000],
            ['ST-1013','Miguel','S.','Bautista','IT','ITS','employee','ST-1002','2026-11-03','Active',30000],
            ['ST-1014','Nina','R.','Dela Cruz','HRD','HRO','employee','ST-1004','2026-11-10','Active',31500],
            ['ST-1015','Oliver',null,'Perez','FIN','ACC','employee','ST-1006','2026-09-29','Inactive',33000],
        ];

        foreach ($people as $p) {
            [$code,$first,$middle,$last,$dept,$pos,$rank,$managerCode,$hired,$status,$salary] = $p;
            $existing = $this->db->prepare('SELECT id FROM employees WHERE employee_id = ?');
            $existing->execute([$code]);
            $id = $existing->fetchColumn();

            if ($id === false) {
                $insert = $this->db->prepare("
                    INSERT INTO employees
                    (employee_id, first_name, middle_name, last_name, date_of_birth, gender,
                     phone, email, address, department_id, position_id, `rank`, manager_id,
                     date_hired, employment_type, employment_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 'Full-time', ?)
                ");
                $insert->execute([
                    $code, $first, $middle, $last,
                    '199' . (int)substr($code, -1) . '-04-15',
                    'Prefer not to say',
                    '09' . str_pad((string)(10000000 + (int)substr($code, -4)), 9, '0', STR_PAD_LEFT),
                    strtolower($first . '.' . $last) . '@stuart-demo.test',
                    'Cebu City, Philippines',
                    $this->departmentIds[$dept], $this->positionIds[$pos], $rank, $hired, $status
                ]);
                $id = (int)$this->db->lastInsertId();
            } else {
                $id = (int)$id;
            }

            $this->employeeIds[$code] = $id;
            $this->employees[$code] = ['id' => $id, 'rank' => $rank, 'salary' => $salary, 'status' => $status, 'hired' => $hired];
        }

        foreach ($people as $p) {
            [$code,,,,,,,$managerCode] = $p;
            if ($managerCode !== null) {
                $update = $this->db->prepare('UPDATE employees SET manager_id = ? WHERE id = ?');
                $update->execute([$this->employeeIds[$managerCode], $this->employeeIds[$code]]);
            }
        }

        // Admin is a system account; the other accounts are tied to employee records.
        $accounts = [
            ['admin', null, 'admin'],
            ['adrian.reyes', 'ST-1001', 'hr'],
            ['bianca.santos', 'ST-1002', 'employee'],
            ['carlos.mendoza', 'ST-1003', 'employee'],
        ];

        foreach (array_slice($people, 3) as $p) {
            $accounts[] = [strtolower($p[1] . '.' . $p[3]), $p[0], 'employee'];
        }

        $password = password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT);

        foreach ($accounts as [$username, $employeeCode, $role]) {
            $check = $this->db->prepare('SELECT id FROM users WHERE username = ?');
            $check->execute([$username]);
            $id = $check->fetchColumn();

            if ($id === false) {
                $insert = $this->db->prepare("
                    INSERT INTO users (employee_id, username, password, role, status, setup_completed)
                    VALUES (?, ?, ?, ?, 'Active', 1)
                ");
                $insert->execute([
                    $employeeCode === null ? null : $this->employeeIds[$employeeCode],
                    $username, $password, $role
                ]);
                $id = (int)$this->db->lastInsertId();
            } else {
                $id = (int)$id;
            }

            $this->userIds[$username] = $id;
        }
    }

    private function seedLeaveBalances(): void
    {
        foreach ($this->employees as $code => $employee) {
            foreach ($this->leaveTypeIds as $leaveName => $leaveTypeId) {
                $allocated = match ($leaveName) {
                    'Annual Leave' => 12,
                    'Sick Leave' => 6,
                    'Personal Leave' => 3,
                    'Maternity Leave' => 105,
                    'Paternity Leave' => 7,
                    default => 3,
                };

                $this->insertOnce('leave_balances', "balance-{$code}-{$leaveTypeId}-2026", [
                    'employee_id' => $employee['id'], 'leave_type_id' => $leaveTypeId,
                    'year' => 2026, 'allocated_days' => $allocated,
                    'used_days' => 0, 'remaining_days' => $allocated
                ]);
            }
        }
    }

    private function seedAttendance(): void
    {
        $start = new DateTimeImmutable(self::START_DATE);
        $end = new DateTimeImmutable(self::END_DATE);
        $today = new DateTimeImmutable('2026-11-27');

        for ($day = $start; $day <= $end && $day <= $today; $day = $day->modify('+1 day')) {
            if ((int)$day->format('N') >= 6) {
                continue;
            }

            foreach ($this->employees as $code => $employee) {
                if ($employee['status'] !== 'Active' || $employee['hired'] > $day->format('Y-m-d')) {
                    continue;
                }

                $n = (int)substr($code, -1);
                $date = $day->format('Y-m-d');
                $key = "attendance-{$code}-{$date}";

                // A few planned absences and late arrivals make the dashboard realistic.
                $absent = (($n * 7 + (int)$day->format('j')) % 47 === 0);
                $late = !$absent && (($n + (int)$day->format('j')) % 13 === 0);
                $timeIn = $absent ? null : ($late ? '08:18:00' : '07:55:00');
                $timeOut = $absent ? null : '17:05:00';
                $regular = $absent ? 0 : ($late ? 8.70 : 8.00);
                $status = $absent ? 'Absent' : ($late ? 'Late' : 'Present');

                $this->insertOnce('attendance', $key, [
                    'employee_id' => $employee['id'],
                    'email' => strtolower(str_replace('-', '.', $code)) . '@stuart-demo.test',
                    'time_in' => $timeIn ? $date . ' ' . $timeIn : null,
                    'time_out' => $timeOut ? $date . ' ' . $timeOut : null,
                    'break_time' => $absent ? null : $date . ' 12:00:00',
                    'workhours' => $regular,
                    'shift_date' => $date,
                    'status' => $status,
                    'regular_hours' => $regular,
                    'overtime_minutes' => $absent ? 0 : (($n + (int)$day->format('j')) % 19 === 0 ? 60 : 0),
                    'overtime_hours' => $absent ? 0 : (($n + (int)$day->format('j')) % 19 === 0 ? 1 : 0),
                    'overtime_status' => 'Approved',
                ]);
            }
        }
    }

    private function seedLeaveRequests(): void
    {
        $requests = [
            ['ST-1007','Annual Leave','2026-09-21','2026-09-22',2,'Family event','Approved','ST-1005'],
            ['ST-1008','Sick Leave','2026-09-28','2026-09-29',2,'Flu symptoms','Approved','ST-1005'],
            ['ST-1009','Annual Leave','2026-10-05','2026-10-07',3,'Out-of-town trip','Rejected','ST-1002'],
            ['ST-1010','Personal Leave','2026-10-09','2026-10-09',1,'Urgent personal matter','Approved','ST-1004'],
            ['ST-1011','Sick Leave','2026-10-19','2026-10-20',2,'Medical recovery','Approved','ST-1006'],
            ['ST-1012','Annual Leave','2026-10-26','2026-10-28',3,'Vacation','Rejected','ST-1005'],
            ['ST-1007','Personal Leave','2026-11-04','2026-11-04',1,'Family appointment','Approved','ST-1005'],
            ['ST-1008','Annual Leave','2026-11-12','2026-11-13',2,'Personal travel','Pending',null],
            ['ST-1009','Sick Leave','2026-11-16','2026-11-16',1,'Not feeling well','Approved','ST-1002'],
            ['ST-1010','Annual Leave','2026-11-23','2026-11-25',3,'Planned vacation','Pending',null],
            ['ST-1011','Personal Leave','2026-11-18','2026-11-18',1,'Personal errand','Rejected','ST-1006'],
            ['ST-1012','Sick Leave','2026-11-20','2026-11-20',1,'Fever','Approved','ST-1005'],
            ['ST-1013','Annual Leave','2026-11-26','2026-11-27',2,'Family occasion','Pending',null],
            ['ST-1014','Emergency Leave','2026-11-09','2026-11-10',2,'Household emergency','Approved','ST-1004'],
            ['ST-1007','Annual Leave','2026-10-30','2026-10-30',1,'Long weekend','Rejected','ST-1005'],
            ['ST-1011','Annual Leave','2026-09-14','2026-09-15',2,'Personal travel','Approved','ST-1006'],
            ['ST-1012','Personal Leave','2026-09-25','2026-09-25',1,'Important appointment','Approved','ST-1005'],
            ['ST-1013','Sick Leave','2026-11-24','2026-11-24',1,'Medical consultation','Pending',null],
            ['ST-1014','Annual Leave','2026-10-20','2026-10-21',2,'Family visit','Rejected','ST-1004'],
            ['ST-1008','Personal Leave','2026-10-02','2026-10-02',1,'Personal matter','Approved','ST-1005'],
        ];

        foreach ($requests as $i => [$employee,$leave,$start,$end,$days,$reason,$status,$approver]) {
            $approvedBy = $approver ? $this->userIdForEmployee($approver) : null;
            $id = $this->insertOnce('leave_requests', 'leave-request-' . ($i + 1), [
                'employee_id' => $this->employeeIds[$employee],
                'leave_type_id' => $this->leaveTypeIds[$leave],
                'start_date' => $start, 'end_date' => $end,
                'number_of_days' => $days, 'reason' => $reason,
                'status' => $status, 'approved_by' => $status === 'Pending' ? null : $approvedBy,
                'approved_at' => $status === 'Pending' ? null : $end . ' 16:00:00',
            ]);

            if ($this->tableExists('leave_request_logs')) {
                $this->insertOnce('leave_request_logs', 'leave-log-' . ($i + 1), [
                    'leave_request_id' => $id,
                    'employee_id' => $this->employeeIds[$employee],
                    'action' => $status === 'Pending' ? 'submitted' : strtolower($status),
                    'old_status' => null,
                    'new_status' => $status,
                    'performed_by_id' => $approvedBy,
                    'performed_by_name' => $approver ? $this->nameForEmployee($approver) : $this->nameForEmployee($employee),
                    'performed_by_email' => $approver ? $this->emailForEmployee($approver) : $this->emailForEmployee($employee),
                    'performed_by_role' => $approver ? 'manager' : 'employee',
                    'remarks' => $status === 'Rejected' ? 'Insufficient team coverage for the requested dates.' :
                        ($status === 'Approved' ? 'Reviewed and approved by the reporting manager.' : 'Awaiting manager review.'),
                    'created_at' => $start . ' 09:00:00',
                ]);
            }
        }

        // Recalculate from approved requests instead of incrementing. This keeps
        // the seeder safe to run repeatedly without subtracting leave twice.
        $this->db->exec("
            UPDATE leave_balances lb
            LEFT JOIN (
                SELECT employee_id, leave_type_id, SUM(number_of_days) AS used_total
                FROM leave_requests
                WHERE status = 'Approved' AND YEAR(start_date) = 2026
                GROUP BY employee_id, leave_type_id
            ) approved ON approved.employee_id = lb.employee_id
                      AND approved.leave_type_id = lb.leave_type_id
            SET lb.used_days = COALESCE(approved.used_total, 0),
                lb.remaining_days = GREATEST(0, lb.allocated_days - COALESCE(approved.used_total, 0))
            WHERE lb.year = 2026
        ");
    }

    private function seedOvertimeRequests(): void
    {
        $items = [
            ['ST-1007','2026-09-18','17:00:00','19:00:00',120,'Production bug fix','Approved','ST-1005'],
            ['ST-1008','2026-09-24','17:00:00','18:30:00',90,'Release testing','Approved','ST-1005'],
            ['ST-1009','2026-10-02','17:00:00','20:00:00',180,'Network maintenance','Approved','ST-1002'],
            ['ST-1012','2026-10-08','17:00:00','19:00:00',120,'Feature implementation','Rejected','ST-1005'],
            ['ST-1011','2026-10-15','17:00:00','18:00:00',60,'Month-end reconciliation','Approved','ST-1006'],
            ['ST-1007','2026-10-22','17:00:00','19:00:00',120,'System deployment','Approved','ST-1005'],
            ['ST-1008','2026-10-29','17:00:00','18:00:00',60,'Quality assurance','Pending',null],
            ['ST-1013','2026-11-05','17:00:00','19:00:00',120,'Hardware inventory','Approved','ST-1002'],
            ['ST-1014','2026-11-11','17:00:00','18:30:00',90,'Personnel file encoding','Approved','ST-1004'],
            ['ST-1012','2026-11-13','17:00:00','20:00:00',180,'Application release','Pending',null],
            ['ST-1009','2026-11-17','17:00:00','18:00:00',60,'Helpdesk backlog','Rejected','ST-1002'],
            ['ST-1011','2026-11-19','17:00:00','19:00:00',120,'Payroll validation','Approved','ST-1006'],
            ['ST-1007','2026-11-20','17:00:00','18:30:00',90,'Bug investigation','Pending',null],
            ['ST-1008','2026-11-24','17:00:00','19:00:00',120,'Sprint completion','Approved','ST-1005'],
            ['ST-1013','2026-11-25','17:00:00','18:00:00',60,'Device setup','Pending',null],
        ];

        foreach ($items as $i => [$employee,$date,$start,$end,$minutes,$reason,$status,$reviewer]) {
            $this->insertOnce('overtime_requests', 'overtime-' . ($i + 1), [
                'employee_id' => $this->employeeIds[$employee],
                'work_date' => $date, 'start_time' => $start, 'end_time' => $end,
                'requested_minutes' => $minutes,
                'approved_minutes' => $status === 'Approved' ? $minutes : 0,
                'ot_type' => 'Regular Day', 'reason' => $reason, 'status' => $status,
                'reviewed_by' => $reviewer ? $this->nameForEmployee($reviewer) : null,
                'reviewed_at' => $status === 'Pending' ? null : $date . ' 16:30:00',
            ]);
        }
    }

    private function seedPayroll(): void
    {
        $periods = [
            ['September 1-15, 2026','2026-09-01','2026-09-15','2026-09-20','Paid'],
            ['September 16-30, 2026','2026-09-16','2026-09-30','2026-10-05','Paid'],
            ['October 1-15, 2026','2026-10-01','2026-10-15','2026-10-20','Paid'],
            ['October 16-31, 2026','2026-10-16','2026-10-31','2026-11-05','Paid'],
            ['November 1-15, 2026','2026-11-01','2026-11-15','2026-11-20','Processed'],
            ['November 16-30, 2026','2026-11-16','2026-11-30','2026-12-05','Draft'],
        ];

        foreach ($periods as $i => [$name,$start,$end,$payDate,$status]) {
            $periodId = $this->insertOnce('payroll_periods', 'payroll-period-' . ($i + 1), [
                'period_name' => $name, 'start_date' => $start, 'end_date' => $end,
                'pay_date' => $payDate, 'status' => $status,
                'created_by' => $this->userIds['adrian.reyes'],
            ]);
            $this->periodIds[] = $periodId;

            foreach ($this->employees as $code => $employee) {
                if ($employee['hired'] > $end || $employee['status'] !== 'Active') {
                    continue;
                }

                $basic = (float)$employee['salary'];
                $workingDays = 10;
                $daysWorked = $employee['hired'] > $start ? 8 : 10;
                $basicPay = round(($basic / 2) * ($daysWorked / $workingDays), 2);
                $allowance = 1000;
                $otHours = ($i + (int)substr($code, -1)) % 4 === 0 ? 2 : 0;
                $otPay = round(($basic / 22 / 8) * 1.25 * $otHours, 2);
                $gross = $basicPay + $allowance + $otPay;
                $sss = round($basicPay * 0.045, 2);
                $philhealth = round($basicPay * 0.025, 2);
                $pagibig = 100;
                $tax = 0;
                $deductions = $sss + $philhealth + $pagibig + $tax;
                $net = $gross - $deductions;

                $this->insertOnce('payslips', "payslip-{$periodId}-{$code}", [
                    'payroll_period_id' => $periodId, 'employee_id' => $employee['id'],
                    'pay_type' => 'Monthly', 'basic_salary' => $basic,
                    'working_days' => $workingDays, 'days_worked' => $daysWorked,
                    'absent_days' => $workingDays - $daysWorked,
                    'late_minutes' => ($i + (int)substr($code, -1)) % 5 === 0 ? 18 : 0,
                    'ot_hours' => $otHours, 'ot_rate_multiplier' => 1.25,
                    'basic_pay' => $basicPay, 'allowance' => $allowance,
                    'overtime_pay' => $otPay, 'other_earnings' => 0,
                    'gross_pay' => $gross, 'sss' => $sss,
                    'philhealth' => $philhealth, 'pagibig' => $pagibig,
                    'withholding_tax' => $tax, 'absences_deduction' => 0,
                    'late_deduction' => 0, 'other_deductions' => 0,
                    'total_deductions' => $deductions, 'net_pay' => $net,
                    'remarks' => 'Generated demo payroll record.',
                    'status' => $status === 'Paid' ? 'Paid' : ($status === 'Processed' ? 'Finalized' : 'Draft'),
                ]);
            }
        }

        foreach ($this->employees as $code => $employee) {
            $this->insertOnce('employee_salaries', "salary-{$code}-2026", [
                'employee_id' => $employee['id'], 'pay_type' => 'Monthly',
                'basic_salary' => $employee['salary'], 'allowance' => 2000,
                'effective_date' => $employee['hired'], 'status' => 'Active'
            ]);
        }
    }

    private function seedPromotions(): void
    {
        $requests = [
            ['ST-1007','ST-1005','team_leader','Consistently leads sprint delivery and mentors new developers.','2026-11-01','approved'],
            ['ST-1011','ST-1006','team_leader','Demonstrated strong ownership of monthly financial reconciliation.','2026-11-15','pending_manager_review'],
            ['ST-1009','ST-1002','team_leader','Shows initiative in resolving recurring support incidents.','2026-11-20','rejected'],
            ['ST-1012','ST-1005','team_leader','Strong technical contribution to the product release.','2026-10-15','pending_manager_review'],
            ['ST-1014','ST-1004','team_leader','Reliable HR operations and onboarding support.','2026-11-25','pending_manager_review'],
        ];

        foreach ($requests as $i => [$employee,$recommender,$rank,$reason,$effective,$status]) {
            $requestId = $this->insertOnce('promotion_requests', 'promotion-request-' . ($i + 1), [
                'employee_id' => $this->employeeIds[$employee],
                'recommended_by' => $this->userIdForEmployee($recommender),
                'recommended_rank' => $rank, 'reason' => $reason,
                'effective_date' => $effective, 'status' => $status,
                'manager_id' => $this->userIdForEmployee($recommender),
                'manager_remarks' => $status === 'rejected' ? 'Not yet meeting the required leadership experience.' : null,
                'manager_reviewed_at' => $status === 'rejected' ? $effective . ' 15:00:00' : null,
                'hr_id' => $status === 'approved' ? $this->userIds['adrian.reyes'] : null,
                'hr_remarks' => $status === 'approved' ? 'Approved following HR review.' : null,
                'hr_reviewed_at' => $status === 'approved' ? $effective . ' 16:00:00' : null,
            ]);

            if ($status === 'approved') {
                $this->insertOnce('promotion_logs', 'promotion-log-' . ($i + 1), [
                    'employee_id' => $this->employeeIds[$employee],
                    'old_rank' => 'employee', 'new_rank' => $rank,
                    'promoted_by' => $this->userIds['adrian.reyes'],
                    'promotion_request_id' => $requestId,
                    'effective_date' => $effective,
                    'created_at' => $effective . ' 16:00:00',
                ]);
                $this->db->prepare('UPDATE employees SET `rank` = ? WHERE id = ?')
                    ->execute([$rank, $this->employeeIds[$employee]]);
            }
        }
    }

    private function seedBenefits(): void
    {
        foreach ($this->employees as $code => $employee) {
            if ($employee['status'] !== 'Active') {
                continue;
            }

            $plans = ['Rice Subsidy (De Minimis)', 'Transportation Allowance', 'Meal Allowance'];
            if ($employee['hired'] <= '2026-09-01') {
                $plans[] = 'HMO Medical Coverage';
            }

            foreach ($plans as $plan) {
                $this->insertOnce('employee_benefits', "benefit-{$code}-" . md5($plan), [
                    'employee_id' => $employee['id'],
                    'benefit_plan_id' => $this->benefitPlanIds[$plan],
                    'custom_amount' => null,
                    'effective_date' => max($employee['hired'], '2026-09-01'),
                    'end_date' => null, 'status' => 'Active',
                    'notes' => 'Assigned during demo HR benefits enrollment.'
                ]);
            }
        }
    }

    private function seedSeparation(): void
    {
        $employeeId = $this->employeeIds['ST-1015'];
        $this->insertOnce('separation_settlements', 'separation-ST-1015', [
            'employee_id' => $employeeId,
            'separation_type' => 'Resignation',
            'hire_date' => '2026-09-29',
            'last_working_day' => '2026-11-14',
            'years_of_service' => 0.13,
            'unpaid_salary' => 16500,
            'prorated_13th_month' => 2750,
            'leave_conversion_pay' => 0,
            'retirement_gratuity_pay' => 0,
            'tax_refund_amount' => 0,
            'deductions_or_accountability' => 1500,
            'net_final_pay' => 17750,
            'notes' => 'Demo resignation. Awaiting return of company equipment and final clearance.',
            'status' => 'Pending_Clearance',
            'reviewed_by' => 'Adrian Reyes'
        ]);
    }

    private function seedAuditLogs(): void
    {
        $events = [
            ['2026-09-01 08:00:00','login','users',null,'Admin signed in for initial company setup.'],
            ['2026-09-02 10:15:00','create','departments',null,'HR, IT, and Finance departments configured.'],
            ['2026-09-08 09:30:00','create','employees',$this->employeeIds['ST-1007'],'Gabriel Flores onboarded.'],
            ['2026-09-15 09:30:00','create','employees',$this->employeeIds['ST-1008'],'Hannah Villanueva onboarded.'],
            ['2026-09-21 16:00:00','approve','leave_requests',null,'Manager approved annual leave request.'],
            ['2026-10-05 16:00:00','reject','leave_requests',null,'Manager rejected leave due to team coverage.'],
            ['2026-10-20 17:00:00','process','payroll_periods',$this->periodIds[0] ?? null,'September payroll cycle processed.'],
            ['2026-10-22 14:00:00','approve','overtime_requests',null,'Overtime request approved after review.'],
            ['2026-11-01 09:00:00','approve','promotion_requests',null,'Promotion recommendation approved by HR.'],
            ['2026-11-14 17:00:00','update','employees',$this->employeeIds['ST-1015'],'Employee separation initiated.'],
            ['2026-11-20 16:30:00','process','payroll_periods',$this->periodIds[4] ?? null,'November first-half payroll finalized.'],
            ['2026-11-25 11:00:00','create','overtime_requests',null,'New overtime request submitted for review.'],
        ];

        foreach ($events as $i => [$date,$action,$entity,$entityId,$details]) {
            $this->insertOnce('audit_logs', 'audit-event-' . ($i + 1), [
                'actor_user_id' => $this->userIds['adrian.reyes'],
                'action' => $action, 'entity_type' => $entity,
                'entity_id' => $entityId,
                'details' => json_encode(['message' => $details], JSON_THROW_ON_ERROR),
                'created_at' => $date,
            ]);
        }
    }

    private function userIdForEmployee(string $employeeCode): int
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE employee_id = ? LIMIT 1');
        $stmt->execute([$this->employeeIds[$employeeCode]]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException("No user account found for employee {$employeeCode}.");
        }

        return (int)$id;
    }

    private function nameForEmployee(string $code): string
    {
        $stmt = $this->db->prepare('SELECT CONCAT(first_name, " ", last_name) FROM employees WHERE id = ?');
        $stmt->execute([$this->employeeIds[$code]]);
        return (string)$stmt->fetchColumn();
    }

    private function emailForEmployee(string $code): string
    {
        $stmt = $this->db->prepare('SELECT email FROM employees WHERE id = ?');
        $stmt->execute([$this->employeeIds[$code]]);
        return (string)$stmt->fetchColumn();
    }
}
