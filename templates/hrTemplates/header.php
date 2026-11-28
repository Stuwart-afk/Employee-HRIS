<?php
// templates/hrTemplates/header.php
//
// Shared app shell for Admin, HR and Manager / Team Leader pages.
// templates/adminTemplates/header.php simply loads this file, so every
// staff page gets the same sidebar, and an admin never sees the layout
// change when moving between "HR" and "admin" pages.
//
// To add a menu item, add one entry to $navSections below.

require_once __DIR__ . '/../../classes/Rbac.php';

$currentPage = basename(
    $_SERVER['PHP_SELF'] ?? 'dashboard.php',
    '.php'
);

$phpSelf       = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$navFolder     = basename(dirname($phpSelf));
$inAdminFolder = $navFolder === 'admin';

// ==================================================
// ACCOUNT INFORMATION
// ==================================================

$navRole = Rbac::normalizeRole($_SESSION['role'] ?? '');
$navRank = Rbac::normalizeRank($_SESSION['rank'] ?? 'employee');

$navPdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;

$isHrSide  = in_array($navRole, ['admin', 'hr'], true);
$isAdmin   = $navRole === 'admin';
$isManager = $navRole === 'employee'
    && in_array($navRank, ['manager', 'team_leader'], true);

// Same rule as authentication/guard.php:
// only employee accounts use rank overrides.
$navRbacRank = $navRole === 'employee' ? $navRank : null;

// ==================================================
// PERMISSION HELPERS
// ==================================================

$navCan = static function (
    string $module,
    string $ability = 'view',
    ?string $scope = null // accepted for older calls; not used
) use ($navPdo, $navRole, $navRbacRank): bool {

    if ($navPdo === null) {
        return false;
    }

    return Rbac::can($navPdo, $navRole, $module, $ability, $navRbacRank);
};

$navModuleEnabled = static function (
    string $module
) use ($navPdo, $navRole, $navRbacRank): bool {

    if ($navPdo === null) {
        return false;
    }

    return Rbac::moduleEnabled($navPdo, $navRole, $module, $navRbacRank);
};

// Small helpers (prefixed so they never clash with page variables).
$navEsc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

// True when the current file is one of $pages (optionally inside $folder).
$navOn = static fn(array $pages, ?string $folder = null): bool =>
in_array($currentPage, $pages, true)
    && ($folder === null || $navFolder === $folder);

// ==================================================
// PAGE TITLES
// ==================================================

$pageTitles = [

    'dashboard' => $isManager
        ? ($navRank === 'manager' ? 'Manager Dashboard' : 'Team Leader Dashboard')
        : ($inAdminFolder ? 'Admin Dashboard' : ($isHrSide ? 'HR Dashboard' : 'Dashboard')),

    'employees' => 'Employee Management',
    'add_employee' => 'Add Employee',
    'edit_employee' => 'Edit Employee',
    'view_employee' => 'Employee Profile',
    'employee_deactivate' => 'Deactivate Employee',

    'attendance' => 'Attendance',
    'daily_attendance' => 'Daily Attendance',
    'attendance_records' => 'Attendance Records',
    'late_absence_monitoring' => 'Late and Absence Monitoring',
    'overtime' => 'Overtime Management',
    'attendance_reports' => 'Attendance Reports',
    'schedule_management' => 'Schedule Management',

    'recruitment' => 'Recruitment',
    'onboarding' => 'Onboarding',
    'onboarding_documents' => 'Pre-Employment Documents',

    'department' => 'Department Management',
    'position' => 'Position Management',

    'leave_management' => 'Leave Management',
    'leave_requests' => 'Team Leave Requests',

    'report' => 'Reports',
    'payroll' => 'Payroll Management',
    'payslip_view' => 'Payslip',
    'benefits' => 'Benefits & Separation Pay',

    'users' => 'User Management',
    'create_user' => 'Create User',
    'permissions' => 'System Settings',
    'audit_logs' => 'Audit Trail',
    'backup_management' => 'Backup Management',

    'team' => 'My Team',
    'promotion_requests' => 'Promotion Approvals'
];

$pageTitle = $pageTitles[$currentPage] ?? 'Human Resources';

$defaultCssSlug = in_array(
    $currentPage,
    [
        'daily_attendance',
        'attendance_records',
        'late_absence_monitoring',
        'overtime',
        'attendance_reports'
    ],
    true
) ? 'attendance' : $currentPage;

$cssPage = $cssPageOverride
    ?? preg_replace('/[^a-z0-9_-]/', '', $defaultCssSlug);

// ==================================================
// NAVIGATION STATE
// ==================================================

$attendancePages = [
    'attendance',
    'daily_attendance',
    'attendance_records',
    'late_absence_monitoring',
    'overtime',
    'attendance_reports',
    'schedule_management'
];

$canViewOnboarding = $isHrSide
    && $navModuleEnabled('employee_onboarding');

// Current query parameters (used by the attendance sub-links).
$curTab   = $_GET['tab'] ?? '';
$curRange = $_GET['range'] ?? '';
$curQ     = $_GET['q'] ?? '';

// ==================================================
// SIDEBAR MENU
//
// Each section has a label and a list of items.
// Each item:
//   href     link target
//   label    text shown
//   icon     Bootstrap Icons class
//   active   true when this item is the current page
//   visible  permission check
//   children (optional) sub-links: [href, label, active]
// Sections with no visible items are hidden.
// ==================================================

$navSections = [

    [
        'label' => 'Overview',
        'items' => [
            [
                'href'    => '/admin/dashboard.php',
                'label'   => 'Admin Dashboard',
                'icon'    => 'bi-speedometer2',
                'active'  => $navOn(['dashboard'], 'admin'),
                'visible' => $isAdmin && $navModuleEnabled('dashboard'),
            ],
            [
                'href'    => '/hr/dashboard.php',
                'label'   => $isAdmin ? 'HR Dashboard' : 'Dashboard',
                'icon'    => 'bi-grid-1x2',
                'active'  => $navOn(['dashboard'], 'hr'),
                'visible' => $isHrSide && $navModuleEnabled('dashboard'),
            ],
            [
                'href'    => '/hr/report.php',
                'label'   => 'Reports',
                'icon'    => 'bi-bar-chart-line',
                'active'  => $navOn(['report'], 'hr'),
                'visible' => $isHrSide && $navModuleEnabled('reports'),
            ],
        ],
    ],

    [
        'label' => 'People',
        'items' => [
            [
                'href'    => '/hr/employees.php',
                'label'   => 'Employees',
                'icon'    => 'bi-people',
                'active'  => $navOn([
                    'employees',
                    'add_employee',
                    'edit_employee',
                    'view_employee',
                    'employee_deactivate'
                ], 'hr'),
                'visible' => $isHrSide && $navModuleEnabled('employee_records'),
            ],
            [
                'href'    => '/hr/department.php',
                'label'   => 'Departments',
                'icon'    => 'bi-diagram-3',
                'active'  => $navOn(['department'], 'hr'),
                'visible' => $isHrSide && $navCan('employee_records', 'edit'),
            ],
            [
                'href'    => '/hr/position.php',
                'label'   => 'Positions',
                'icon'    => 'bi-briefcase',
                'active'  => $navOn(['position'], 'hr'),
                'visible' => $isHrSide && $navCan('employee_records', 'edit'),
            ],
            [
                'href'    => '/hr/recruitment.php',
                'label'   => 'Recruitment',
                'icon'    => 'bi-person-workspace',
                'active'  => $navOn(['recruitment'], 'hr'),
                'visible' => $isHrSide && $navModuleEnabled('recruitment'),
            ],
            [
                'href'     => '/hr/onboarding.php',
                'label'    => 'Onboarding',
                'icon'     => 'bi-person-check',
                'active'   => $navOn(['onboarding', 'onboarding_documents'], 'hr'),
                'visible'  => $canViewOnboarding,
                'children' => [
                    [
                        '/hr/onboarding_documents.php',
                        'Pre-Employment Docs',
                        $navOn(['onboarding_documents'], 'hr'),
                    ],
                ],
            ],
            [
                'href'    => '/hr/promotion_requests.php',
                'label'   => 'Promotion Approvals',
                'icon'    => 'bi-graph-up-arrow',
                'active'  => $navOn(['promotion_requests'], 'hr'),
                'visible' => $isHrSide && $navCan('promotion_management', 'approve'),
            ],
        ],
    ],

    [
        'label' => 'Time and leave',
        'items' => [
            [
                'href'     => '/hr/daily_attendance.php',
                'label'    => 'Attendance',
                'icon'     => 'bi-clock-history',
                'active'   => $navOn($attendancePages, 'hr'),
                'visible'  => $isHrSide && $navModuleEnabled('attendance'),
                'children' => [
                    [
                        '/hr/schedule_management.php',
                        'Schedule Management',
                        $navOn(['schedule_management'], 'hr'),
                    ],
                    [
                        '/hr/daily_attendance.php',
                        'Daily Attendance',
                        $navOn(['daily_attendance'], 'hr')
                            || ($navOn(['attendance'], 'hr') && $curTab === 'logs' && $curRange === 'daily'),
                    ],
                    [
                        '/hr/attendance_records.php',
                        'Attendance Records',
                        $navOn(['attendance_records'], 'hr')
                            || ($navOn(['attendance'], 'hr') && $curTab === 'logs' && $curRange === 'monthly'),
                    ],
                    [
                        '/hr/late_absence_monitoring.php',
                        'Late and Absence Monitoring',
                        $navOn(['late_absence_monitoring'], 'hr')
                            || ($navOn(['attendance'], 'hr') && $curTab === 'logs' && $curQ === 'Late'),
                    ],
                    [
                        '/hr/overtime.php',
                        'Overtime Management',
                        $navOn(['overtime'], 'hr'),
                    ],
                    [
                        '/hr/attendance_reports.php',
                        'Attendance Reports',
                        $navOn(['attendance_reports'], 'hr')
                            || ($navOn(['attendance'], 'hr') && $curTab === 'reports'),
                    ],
                ],
            ],
            [
                'href'    => '/hr/leave_management.php',
                'label'   => 'Leave Management',
                'icon'    => 'bi-calendar-check',
                'active'  => $navOn(['leave_management'], 'hr'),
                'visible' => $isHrSide && $navCan('leave_requests', 'view'),
            ],
        ],
    ],

    [
        'label' => 'Pay and benefits',
        'items' => [
            [
                'href'    => '/hr/payroll.php',
                'label'   => 'Payroll',
                'icon'    => 'bi-cash-stack',
                'active'  => $navOn(['payroll', 'payslip_view'], 'hr'),
                'visible' => $isHrSide && $navModuleEnabled('payroll'),
            ],
            [
                'href'    => '/hr/benefits.php',
                'label'   => 'Benefits & Separation',
                'icon'    => 'bi-gift',
                'active'  => $navOn(['benefits'], 'hr'),
                'visible' => $isHrSide && $navModuleEnabled('payroll'),
            ],
        ],
    ],

    [
        'label' => 'Administration',
        'items' => [
            [
                'href'    => '/admin/users.php',
                'label'   => 'User Management',
                'icon'    => 'bi-person-gear',
                'active'  => $navOn(['users', 'create_user'], 'admin'),
                'visible' => $isHrSide && $navModuleEnabled('user_management'),
            ],
            [
                'href'    => '/admin/audit_logs.php',
                'label'   => 'Audit Trail',
                'icon'    => 'bi-clipboard-data',
                'active'  => $navOn(['audit_logs'], 'admin'),
                'visible' => $isHrSide && $navModuleEnabled('audit_logs'),
            ],
            [
                'href'    => '/admin/permissions.php',
                'label'   => 'System Settings',
                'icon'    => 'bi-shield-lock',
                'active'  => $navOn(['permissions'], 'admin'),
                'visible' => $isHrSide && $navModuleEnabled('system_settings'),
            ],
            [
                'href'    => '/admin/backup_management.php',
                'label'   => 'Backup Management',
                'icon'    => 'bi-database-check',
                'active'  => $navOn(['backup_management'], 'admin'),
                'visible' => $navModuleEnabled('backup_management')
                    && Rbac::canManageBackups($navRole),
            ],
        ],
    ],

    [
        'label' => 'My team',
        'items' => [
            [
                'href'    => '/manager/dashboard.php',
                'label'   => 'Dashboard',
                'icon'    => 'bi-grid-1x2',
                'active'  => $navOn(['dashboard'], 'manager'),
                'visible' => $isManager && $navModuleEnabled('dashboard'),
            ],
            [
                'href'    => '/manager/team.php',
                'label'   => 'My Team',
                'icon'    => 'bi-people',
                'active'  => $navOn(['team'], 'manager'),
                'visible' => $isManager && $navCan('employee_records', 'view', 'team'),
            ],
            [
                'href'    => '/manager/leave_requests.php',
                'label'   => 'Team Leave',
                'icon'    => 'bi-calendar-check',
                'active'  => $navOn(['leave_requests'], 'manager'),
                'visible' => $isManager && $navCan('leave_requests', 'view', 'team'),
            ],
            [
                'href'    => '/manager/attendance.php',
                'label'   => 'Team Attendance',
                'icon'    => 'bi-clock-history',
                'active'  => $navOn(['attendance'], 'manager'),
                'visible' => $isManager && $navCan('attendance', 'view', 'team'),
            ],
        ],
    ],
];

// ==================================================
// SIDEBAR PROFILE
// ==================================================

$roleLabels = [

    'admin' => [
        'AD',
        'Administrator',
        'System administrator',
        'ADMINISTRATION',
        'Admin Workspace',
        'Admin Control'
    ],

    'hr' => [
        'HR',
        'HR Administration',
        'Human Resources',
        'HUMAN RESOURCES',
        'HR Workspace',
        'HR Portal'
    ],

    'employee' => $navRank === 'manager'
        ? [
            'MG',
            'Manager',
            'Department Management',
            'MANAGER PORTAL',
            'Manager Workspace',
            'Manager Portal'
        ]
        : (
            $navRank === 'team_leader'
            ? [
                'TL',
                'Team Leader',
                'Team Management',
                'TEAM LEADER',
                'Team Workspace',
                'Team Portal'
            ]
            : [
                'EM',
                'Employee',
                'Employee Workspace',
                'EMPLOYEE',
                'Employee Workspace',
                'Employee Portal'
            ]
        )
];

[
    $navInitials,
    $navName,
    $navSub,
    $navEyebrow,
    $navStatus,
    $navTagline
] = $roleLabels[$navRole] ?? [
    'HR',
    'HR Administration',
    'Human Resources',
    'HUMAN RESOURCES',
    'HR Workspace',
    'HR Portal'
];

// Show the real account name when the session has one.
$navUserName = trim((string) ($_SESSION['username'] ?? ''));

if ($navUserName !== '') {
    $navInitials = strtoupper(substr($navUserName, 0, 2));
    $navName     = $navUserName;
}

$navHome = function_exists('role_home')
    ? role_home($navRole)
    : ($isManager ? '/manager/dashboard.php' : '/hr/dashboard.php');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= $navEsc($pageTitle) ?> | HRIS
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Icon fonts: used by the sidebar and by the admin pages. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <?php if ($inAdminFolder): ?>
        <!-- Admin pages were built on Bootstrap; HR pages are not. -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <?php endif; ?>

    <link rel="stylesheet" href="/assets/css/hris-base.css">
    <link rel="stylesheet" href="/assets/css/welcome.css">

    <link
        rel="stylesheet"
        href="/assets/css/pages/<?= $navEsc($cssPage) ?>.css">

    <script src="/asset/js/page-transition.js" defer></script>

    <style>
        /* =========================================
           SIDEBAR SHELL (drawer on small screens)
        ========================================= */
        .hris-app .sidebar {
            position: fixed !important;
            top: 0;
            left: 0;
            bottom: 0;
            width: min(264px, 85vw);
            height: 100vh;
            height: 100dvh;
            padding: 22px 14px 18px;
            border-right: 1px solid var(--line);
            border-bottom: 0;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 300;
            transform: translateX(-100%);
            transition: transform .28s cubic-bezier(.4, 0, .2, 1);
        }

        .hris-app .sidebar:not(.hidden) {
            transform: translateX(0);
        }

        .hris-app .sidebar.hidden {
            transform: translateX(-100%);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 20, .62);
            z-index: 299;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        .hris-app .logo-group {
            margin: 0 6px 22px;
        }

        /* =========================================
           MENU SECTIONS AND ITEMS
        ========================================= */
        .hris-app .nav-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow: visible;
        }

        .nav-section {
            display: grid;
            gap: 3px;
        }

        .nav-section-label {
            margin: 0 0 4px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            opacity: .85;
        }

        .hris-app .nav-item {
            padding: 10px 12px;
            font-size: 13.5px;
        }

        .nav-icon {
            flex: 0 0 22px;
        }

        .nav-icon i {
            display: block;
            line-height: 1;
        }

        .nav-text {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Item that has a submenu: link + separate caret button */
        .nav-split {
            position: relative;
        }

        .nav-split .nav-item {
            padding-right: 44px;
        }

        .nav-caret {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            transition: background .15s ease, color .15s ease;
        }

        .nav-caret:hover {
            background: rgba(170, 220, 255, .10);
            color: #fff;
        }

        .nav-caret i {
            font-size: 12px;
            transition: transform .22s ease;
        }

        .nav-caret[aria-expanded="true"] i {
            transform: rotate(180deg);
        }

        /* Submenu */
        .nav-group {
            display: block;
        }

        .nav-sub {
            display: grid;
            grid-template-rows: 0fr;
            transition: grid-template-rows .22s ease;
        }

        .nav-sub.is-open {
            grid-template-rows: 1fr;
        }

        .nav-sub-inner {
            min-height: 0;
            overflow: hidden;
            display: grid;
            gap: 2px;
            margin-left: 21px;
            padding-left: 12px;
            border-left: 1px solid var(--line);
            visibility: hidden;
            transition: visibility 0s linear .22s;
        }

        .nav-sub.is-open .nav-sub-inner {
            visibility: visible;
            transition-delay: 0s;
            margin-top: 2px;
            margin-bottom: 4px;
        }

        .nav-sub-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 10px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--muted);
            transition: background .15s ease, color .15s ease;
        }

        .nav-sub-link::before {
            content: "";
            flex: 0 0 5px;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--line);
            transition: background .15s ease;
        }

        .nav-sub-link:hover {
            background: rgba(59, 130, 246, .10);
            color: #fff;
        }

        .nav-sub-link.active {
            background: rgba(139, 92, 246, .18);
            color: var(--moon);
        }

        .nav-sub-link.active::before {
            background: var(--teal);
            box-shadow: 0 0 8px rgba(118, 245, 232, .6);
        }

        /* Keyboard focus */
        .nav-item:focus-visible,
        .nav-sub-link:focus-visible,
        .nav-caret:focus-visible,
        .sidebar-toggle:focus-visible,
        .logo-group:focus-visible,
        .logout-btn:focus-visible {
            outline: 2px solid var(--teal);
            outline-offset: 2px;
        }

        /* =========================================
           SIDEBAR FOOTER
        ========================================= */
        .hris-app .sidebar-footer {
            display: block;
            position: sticky;
            bottom: -18px;
            margin-top: auto;
            padding: 0 0 18px;
            background: #090d1e;
        }

        .hris-app .sidebar-footer .footer-line {
            margin: 0 0 12px;
        }

        .hris-app .nav-list {
            padding-bottom: 16px;
        }

        .admin-info strong {
            display: block;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* =========================================
           MAIN CONTENT
        ========================================= */
        .hris-app .main {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100vw !important;
            min-width: 0;
            overflow-x: hidden;
        }

        .hris-app .content {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            margin: 0;
            overflow-x: hidden;
        }

        .hris-app .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
            min-width: 0;
            gap: 12px;
        }

        .topbar-title {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 6px;
        }

        .title-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .title-row strong {
            font-size: 18px;
            color: #fff;
            overflow-wrap: anywhere;
        }

        /* =========================================
           SIDEBAR TOGGLE (hamburger)
        ========================================= */
        .sidebar-toggle {
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            padding: 7px 6px;
            margin-right: 0;
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            cursor: pointer;
        }

        .sidebar-toggle .hb-line {
            display: block;
            width: 18px;
            height: 2px;
            border-radius: 2px;
            background: #e2e0ea;
        }

        .sidebar-toggle:hover {
            background: rgba(139, 92, 246, .2);
            border-color: rgba(139, 92, 246, .4);
        }

        /* =========================================
           WIDE SCREENS: sidebar stays pinned.
           Delete this block to go back to drawer-only.
        ========================================= */
        @media (min-width: 1200px) {

            .hris-app .sidebar,
            .hris-app .sidebar.hidden {
                transform: none;
            }

            .hris-app .main {
                margin-left: 264px !important;
                width: calc(100% - 264px) !important;
                max-width: none !important;
            }

            .hris-app .sidebar-toggle {
                display: none;
            }

            .sidebar-backdrop,
            .sidebar-backdrop.active {
                display: none;
            }
        }

        /* =========================================
           SCROLLBARS
        ========================================= */
        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        .sidebar::-webkit-scrollbar,
        .content::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }

        /* =========================================
           MOBILE
        ========================================= */
        @media (max-width: 760px) {
            .hris-app .topbar {
                padding: 12px 16px;
                min-height: 68px;
                height: auto;
                flex-wrap: wrap;
            }

            .hris-app .topbar-actions {
                display: none;
            }

            .hris-app .content {
                padding: 22px 16px 32px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .hris-app .sidebar,
            .nav-sub,
            .nav-caret i {
                transition: none;
            }
        }
    </style>
</head>

<body class="hris-app page-<?= $navEsc($cssPage) ?>">

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =========================================
         SIDEBAR
    ========================================== -->

    <aside class="sidebar hidden" id="hrSidebar">

        <a
            class="logo-group"
            href="<?= $navEsc($navHome) ?>"
            aria-label="HRIS home">

            <span class="logo-mark">H</span>

            <span class="logo-text">
                <strong>HRIS</strong>
                <small><?= $navEsc($navTagline) ?></small>
            </span>

        </a>

        <nav class="nav-list" aria-label="Main navigation">

            <?php foreach ($navSections as $navSection): ?>

                <?php
                $navVisible = array_values(array_filter(
                    $navSection['items'],
                    static fn(array $navEntry): bool => !empty($navEntry['visible'])
                ));

                if (!$navVisible) {
                    continue;
                }
                ?>

                <div class="nav-section">

                    <p class="nav-section-label"><?= $navEsc($navSection['label']) ?></p>

                    <?php foreach ($navVisible as $navIdx => $navEntry): ?>

                        <?php
                        $navChildren = $navEntry['children'] ?? [];
                        $navChildActive = false;

                        foreach ($navChildren as $navChildRow) {
                            if (!empty($navChildRow[2])) {
                                $navChildActive = true;
                                break;
                            }
                        }

                        $navActive = !empty($navEntry['active']);
                        $navGroupId = 'navGroup' . md5($navEntry['href']);
                        $navOpen = $navActive || $navChildActive;
                        ?>

                        <?php if ($navChildren): ?>

                            <div class="nav-group">

                                <div class="nav-split">

                                    <a
                                        class="nav-item <?= $navActive ? 'active' : '' ?>"
                                        href="<?= $navEsc($navEntry['href']) ?>"
                                        <?= ($navActive && !$navChildActive) ? 'aria-current="page"' : '' ?>>
                                        <span class="nav-icon"><i class="bi <?= $navEsc($navEntry['icon']) ?>" aria-hidden="true"></i></span>
                                        <span class="nav-text"><?= $navEsc($navEntry['label']) ?></span>
                                    </a>

                                    <button
                                        type="button"
                                        class="nav-caret"
                                        data-nav-toggle="<?= $navEsc($navGroupId) ?>"
                                        aria-expanded="<?= $navOpen ? 'true' : 'false' ?>"
                                        aria-controls="<?= $navEsc($navGroupId) ?>"
                                        aria-label="Show or hide <?= $navEsc($navEntry['label']) ?> pages">
                                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                    </button>

                                </div>

                                <div class="nav-sub <?= $navOpen ? 'is-open' : '' ?>" id="<?= $navEsc($navGroupId) ?>">
                                    <div class="nav-sub-inner">

                                        <?php foreach ($navChildren as [$navChildHref, $navChildLabel, $navChildIsActive]): ?>
                                            <a
                                                class="nav-sub-link <?= $navChildIsActive ? 'active' : '' ?>"
                                                href="<?= $navEsc($navChildHref) ?>"
                                                <?= $navChildIsActive ? 'aria-current="page"' : '' ?>>
                                                <span><?= $navEsc($navChildLabel) ?></span>
                                            </a>
                                        <?php endforeach; ?>

                                    </div>
                                </div>

                            </div>

                        <?php else: ?>

                            <a
                                class="nav-item <?= $navActive ? 'active' : '' ?>"
                                href="<?= $navEsc($navEntry['href']) ?>"
                                <?= $navActive ? 'aria-current="page"' : '' ?>>
                                <span class="nav-icon"><i class="bi <?= $navEsc($navEntry['icon']) ?>" aria-hidden="true"></i></span>
                                <span class="nav-text"><?= $navEsc($navEntry['label']) ?></span>
                            </a>

                        <?php endif; ?>

                    <?php endforeach; ?>

                </div>

            <?php endforeach; ?>

        </nav>

        <!-- SIDEBAR FOOTER -->

        <div class="sidebar-footer">

            <div class="footer-line"></div>

            <div class="admin-profile">

                <span class="avatar">
                    <?= $navEsc($navInitials) ?>
                </span>

                <span class="admin-info">
                    <strong><?= $navEsc($navName) ?></strong>
                    <span><?= $navEsc($navSub) ?></span>
                </span>

            </div>

            <a class="logout-btn" href="/authentication/logout.php">

                <svg
                    class="logout-ico"
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                    aria-hidden="true">

                    <path
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>

                <span>Log Out</span>

            </a>

        </div>

    </aside>

    <!-- =========================================
         MAIN CONTENT
    ========================================== -->

    <main class="main">

        <header class="topbar">

            <div class="topbar-title">

                <span class="eyebrow">
                    <?= $navEsc($navEyebrow) ?>
                </span>

                <div class="title-row">

                    <button
                        type="button"
                        class="sidebar-toggle"
                        id="sidebarToggle"
                        aria-label="Open navigation"
                        aria-expanded="false"
                        aria-controls="hrSidebar">
                        <span class="hb-line"></span>
                        <span class="hb-line"></span>
                        <span class="hb-line"></span>
                    </button>

                    <strong>
                        <?= $navEsc($pageTitle) ?>
                    </strong>

                </div>

            </div>

            <div class="topbar-actions">

                <span class="system-status">
                    <span class="status-dot"></span>
                    <?= $navEsc($navStatus) ?>
                </span>

            </div>

        </header>

        <section class="content">

            <script>
                (function() {

                    var sidebar = document.getElementById('hrSidebar');
                    var backdrop = document.getElementById('sidebarBackdrop');
                    var toggle = document.getElementById('sidebarToggle');

                    if (!sidebar) {
                        return;
                    }

                    // ---------- Drawer (small screens) ----------

                    function openSidebar() {
                        sidebar.classList.remove('hidden');
                        if (backdrop) {
                            backdrop.classList.add('active');
                        }
                        if (toggle) {
                            toggle.setAttribute('aria-expanded', 'true');
                            toggle.setAttribute('aria-label', 'Close navigation');
                        }
                    }

                    function closeSidebar() {
                        sidebar.classList.add('hidden');
                        if (backdrop) {
                            backdrop.classList.remove('active');
                        }
                        if (toggle) {
                            toggle.setAttribute('aria-expanded', 'false');
                            toggle.setAttribute('aria-label', 'Open navigation');
                        }
                    }

                    if (toggle) {
                        toggle.addEventListener('click', function() {
                            if (sidebar.classList.contains('hidden')) {
                                openSidebar();
                            } else {
                                closeSidebar();
                            }
                        });
                    }

                    if (backdrop) {
                        backdrop.addEventListener('click', closeSidebar);
                    }

                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            closeSidebar();
                        }
                    });

                    sidebar.querySelectorAll('a.nav-item, a.nav-sub-link').forEach(function(link) {
                        link.addEventListener('click', closeSidebar);
                    });

                    // ---------- Submenus ----------

                    sidebar.querySelectorAll('[data-nav-toggle]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            var panel = document.getElementById(button.getAttribute('data-nav-toggle'));

                            if (!panel) {
                                return;
                            }

                            var isOpen = panel.classList.toggle('is-open');
                            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        });
                    });

                    // ---------- Keep the current page visible in a long menu ----------

                    var current = sidebar.querySelector('[aria-current="page"]');

                    if (current) {
                        var offset = current.getBoundingClientRect().top - sidebar.getBoundingClientRect().top;
                        sidebar.scrollTop = Math.max(0, offset - sidebar.clientHeight / 2);
                    }

                })();
            </script>