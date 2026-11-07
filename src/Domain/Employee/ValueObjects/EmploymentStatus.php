<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Employee\ValueObjects;

use InvalidArgumentException;

enum EmploymentStatus: string
{
    case ACTIVE = 'Active';
    case INACTIVE = 'Inactive';
    case ON_LEAVE = 'On Leave';
    case SUSPENDED = 'Suspended';

    public static function fromString(string $status): self
    {
        return self::tryFrom($status)
            ?? throw new InvalidArgumentException(
                "Invalid employment status: {$status}"
            );
    }
}
