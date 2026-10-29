<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Event;

use DateTimeImmutable;

final class LeaveApprovedEvent implements DomainEventInterface
{
    private DateTimeImmutable $occurredOn;

    public function __construct(
        public readonly int $requestId,
        public readonly int $employeeId,
        public readonly int $approverId,
        public readonly float $daysDeducted
    ) {
        $this->occurredOn = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'leave.approved';
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function toPayload(): array
    {
        return [
            'request_id' => $this->requestId,
            'employee_id' => $this->employeeId,
            'approver_id' => $this->approverId,
            'days_deducted' => $this->daysDeducted,
            'occurred_on' => $this->occurredOn->format(\DateTimeInterface::ATOM),
        ];
    }
}
