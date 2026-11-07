<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Infrastructure\Persistence\MySQL\Employee;

use PDO;
use Administrator\EmployeeHris\Domain\Employee\Entities\Employee;
use Administrator\EmployeeHris\Domain\Employee\Repositories\EmployeeRepositoryInterface;
use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmployeeId;
use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmploymentStatus;
use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmploymentType;

final class MySQLEmployeeRepository implements EmployeeRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Employee
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employees WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmployeeId(EmployeeId $employeeId): ?Employee
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employees WHERE employee_id = :employee_id LIMIT 1'
        );
        $stmt->execute(['employee_id' => $employeeId->value()]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM employees ORDER BY last_name, first_name'
        );

        return array_map(
            fn(array $row): Employee => $this->hydrate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function save(Employee $employee): int
    {
        $data = [
            'employee_id' => $employee->employeeId()->value(),
            'first_name' => $employee->firstName(),
            'middle_name' => $employee->middleName(),
            'last_name' => $employee->lastName(),
            'suffix' => $employee->suffix(),
            'date_of_birth' => $employee->dateOfBirth(),
            'gender' => $employee->gender(),
            'phone' => $employee->phone(),
            'email' => $employee->email(),
            'address' => $employee->address(),
            'department_id' => $employee->departmentId(),
            'position_id' => $employee->positionId(),
            'date_hired' => $employee->dateHired(),
            'employment_type' => $employee->employmentType()->value,
            'employment_status' => $employee->employmentStatus()->value,
        ];

        if ($employee->id() === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO employees (
                    employee_id, first_name, middle_name, last_name,
                    suffix, date_of_birth, gender, phone, email, address,
                    department_id, position_id, date_hired,
                    employment_type, employment_status
                ) VALUES (
                    :employee_id, :first_name, :middle_name, :last_name,
                    :suffix, :date_of_birth, :gender, :phone, :email, :address,
                    :department_id, :position_id, :date_hired,
                    :employment_type, :employment_status
                )'
            );

            $stmt->execute($data);

            return (int) $this->pdo->lastInsertId();
        }

        $data['id'] = $employee->id();

        $stmt = $this->pdo->prepare(
            'UPDATE employees SET
                employee_id = :employee_id,
                first_name = :first_name,
                middle_name = :middle_name,
                last_name = :last_name,
                suffix = :suffix,
                date_of_birth = :date_of_birth,
                gender = :gender,
                phone = :phone,
                email = :email,
                address = :address,
                department_id = :department_id,
                position_id = :position_id,
                date_hired = :date_hired,
                employment_type = :employment_type,
                employment_status = :employment_status,
                updated_at = NOW()
            WHERE id = :id'
        );

        $stmt->execute($data);

        return $employee->id();
    }

    private function hydrate(array $row): Employee
    {
        return new Employee(
            id: (int) $row['id'],
            employeeId: new EmployeeId($row['employee_id']),
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            employmentStatus: EmploymentStatus::fromString($row['employment_status']),
            middleName: $row['middle_name'],
            suffix: $row['suffix'],
            dateOfBirth: $row['date_of_birth'],
            gender: $row['gender'],
            phone: $row['phone'],
            email: $row['email'],
            address: $row['address'],
            departmentId: $row['department_id'] !== null
                ? (int) $row['department_id'] : null,
            positionId: $row['position_id'] !== null
                ? (int) $row['position_id'] : null,
            dateHired: $row['date_hired'],
            employmentType: EmploymentType::fromString($row['employment_type'])
        );
    }
}
