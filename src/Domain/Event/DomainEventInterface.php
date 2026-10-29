<?php

declare(strict_types=1);

namespace Administrator\EmployeeHris\Domain\Event;

use DateTimeImmutable;

interface DomainEventInterface
{
    public function eventName(): string;
    public function occurredOn(): DateTimeImmutable;
    public function toPayload(): array;
}
