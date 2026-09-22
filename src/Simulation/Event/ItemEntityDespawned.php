<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

final readonly class ItemEntityDespawned implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(public int $runtimeActorId, public array $recipientSessionIds) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
