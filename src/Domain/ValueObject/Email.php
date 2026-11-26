<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\ValueObject;

use Administrator\EmployeeHris\Domain\Exception\InvalidEmailException;

final class Email implements \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmailException::forValue($value);
        }
        $this->value = strtolower($trimmed);
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
