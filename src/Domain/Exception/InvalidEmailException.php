<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Exception;

final class InvalidEmailException extends DomainException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Invalid email address "%s".', $value));
    }
}
