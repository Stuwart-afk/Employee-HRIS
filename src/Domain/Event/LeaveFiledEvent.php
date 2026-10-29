<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Event;

use DateTimeImmutable;

final class LeaveFiledEvent implements DomainEventInterface
{
    private DateTimeImmutable $occurredOn;

    public function __construct(
        public readonly int $requestId,
        public readonly int $employeeId,
        public readonly int $leaveTypeId,
        public readonly float $days,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $reason
    ) {
        $this->occurredOn = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'leave.filed';
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
            'leave_type_id' => $this->leaveTypeId,
            'days' => $this->days,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'reason' => $this->reason,
            'occurred_on' => $this->occurredOn->format(\DateTimeInterface::ATOM),
        ];
    }
}
