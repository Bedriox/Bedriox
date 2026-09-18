<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

final readonly class PlayerDisconnected implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $sessionId,
        public string $identity,
        public int $runtimeActorId,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
