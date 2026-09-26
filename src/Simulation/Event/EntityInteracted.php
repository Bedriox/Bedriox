<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Entity\EntityInteractionType;

/** Internal acknowledgement for an authoritative entity interaction with no direct packet projection. */
final readonly class EntityInteracted implements WorldEvent
{
    public function __construct(
        public string $ownerSessionId,
        public int $targetRuntimeActorId,
        public EntityInteractionType $interaction,
    ) {}

    public function recipients(): array
    {
        return [];
    }
}
