<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Employee\ValueObjects;

use InvalidArgumentException;

final class EmployeeId
{
    public function __construct(
        private readonly string $value
    ) {
        if (!preg_match('/^EMP-[0-9]{3,}$/', $value)) {
            throw new InvalidArgumentException(
                'Invalid employee ID format.'
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(EmployeeId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
