<?php
require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'edit', 'all');

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php'; // h()
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../classes/Department.php';
require __DIR__ . '/../classes/Position.php';

$employeeModel = new Employee($pdo);
$departmentModel = new Department($pdo);
$positionModel = new Position($pdo);

$isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id'])
    ? (int) $_GET['id']
    : (isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int) $_POST['id'] : 0);

if ($id <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid employee ID.']);
        exit;
    }
    header('Location: /hr/employees.php');
    exit;
}

$emp = $employeeModel->findById($id);

if (!$emp) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
        exit;
    }
    header('Location: /hr/employees.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'employee_id'   => trim($_POST['employee_id'] ?? ''),
        'first_name'    => trim($_POST['first_name'] ?? ''),
        'middle_name'   => trim($_POST['middle_name'] ?? ''),
        'last_name'     => trim($_POST['last_name'] ?? ''),
        'suffix'        => trim($_POST['suffix'] ?? ''),
        'date_of_birth' => $_POST['date_of_birth'] ?? '',
        'gender'        => $_POST['gender'] ?? '',
        'phone'         => trim($_POST['phone'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'address'       => trim($_POST['address'] ?? ''),
        'department_id' => ($_POST['department_id'] ?? '') !== ''
            ? (int)$_POST['department_id']
            : null,
        'position_id' => ($_POST['position_id'] ?? '') !== ''
            ? (int)$_POST['position_id']
            : null,
        'rank' => in_array(
            $_POST['rank'] ?? 'employee',
            ['employee', 'team_leader', 'manager'],
            true
        )
            ? $_POST['rank']
            : 'employee',
        'manager_id' => ($_POST['manager_id'] ?? '') !== ''
            ? (int)$_POST['manager_id']
            : null,
        'date_hired'        => $_POST['date_hired'] ?? '',
        'employment_type'   => $_POST['employment_type'] ?? 'Full-time',
        'employment_status' => $_POST['employment_status'] ?? 'Active',
    ];

    if (
        $data['employee_id'] === '' ||
        $data['first_name'] === '' ||
        $data['last_name'] === ''  ||
        $data['date_hired'] === ''
    ) {
        $error = 'Please fill in all required fields (Employee ID, First Name, Last Name, Date Hired).';
    } elseif (
        $data['manager_id'] !== null
        && !$employeeModel->isManagerCandidate($data['manager_id'], $id, isset($emp['manager_id']) ? (int)$emp['manager_id'] : null)
    ) {
        $error = 'Please choose a valid manager with manager rank, and not the employee themselves.';
    } else {
        try {
            $employeeModel->update($id, $data);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Employee updated successfully.']);
                exit;
            }
            header('Location: /hr/view_employee.php?id=' . $id);
            exit;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate') !== false || (int)($e->errorInfo[1] ?? 0) === 1062) {
                $error = 'Employee ID already exists. Please use a different one.';
            } else {
                $error = 'Unable to update employee. ' . $msg;
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}

require __DIR__ . '/../templates/hrTemplates/header.php';
require __DIR__ . '/../templates/hrTemplates/employee_modals.php';
?>

<p><a href="/hr/employees.php">← Back to Employees</a></p>

<script>
  window.addEventListener('load', function () {
    window.openEmployeeEditModal(<?= (int)$id ?>);
  });
</script>