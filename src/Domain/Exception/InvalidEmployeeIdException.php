<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Exception;

final class InvalidEmployeeIdException extends DomainException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Invalid employee ID format "%s". Expected format like "EMP-001".', $value));
    }
}
