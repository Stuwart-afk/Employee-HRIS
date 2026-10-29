<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Exception;

final class LeaveRequestAlreadyProcessedException extends DomainException
{
    public static function forStatus(int $requestId, string $currentStatus, string $attemptedAction): self
    {
        return new self(sprintf(
            'Cannot %s leave request #%d because it is already in %s status.',
            $attemptedAction,
            $requestId,
            $currentStatus
        ));
    }
}
