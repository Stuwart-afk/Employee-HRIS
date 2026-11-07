<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Employee\Repositories;

use Administrator\EmployeeHris\Domain\Employee\Entities\Employee;
use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmployeeId;

interface EmployeeRepositoryInterface
{
    public function findById(int $id): ?Employee;

    public function findByEmployeeId(EmployeeId $employeeId): ?Employee;

    /** @return list<Employee> */
    public function findAll(): array;

    /**
     * Persists an employee and returns its database primary key.
     */
    public function save(Employee $employee): int;
}
