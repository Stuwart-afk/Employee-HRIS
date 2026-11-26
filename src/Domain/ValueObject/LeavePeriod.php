<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\ValueObject;

use Administrator\EmployeeHris\Domain\Exception\InvalidLeavePeriodException;
use DateTimeImmutable;

final class LeavePeriod
{
    private DateTimeImmutable $startDate;
    private DateTimeImmutable $endDate;
    private float $numberOfDays;

    public function __construct(DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?float $explicitDays = null)
    {
        $normalizedStart = $startDate->setTime(0, 0, 0);
        $normalizedEnd = $endDate->setTime(0, 0, 0);

        if ($normalizedEnd < $normalizedStart) {
            throw InvalidLeavePeriodException::endBeforeStart($normalizedStart, $normalizedEnd);
        }

        $this->startDate = $normalizedStart;
        $this->endDate = $normalizedEnd;

        if ($explicitDays !== null) {
            if ($explicitDays <= 0) {
                throw InvalidLeavePeriodException::zeroOrNegativeDays($explicitDays);
            }
            $this->numberOfDays = $explicitDays;
        } else {
            // Default: inclusive calendar days
            $diff = $normalizedStart->diff($normalizedEnd);
            $this->numberOfDays = (float)($diff->days + 1);
        }
    }

    public static function create(string $startDateStr, string $endDateStr, ?float $days = null): self
    {
        return new self(
            new DateTimeImmutable($startDateStr),
            new DateTimeImmutable($endDateStr),
            $days
        );
    }

    public function startDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function endDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function numberOfDays(): float
    {
        return $this->numberOfDays;
    }

    /**
     * Calculates the number of working days (Monday-Friday) within this period.
     */
    public function workingDaysCount(): int
    {
        $count = 0;
        $current = $this->startDate;
        while ($current <= $this->endDate) {
            $dayOfWeek = (int)$current->format('N'); // 1 = Monday, 7 = Sunday
            if ($dayOfWeek <= 5) {
                $count++;
            }
            $current = $current->modify('+1 day');
        }
        return $count;
    }

    public function includes(DateTimeImmutable $date): bool
    {
        $check = $date->setTime(0, 0, 0);
        return $check >= $this->startDate && $check <= $this->endDate;
    }

    public function formattedStart(string $format = 'Y-m-d'): string
    {
        return $this->startDate->format($format);
    }

    public function formattedEnd(string $format = 'Y-m-d'): string
    {
        return $this->endDate->format($format);
    }
}
