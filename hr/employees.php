<?php

require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/Employee.php';

$employeeModel = new Employee($pdo);
$employees = $employeeModel->getAll();

require __DIR__ . '/../templates/hrTemplates/header.php';

?>

<h1>Employee Management</h1>

<a href="add_employee.php">Add Employee</a>

<table border="1" cellpadding="8" cellspacing="0">
    <thead>
        <tr>
            <th>Employee ID</th>
            <th>Name</th>
            <th>Department</th>
            <th>Position</th>
            <th>Date Hired</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>

        <?php if (empty($employees)): ?>

            <tr>
                <td colspan="7">
                    No employees found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($employees as $employee): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($employee['employee_id']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $employee['first_name'] . ' ' .
                            ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') .
                            $employee['last_name'] .
                            ($employee['suffix'] ? ' ' . $employee['suffix'] : '')
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['position_title'] ?? 'N/A') ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['date_hired']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['employment_status']) ?>
                    </td>

                    <td>
                        <a href="view_employee.php?id=<?= $employee['id'] ?>">
                            View
                        </a>

                        |

                        <a href="edit_employee.php?id=<?= $employee['id'] ?>">
                            Edit
                        </a>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

    </tbody>
</table>
