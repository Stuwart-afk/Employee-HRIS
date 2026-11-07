<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Employee\ValueObjects;

use InvalidArgumentException;

enum EmploymentType: string
{
    case FULL_TIME = 'Full-time';
    case PART_TIME = 'Part-time';
    case CONTRACT = 'Contract';
    case INTERN = 'Intern';

    public static function fromString(string $type): self
    {
        return self::tryFrom($type)
            ?? throw new InvalidArgumentException(
                "Invalid employment type: {$type}"
            );
    }
}