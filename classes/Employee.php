<?php

class Employee
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
        $sql = "
            SELECT
                e.id,
                e.employee_id,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix,
                e.date_of_birth,
                e.gender,
                e.phone,
                e.email,
                e.address,
                e.department_id,
                e.position_id,
                e.date_hired,
                e.employment_type,
                e.employment_status,
                d.name AS department_name,
                p.title AS position_title
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.id
            LEFT JOIN positions p
                ON e.position_id = p.id
            ORDER BY e.last_name ASC, e.first_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $sql = "
            SELECT
                e.*,
                d.name AS department_name,
                p.title AS position_title
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.id
            LEFT JOIN positions p
                ON e.position_id = p.id
            WHERE e.id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'id' => $id
        ]);

        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        return $employee ?: null;
    }

    public function create(array $data): bool
    {
        $sql = "
            INSERT INTO employees (
                employee_id,
                first_name,
                middle_name,
                last_name,
                suffix,
                date_of_birth,
                gender,
                phone,
                email,
                address,
                department_id,
                position_id,
                date_hired,
                employment_type,
                employment_status
            )
            VALUES (
                :employee_id,
                :first_name,
                :middle_name,
                :last_name,
                :suffix,
                :date_of_birth,
                :gender,
                :phone,
                :email,
                :address,
                :department_id,
                :position_id,
                :date_hired,
                :employment_type,
                :employment_status
            )
        ";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            'employee_id' => $data['employee_id'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?: null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?: null,
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'department_id' => $data['department_id'] ?: null,
            'position_id' => $data['position_id'] ?: null,
            'date_hired' => $data['date_hired'],
            'employment_type' => $data['employment_type'],
            'employment_status' => $data['employment_status']
        ]);
    }
}
