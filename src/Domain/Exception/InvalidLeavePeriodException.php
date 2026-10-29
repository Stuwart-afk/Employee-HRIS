<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Exception;

final class InvalidLeavePeriodException extends DomainException
{
    public static function endBeforeStart(\DateTimeInterface $start, \DateTimeInterface $end): self
    {
        return new self(sprintf(
            'Leave end date (%s) cannot be before start date (%s).',
            $end->format('Y-m-d'),
            $start->format('Y-m-d')
        ));
    }

    public static function zeroOrNegativeDays(float $days): self
    {
        return new self(sprintf('Leave duration must be greater than 0, got %.2f days.', $days));
    }
}
