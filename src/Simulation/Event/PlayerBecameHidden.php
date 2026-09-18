<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

/** Runtime-derived actor visibility transition; player-list membership is unchanged. */
final readonly class PlayerBecameHidden implements WorldEvent
{
    public function __construct(
        public string $playerSessionId,
        public int $runtimeActorId,
        public string $recipientSessionId,
    ) {}

    public function recipients(): array
    {
        return [$this->recipientSessionId];
    }
}
