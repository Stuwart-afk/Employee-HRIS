<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Employee\Entities;

use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmployeeId;
use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmploymentStatus;
use Administrator\EmployeeHris\Domain\Employee\ValueObjects\EmploymentType;
use InvalidArgumentException;

final class Employee
{
    public function __construct(
        private readonly ?int $id,
        private readonly EmployeeId $employeeId,
        private string $firstName,
        private string $lastName,
        private EmploymentStatus $employmentStatus,
        private ?string $middleName = null,
        private ?string $suffix = null,
        private ?string $dateOfBirth = null,
        private ?string $gender = null,
        private ?string $phone = null,
        private ?string $email = null,
        private ?string $address = null,
        private ?int $departmentId = null,
        private ?int $positionId = null,
        private ?string $dateHired = null,
        private EmploymentType $employmentType = EmploymentType::FULL_TIME
    ) {
        $this->validateName($firstName, 'First name');
        $this->validateName($lastName, 'Last name');

        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('Employee database ID must be positive.');
        }

        if ($departmentId !== null && $departmentId <= 0) {
            throw new InvalidArgumentException('Department ID must be positive.');
        }

        if ($positionId !== null && $positionId <= 0) {
            throw new InvalidArgumentException('Position ID must be positive.');
        }

        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid employee email address.');
        }

        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
    }

    private function validateName(string $name, string $field): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException("{$field} cannot be empty.");
        }
    }

    public function updateName(string $firstName, string $lastName): void
    {
        $this->validateName($firstName, 'First name');
        $this->validateName($lastName, 'Last name');

        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
    }

    public function updatePersonalInformation(
        ?string $middleName,
        ?string $suffix,
        ?string $dateOfBirth,
        ?string $gender,
        ?string $phone,
        ?string $email,
        ?string $address
    ): void {
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid employee email address.');
        }

        $this->middleName = $middleName;
        $this->suffix = $suffix;
        $this->dateOfBirth = $dateOfBirth;
        $this->gender = $gender;
        $this->phone = $phone;
        $this->email = $email;
        $this->address = $address;
    }

    public function updateEmploymentInformation(
        ?int $departmentId,
        ?int $positionId,
        string $dateHired,
        EmploymentType $employmentType
    ): void {
        if ($departmentId !== null && $departmentId <= 0) {
            throw new InvalidArgumentException('Department ID must be positive.');
        }

        if ($positionId !== null && $positionId <= 0) {
            throw new InvalidArgumentException('Position ID must be positive.');
        }

        if (trim($dateHired) === '') {
            throw new InvalidArgumentException('Date hired cannot be empty.');
        }

        $this->departmentId = $departmentId;
        $this->positionId = $positionId;
        $this->dateHired = $dateHired;
        $this->employmentType = $employmentType;
    }

    public function changeEmploymentStatus(EmploymentStatus $status): void
    {
        $this->employmentStatus = $status;
    }

    public function deactivate(): void
    {
        $this->employmentStatus = EmploymentStatus::INACTIVE;
    }

    public function activate(): void
    {
        $this->employmentStatus = EmploymentStatus::ACTIVE;
    }

    public function id(): ?int { return $this->id; }
    public function employeeId(): EmployeeId { return $this->employeeId; }
    public function firstName(): string { return $this->firstName; }
    public function lastName(): string { return $this->lastName; }
    public function middleName(): ?string { return $this->middleName; }
    public function suffix(): ?string { return $this->suffix; }
    public function dateOfBirth(): ?string { return $this->dateOfBirth; }
    public function gender(): ?string { return $this->gender; }
    public function phone(): ?string { return $this->phone; }
    public function email(): ?string { return $this->email; }
    public function address(): ?string { return $this->address; }
    public function departmentId(): ?int { return $this->departmentId; }
    public function positionId(): ?int { return $this->positionId; }
    public function dateHired(): ?string { return $this->dateHired; }
    public function employmentType(): EmploymentType { return $this->employmentType; }
    public function employmentStatus(): EmploymentStatus { return $this->employmentStatus; }
}
