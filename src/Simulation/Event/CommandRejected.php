<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

final readonly class CommandRejected implements WorldEvent
{
    public function __construct(
        public string $sessionId,
        public string $reason,
    ) {}

    public function recipients(): array
    {
        return [$this->sessionId];
    }
}
