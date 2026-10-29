<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\ValueObject;

use Administrator\EmployeeHris\Domain\Exception\InvalidEmployeeIdException;

final class EmployeeId implements \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !preg_match('/^[A-Z]{2,4}-\d{3,6}$/i', $trimmed)) {
            throw InvalidEmployeeIdException::forValue($value);
        }
        $this->value = strtoupper($trimmed);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
