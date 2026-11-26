<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'all');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../classes/Department.php';
require __DIR__ . '/../classes/Position.php';
require_once __DIR__ . '/../config/helpers.php';

$employeeModel = new Employee($pdo);

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id'])
    ? (int) $_GET['id']
    : 0;

$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid employee ID.']);
        exit;
    }

    try {
        $emp = $employeeModel->findById($id);
        if (!$emp) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
            exit;
        }

        $departmentModel = new Department($pdo);
        $positionModel   = new Position($pdo);

        $departments = $departmentModel->getActive();
        $positions   = $positionModel->getActive();

        // Keep currently assigned department/position in dropdown even if inactive
        if (!empty($emp['department_id']) && !in_array((int)$emp['department_id'], array_map('intval', array_column($departments, 'id')), true)) {
            $s = $pdo->prepare("SELECT id, name, status FROM departments WHERE id = ? LIMIT 1");
            $s->execute([(int)$emp['department_id']]);
            if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                $departments[] = ['id' => $row['id'], 'name' => $row['name'] . ' (Inactive)', 'status' => $row['status']];
            }
        }
        if (!empty($emp['position_id']) && !in_array((int)$emp['position_id'], array_map('intval', array_column($positions, 'id')), true)) {
            $s = $pdo->prepare("SELECT id, title, status FROM positions WHERE id = ? LIMIT 1");
            $s->execute([(int)$emp['position_id']]);
            if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                $positions[] = ['id' => $row['id'], 'title' => $row['title'] . ' (Inactive)', 'status' => $row['status']];
            }
        }

        $managers = $employeeModel->getManagerOptions(isset($emp['manager_id']) ? (int)$emp['manager_id'] : null);
        $emp['rank_label'] = function_exists('rank_label') ? rank_label($emp['rank'] ?? 'employee') : ($emp['rank'] ?? 'employee');

        echo json_encode([
            'ok'          => true,
            'employee'    => $emp,
            'departments' => $departments,
            'positions'   => $positions,
            'managers'    => $managers,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to load employee profile.']);
    }
    exit;
}

if ($id <= 0) {
    header('Location: /hr/employees.php');
    exit;
}

try {
    $emp = $employeeModel->findById($id);
} catch (\Throwable $th) {
    header('Location: /hr/employees.php');
    exit;
}

if (!$emp) {
    header('Location: /hr/employees.php');
    exit;
}

require __DIR__ . '/../templates/hrTemplates/header.php';
require __DIR__ . '/../templates/hrTemplates/employee_modals.php';
?>

<p><a href="/hr/employees.php">← Back to Employees</a></p>

<script>
  window.addEventListener('load', function () {
    window.openEmployeeViewModal(<?= (int)$emp['id'] ?>);
  });
</script>