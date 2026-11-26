<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Exception;

final class InsufficientLeaveBalanceException extends DomainException
{
    public static function forDays(float $requested, float $available): self
    {
        return new self(sprintf(
            'Insufficient leave balance: requested %.2f day(s), but only %.2f day(s) remaining.',
            $requested,
            $available
        ));
    }
}
